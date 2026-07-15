import { contextBridge, ipcRenderer } from 'electron';
import type { PlayerConfig } from './config.js';
import type { CacheItem } from './cache.js';

/** Safe, minimal bridge exposed to the renderer as window.signage. */
contextBridge.exposeInMainWorld('signage', {
  onConfig(callback: (config: PlayerConfig) => void): void {
    ipcRenderer.on('config', (_event, config: PlayerConfig) => callback(config));
  },
  restart(): void {
    ipcRenderer.send('player:restart');
  },
  reload(): void {
    ipcRenderer.send('player:reload');
  },
  getTelemetry(): Promise<Record<string, unknown>> {
    return ipcRenderer.invoke('player:telemetry');
  },
  captureScreenshot(): Promise<string | null> {
    return ipcRenderer.invoke('player:screenshot');
  },
  cacheContents(items: CacheItem[]): Promise<Record<string, string>> {
    return ipcRenderer.invoke('player:cache', items);
  },
  checkUpdate(): Promise<{ version: string } | null> {
    return ipcRenderer.invoke('player:check-update');
  },
});
