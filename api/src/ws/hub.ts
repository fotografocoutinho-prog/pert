import crypto from 'node:crypto';
import type { WebSocket } from 'ws';
import type { MonitorCommand, ServerToPlayer } from '@signage/shared';
import { bus } from './bus.js';
import { logger } from '../utils/logger.js';

/**
 * Tracks the live socket for each connected monitor on this node. When a target
 * monitor's socket lives on another node, delivery is routed through the message
 * bus (Redis) so it reaches the node that holds it.
 */
class Hub {
  private readonly sockets = new Map<string, WebSocket>();

  constructor() {
    // Deliver messages that arrive from other nodes to local sockets.
    bus.onMessage(({ monitorId, message }) => this.deliverLocal(monitorId, message));
  }

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

  private deliverLocal(monitorId: string, message: ServerToPlayer): boolean {
    const socket = this.sockets.get(monitorId);
    if (!socket || socket.readyState !== socket.OPEN) return false;
    socket.send(JSON.stringify(message));
    return true;
  }

  /**
   * Sends a message to a monitor. Delivered locally if the socket is on this
   * node; otherwise published to the bus for another node to deliver. Returns
   * whether delivery was possible (locally, or optimistically when distributed).
   */
  send(monitorId: string, message: ServerToPlayer): boolean {
    if (this.deliverLocal(monitorId, message)) return true;
    if (bus.distributed) {
      bus.publish({ monitorId, message });
      return true;
    }
    return false;
  }

  sendCommand(
    monitorId: string,
    command: MonitorCommand,
    payload?: unknown,
  ): { commandId: string; delivered: boolean } {
    const commandId = crypto.randomUUID();
    const delivered = this.send(monitorId, { type: 'command', command, commandId, payload });
    return { commandId, delivered };
  }

  broadcastSync(monitorIds: string[], reason: 'content' | 'playlist' | 'layout'): void {
    for (const id of monitorIds) this.send(id, { type: 'sync', reason });
  }
}

export const hub = new Hub();
