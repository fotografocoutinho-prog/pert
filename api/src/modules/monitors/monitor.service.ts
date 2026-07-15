import crypto from 'node:crypto';
import type {
  CreateMonitorInput,
  Monitor,
  MonitorTelemetry,
  UpdateMonitorInput,
} from '@signage/shared';
import { query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';

interface MonitorRow {
  id: string;
  name: string;
  group_name: string | null;
  location: string | null;
  resolution: string | null;
  orientation: string;
  status: 'online' | 'offline';
  last_seen_at: Date | null;
  player_version: string | null;
  ip_address: string | null;
  os: string | null;
  uptime_seconds: string | null;
  layout_id: string | null;
  playlist_id: string | null;
  pairing_code: string | null;
  created_at: Date;
  updated_at: Date;
}

function toMonitor(row: MonitorRow): Monitor {
  return {
    id: row.id,
    name: row.name,
    groupName: row.group_name,
    location: row.location,
    resolution: row.resolution,
    orientation: row.orientation === 'portrait' ? 'portrait' : 'landscape',
    status: row.status,
    lastSeenAt: row.last_seen_at?.toISOString() ?? null,
    playerVersion: row.player_version,
    ipAddress: row.ip_address,
    os: row.os,
    uptimeSeconds: row.uptime_seconds === null ? null : Number(row.uptime_seconds),
    layoutId: row.layout_id,
    playlistId: row.playlist_id,
    pairingCode: row.pairing_code,
    createdAt: row.created_at.toISOString(),
    updatedAt: row.updated_at.toISOString(),
  };
}

export async function listMonitors(): Promise<Monitor[]> {
  const { rows } = await query<MonitorRow>('SELECT * FROM monitors ORDER BY name ASC');
  return rows.map(toMonitor);
}

export async function getMonitor(id: string): Promise<Monitor> {
  const { rows } = await query<MonitorRow>('SELECT * FROM monitors WHERE id = $1', [id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Monitor not found');
  return toMonitor(rows[0]);
}

export async function createMonitor(input: CreateMonitorInput): Promise<Monitor> {
  const pairingCode = crypto.randomInt(100_000, 999_999).toString();
  const { rows } = await query<MonitorRow>(
    `INSERT INTO monitors (name, group_name, location, resolution, orientation, pairing_code)
     VALUES ($1, $2, $3, $4, $5, $6) RETURNING *`,
    [
      input.name,
      input.groupName ?? null,
      input.location ?? null,
      input.resolution ?? null,
      input.orientation ?? 'landscape',
      pairingCode,
    ],
  );
  return toMonitor(rows[0]);
}

const UPDATABLE: Record<keyof UpdateMonitorInput, string> = {
  name: 'name',
  groupName: 'group_name',
  location: 'location',
  resolution: 'resolution',
  orientation: 'orientation',
  layoutId: 'layout_id',
  playlistId: 'playlist_id',
};

export async function updateMonitor(id: string, input: UpdateMonitorInput): Promise<Monitor> {
  const sets: string[] = [];
  const values: unknown[] = [];
  let i = 1;
  for (const [key, column] of Object.entries(UPDATABLE)) {
    const value = (input as Record<string, unknown>)[key];
    if (value !== undefined) {
      sets.push(`${column} = $${i++}`);
      values.push(value);
    }
  }
  if (sets.length === 0) return getMonitor(id);
  sets.push('updated_at = now()');
  values.push(id);
  const { rows } = await query<MonitorRow>(
    `UPDATE monitors SET ${sets.join(', ')} WHERE id = $${i} RETURNING *`,
    values,
  );
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Monitor not found');
  return toMonitor(rows[0]);
}

export async function deleteMonitor(id: string): Promise<void> {
  const { rowCount } = await query('DELETE FROM monitors WHERE id = $1', [id]);
  if (!rowCount) throw new HttpError(404, 'not_found', 'Monitor not found');
}

export async function markOnline(id: string, meta: { os?: string; playerVersion?: string; ip?: string }): Promise<void> {
  await query(
    `UPDATE monitors
     SET status = 'online', last_seen_at = now(),
         os = COALESCE($2, os), player_version = COALESCE($3, player_version),
         ip_address = COALESCE($4, ip_address), updated_at = now()
     WHERE id = $1`,
    [id, meta.os ?? null, meta.playerVersion ?? null, meta.ip ?? null],
  );
}

export async function markOffline(id: string): Promise<void> {
  await query(`UPDATE monitors SET status = 'offline', updated_at = now() WHERE id = $1`, [id]);
}

export async function recordTelemetry(id: string, t: MonitorTelemetry): Promise<void> {
  await query(
    `UPDATE monitors SET last_seen_at = now(), status = 'online',
        uptime_seconds = COALESCE($2, uptime_seconds), updated_at = now()
     WHERE id = $1`,
    [id, t.uptimeSeconds ?? null],
  );
  await query(
    `INSERT INTO telemetry (monitor_id, cpu_percent, ram_percent, temperature_c, free_disk_bytes)
     VALUES ($1, $2, $3, $4, $5)`,
    [id, t.cpuPercent ?? null, t.ramPercent ?? null, t.temperatureC ?? null, t.freeDiskBytes ?? null],
  );
}
