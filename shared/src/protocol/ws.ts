import type { MonitorCommand, MonitorTelemetry } from '../types/monitor.js';
import type { UUID } from '../types/common.js';

/** Messages sent from a player to the server. */
export type PlayerToServer =
  | { type: 'hello'; monitorId: UUID; playerVersion: string; os: string }
  | { type: 'heartbeat'; telemetry: MonitorTelemetry }
  | { type: 'ack'; command: MonitorCommand; commandId: UUID; ok: boolean; message?: string }
  | { type: 'screenshot'; commandId: UUID; dataUrl: string }
  | { type: 'play'; contentId: UUID; durationSeconds: number }
  | { type: 'log'; level: 'info' | 'warn' | 'error'; message: string };

/** Messages sent from the server to a player. */
export type ServerToPlayer =
  | { type: 'welcome'; serverTime: string; heartbeatIntervalMs: number }
  | { type: 'command'; command: MonitorCommand; commandId: UUID; payload?: unknown }
  | { type: 'sync'; reason: 'content' | 'playlist' | 'layout' }
  | { type: 'pong' };

export const WS_PATH = '/ws';
export const DEFAULT_HEARTBEAT_MS = 15_000;
