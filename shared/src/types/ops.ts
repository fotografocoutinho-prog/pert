import type { ISODateString, UUID } from './common.js';

/** A historical device-health sample. */
export interface TelemetrySample {
  cpuPercent: number | null;
  ramPercent: number | null;
  temperatureC: number | null;
  freeDiskBytes: number | null;
  createdAt: ISODateString;
}

export type LogLevel = 'info' | 'warn' | 'error';

/** An audit / activity log entry. */
export interface LogEntry {
  id: number;
  userId: UUID | null;
  monitorId: UUID | null;
  action: string;
  level: LogLevel;
  detail: Record<string, unknown> | null;
  createdAt: ISODateString;
}

/** A published player release, used for OTA updates. */
export interface PlayerRelease {
  id: UUID;
  version: string;
  url: string;
  checksum: string;
  notes: string | null;
  createdAt: ISODateString;
}

/** OTA manifest returned to players checking for updates. */
export interface UpdateManifest {
  version: string;
  url: string;
  checksum: string;
  notes: string | null;
}
