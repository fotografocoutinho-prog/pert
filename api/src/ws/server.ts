import type { Server } from 'node:http';
import { WebSocketServer, type WebSocket } from 'ws';
import {
  DEFAULT_HEARTBEAT_MS,
  WS_PATH,
  type PlayerToServer,
} from '@signage/shared';
import { verifyAccessToken } from '../utils/jwt.js';
import { hub } from './hub.js';
import { markOffline, markOnline, recordTelemetry } from '../modules/monitors/monitor.service.js';
import { saveScreenshot } from '../modules/monitors/screenshot.service.js';
import { writeLog } from '../modules/audit/audit.service.js';
import { logger } from '../utils/logger.js';

interface SocketState {
  monitorId?: string;
  alive: boolean;
}

/**
 * Players connect to /ws?token=<jwt>&monitorId=<uuid>. The JWT is a normal
 * access token; a monitor is paired to a device account (or a service token).
 */
export function attachWebSocketServer(server: Server): WebSocketServer {
  const wss = new WebSocketServer({ server, path: WS_PATH });

  wss.on('connection', (socket: WebSocket, req) => {
    const url = new URL(req.url ?? '', 'http://localhost');
    const token = url.searchParams.get('token');
    const monitorId = url.searchParams.get('monitorId');

    if (!token || !monitorId) {
      socket.close(4001, 'missing token or monitorId');
      return;
    }
    try {
      verifyAccessToken(token);
    } catch {
      socket.close(4003, 'invalid token');
      return;
    }

    const state: SocketState = { monitorId, alive: true };
    hub.register(monitorId, socket);
    const ip = (req.socket.remoteAddress ?? '').replace('::ffff:', '');
    void markOnline(monitorId, { ip });

    socket.send(
      JSON.stringify({
        type: 'welcome',
        serverTime: new Date().toISOString(),
        heartbeatIntervalMs: DEFAULT_HEARTBEAT_MS,
      }),
    );

    socket.on('pong', () => {
      state.alive = true;
    });

    socket.on('message', (raw) => {
      let msg: PlayerToServer;
      try {
        msg = JSON.parse(raw.toString()) as PlayerToServer;
      } catch {
        return;
      }
      handleMessage(state, msg, ip).catch((err) =>
        logger.error('WS message handler failed', { error: String(err) }),
      );
    });

    socket.on('close', () => {
      hub.unregister(monitorId, socket);
      void markOffline(monitorId);
    });

    socket.on('error', (err) => logger.warn('WS socket error', { error: String(err) }));
  });

  // Liveness ping/pong sweep.
  const interval = setInterval(() => {
    for (const socket of wss.clients) {
      const s = socket as WebSocket & { _state?: SocketState };
      if (s.readyState !== s.OPEN) continue;
      socket.ping();
    }
  }, DEFAULT_HEARTBEAT_MS);

  wss.on('close', () => clearInterval(interval));
  logger.info('WebSocket server attached', { path: WS_PATH });
  return wss;
}

async function handleMessage(state: SocketState, msg: PlayerToServer, ip: string): Promise<void> {
  if (!state.monitorId) return;
  switch (msg.type) {
    case 'hello':
      await markOnline(state.monitorId, { os: msg.os, playerVersion: msg.playerVersion, ip });
      break;
    case 'heartbeat':
      await recordTelemetry(state.monitorId, msg.telemetry);
      break;
    case 'ack':
      logger.info('Command ack', { monitorId: state.monitorId, command: msg.command, ok: msg.ok });
      break;
    case 'screenshot':
      try {
        await saveScreenshot(state.monitorId, msg.dataUrl);
        logger.info('Screenshot stored', { monitorId: state.monitorId, commandId: msg.commandId });
      } catch (err) {
        logger.warn('Failed to store screenshot', { error: String(err) });
      }
      break;
    case 'log':
      await writeLog({
        monitorId: state.monitorId,
        action: 'player_log',
        level: msg.level,
        detail: { message: msg.message },
      });
      break;
  }
}
