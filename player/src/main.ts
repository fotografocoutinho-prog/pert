import { join } from 'node:path';
import { app, BrowserWindow, ipcMain } from 'electron';
import { loadConfig, type PlayerConfig } from './config.js';
import { collectTelemetry } from './telemetry.js';
import { ContentCache, type CacheItem } from './cache.js';
import { Updater, isNewerVersion } from './updater.js';

const PLAYER_VERSION = '0.3.0';

// Enable hardware video decoding / GL where available (Raspberry Pi).
app.commandLine.appendSwitch('enable-features', 'VaapiVideoDecoder');
app.commandLine.appendSwitch('ignore-gpu-blocklist');

let mainWindow: BrowserWindow | null = null;
let activeConfig: PlayerConfig | null = null;

function createWindow(): void {
  const config = loadConfig();
  activeConfig = config;

  mainWindow = new BrowserWindow({
    fullscreen: true,
    kiosk: true,
    frame: false,
    backgroundColor: '#000000',
    autoHideMenuBar: true,
    webPreferences: {
      preload: join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      backgroundThrottling: false,
    },
  });

  mainWindow.loadFile(join(__dirname, 'renderer', 'index.html'));

  // Publish config to the renderer once it is ready.
  mainWindow.webContents.on('did-finish-load', () => {
    mainWindow?.webContents.send('config', config);
  });

  // Watchdog: if the renderer process crashes, reload it.
  mainWindow.webContents.on('render-process-gone', () => {
    setTimeout(() => mainWindow?.reload(), 2_000);
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

// Single-instance lock so the kiosk never runs twice.
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.focus();
    }
  });

  app.whenReady().then(createWindow);

  app.on('window-all-closed', () => {
    // Kiosk devices should never sit at a blank desktop — restart the window.
    if (process.platform !== 'darwin') createWindow();
  });
}

// Renderer-driven power actions (triggered by remote commands).
ipcMain.on('player:restart', () => {
  app.relaunch();
  app.exit(0);
});

ipcMain.on('player:reload', () => {
  mainWindow?.reload();
});

// Device health, gathered in the main (Node) process.
ipcMain.handle('player:telemetry', async () => {
  const diskPath = app.getPath('userData');
  const t = await collectTelemetry(diskPath);
  return { ...t, playerVersion: PLAYER_VERSION };
});

// Remote screenshot: capture the current page as a data URL.
ipcMain.handle('player:screenshot', async () => {
  if (!mainWindow) return null;
  const image = await mainWindow.webContents.capturePage();
  return image.toDataURL();
});

// Offline content cache — download changed assets, return contentId -> file URL.
ipcMain.handle('player:cache', async (_event, items: CacheItem[]) => {
  if (!activeConfig) return {};
  const cache = new ContentCache(
    join(app.getPath('userData'), 'content-cache'),
    activeConfig.apiUrl,
    activeConfig.token,
  );
  return cache.sync(items);
});

// OTA: check for a newer player release and stage it.
ipcMain.handle('player:check-update', async () => {
  if (!activeConfig) return null;
  const updater = new Updater(
    join(app.getPath('userData'), 'updates'),
    activeConfig.apiUrl,
    activeConfig.token,
  );
  const manifest = await updater.checkManifest();
  if (!manifest || !isNewerVersion(manifest.version, PLAYER_VERSION)) return null;
  await updater.stage(manifest);
  await updater.promote(manifest.version, PLAYER_VERSION);
  return manifest;
});
