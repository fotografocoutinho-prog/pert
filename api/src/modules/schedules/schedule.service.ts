import type { Schedule, Weekday } from '@signage/shared';
import { query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';

interface ScheduleRow {
  id: string;
  name: string;
  monitor_id: string | null;
  group_name: string | null;
  playlist_id: string;
  priority: number;
  start_date: Date | null;
  end_date: Date | null;
  start_time: string | null;
  end_time: string | null;
  weekdays: number[];
  active: boolean;
  created_at: Date;
  updated_at: Date;
}

export interface ScheduleInput {
  name: string;
  monitorId?: string | null;
  groupName?: string | null;
  playlistId: string;
  priority?: number;
  startDate?: string | null;
  endDate?: string | null;
  startTime?: string | null;
  endTime?: string | null;
  weekdays?: Weekday[];
  active?: boolean;
}

function toSchedule(row: ScheduleRow): Schedule {
  return {
    id: row.id,
    name: row.name,
    monitorId: row.monitor_id,
    groupName: row.group_name,
    playlistId: row.playlist_id,
    priority: row.priority,
    startDate: row.start_date?.toISOString() ?? null,
    endDate: row.end_date?.toISOString() ?? null,
    startTime: row.start_time,
    endTime: row.end_time,
    weekdays: (row.weekdays ?? []) as Weekday[],
    active: row.active,
    createdAt: row.created_at.toISOString(),
    updatedAt: row.updated_at.toISOString(),
  };
}

export async function listSchedules(): Promise<Schedule[]> {
  const { rows } = await query<ScheduleRow>(
    'SELECT * FROM schedules ORDER BY priority DESC, name ASC',
  );
  return rows.map(toSchedule);
}

export async function getSchedule(id: string): Promise<Schedule> {
  const { rows } = await query<ScheduleRow>('SELECT * FROM schedules WHERE id = $1', [id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Schedule not found');
  return toSchedule(rows[0]);
}

export async function createSchedule(input: ScheduleInput): Promise<Schedule> {
  const { rows } = await query<ScheduleRow>(
    `INSERT INTO schedules
       (name, monitor_id, group_name, playlist_id, priority,
        start_date, end_date, start_time, end_time, weekdays, active)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11) RETURNING *`,
    [
      input.name,
      input.monitorId ?? null,
      input.groupName ?? null,
      input.playlistId,
      input.priority ?? 0,
      input.startDate ?? null,
      input.endDate ?? null,
      input.startTime ?? null,
      input.endTime ?? null,
      input.weekdays ?? [],
      input.active ?? true,
    ],
  );
  return toSchedule(rows[0]);
}

const COLUMNS: Record<string, string> = {
  name: 'name',
  monitorId: 'monitor_id',
  groupName: 'group_name',
  playlistId: 'playlist_id',
  priority: 'priority',
  startDate: 'start_date',
  endDate: 'end_date',
  startTime: 'start_time',
  endTime: 'end_time',
  weekdays: 'weekdays',
  active: 'active',
};

export async function updateSchedule(id: string, input: Partial<ScheduleInput>): Promise<Schedule> {
  const sets: string[] = [];
  const values: unknown[] = [];
  let i = 1;
  for (const [key, column] of Object.entries(COLUMNS)) {
    const value = (input as Record<string, unknown>)[key];
    if (value !== undefined) {
      sets.push(`${column} = $${i++}`);
      values.push(value);
    }
  }
  if (sets.length === 0) return getSchedule(id);
  sets.push('updated_at = now()');
  values.push(id);
  const { rows } = await query<ScheduleRow>(
    `UPDATE schedules SET ${sets.join(', ')} WHERE id = $${i} RETURNING *`,
    values,
  );
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Schedule not found');
  return toSchedule(rows[0]);
}

export async function deleteSchedule(id: string): Promise<void> {
  const { rowCount } = await query('DELETE FROM schedules WHERE id = $1', [id]);
  if (!rowCount) throw new HttpError(404, 'not_found', 'Schedule not found');
}

/**
 * Resolves the active schedule for a monitor at a given time. The highest
 * priority schedule whose date range, time window and weekday all match wins.
 */
export function selectActiveSchedule(
  schedules: Schedule[],
  monitorId: string,
  groupName: string | null,
  now: Date,
): Schedule | null {
  const candidates = schedules
    .filter((s) => s.active)
    .filter((s) => s.monitorId === monitorId || (s.groupName !== null && s.groupName === groupName))
    .filter((s) => matchesNow(s, now))
    .sort((a, b) => b.priority - a.priority);
  return candidates[0] ?? null;
}

function matchesNow(s: Schedule, now: Date): boolean {
  if (s.startDate && now < new Date(s.startDate)) return false;
  if (s.endDate && now > new Date(s.endDate)) return false;

  if (s.weekdays.length > 0 && !s.weekdays.includes(now.getDay() as Weekday)) return false;

  if (s.startTime || s.endTime) {
    const minutes = now.getHours() * 60 + now.getMinutes();
    if (s.startTime && minutes < toMinutes(s.startTime)) return false;
    if (s.endTime && minutes > toMinutes(s.endTime)) return false;
  }
  return true;
}

function toMinutes(hhmm: string): number {
  const [h, m] = hhmm.split(':').map(Number);
  return (h || 0) * 60 + (m || 0);
}
