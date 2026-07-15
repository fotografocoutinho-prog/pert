import { join } from 'node:path';
import { app, BrowserWindow, ipcMain } from 'electron';
import { loadConfig } from './config.js';

// Enable hardware video decoding / GL where available (Raspberry Pi).
app.commandLine.appendSwitch('enable-features', 'VaapiVideoDecoder');
app.commandLine.appendSwitch('ignore-gpu-blocklist');

let mainWindow: BrowserWindow | null = null;

function createWindow(): void {
  const config = loadConfig();

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
