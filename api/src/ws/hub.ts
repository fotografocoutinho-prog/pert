import crypto from 'node:crypto';
import type { WebSocket } from 'ws';
import type { MonitorCommand, ServerToPlayer } from '@signage/shared';
import { logger } from '../utils/logger.js';

/** Tracks the live socket for each connected monitor. */
class Hub {
  private readonly sockets = new Map<string, WebSocket>();

  register(monitorId: string, socket: WebSocket): void {
    this.sockets.get(monitorId)?.close(4000, 'replaced by newer connection');
    this.sockets.set(monitorId, socket);
    logger.info('Monitor connected', { monitorId, online: this.sockets.size });
  }

  unregister(monitorId: string, socket: WebSocket): void {
    if (this.sockets.get(monitorId) === socket) {
      this.sockets.delete(monitorId);
      logger.info('Monitor disconnected', { monitorId, online: this.sockets.size });
    }
  }

  isOnline(monitorId: string): boolean {
    return this.sockets.has(monitorId);
  }

  onlineIds(): string[] {
    return [...this.sockets.keys()];
  }

  send(monitorId: string, message: ServerToPlayer): boolean {
    const socket = this.sockets.get(monitorId);
    if (!socket || socket.readyState !== socket.OPEN) return false;
    socket.send(JSON.stringify(message));
    return true;
  }

  /** Sends a command and returns its generated command id. */
  sendCommand(monitorId: string, command: MonitorCommand, payload?: unknown): { commandId: string; delivered: boolean } {
    const commandId = crypto.randomUUID();
    const delivered = this.send(monitorId, { type: 'command', command, commandId, payload });
    return { commandId, delivered };
  }

  broadcastSync(monitorIds: string[], reason: 'content' | 'playlist' | 'layout'): void {
    for (const id of monitorIds) this.send(id, { type: 'sync', reason });
  }
}

export const hub = new Hub();
