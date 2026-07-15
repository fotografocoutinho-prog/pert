import type { ISODateString, Orientation, UUID } from './common.js';

export type MonitorStatus = 'online' | 'offline';

export interface Monitor {
  id: UUID;
  name: string;
  groupName: string | null;
  location: string | null;
  resolution: string | null;
  orientation: Orientation;
  status: MonitorStatus;
  lastSeenAt: ISODateString | null;
  playerVersion: string | null;
  ipAddress: string | null;
  os: string | null;
  uptimeSeconds: number | null;
  layoutId: UUID | null;
  playlistId: UUID | null;
  pairingCode: string | null;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface MonitorTelemetry {
  cpuPercent?: number;
  ramPercent?: number;
  temperatureC?: number;
  freeDiskBytes?: number;
  online?: boolean;
  uptimeSeconds?: number;
}

export interface CreateMonitorInput {
  name: string;
  groupName?: string | null;
  location?: string | null;
  resolution?: string | null;
  orientation?: Orientation;
}

export type UpdateMonitorInput = Partial<CreateMonitorInput> & {
  layoutId?: UUID | null;
  playlistId?: UUID | null;
};

export type MonitorCommand =
  | 'restart'
  | 'shutdown'
  | 'clear_cache'
  | 'screenshot'
  | 'update_content'
  | 'update_player';
