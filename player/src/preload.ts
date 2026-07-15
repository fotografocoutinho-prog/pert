import { contextBridge, ipcRenderer } from 'electron';
import type { PlayerConfig } from './config.js';

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
});
