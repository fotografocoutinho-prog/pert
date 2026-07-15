import type { LogEntry, LogLevel } from '@signage/shared';
import { query } from '../../db/pool.js';
import { logger } from '../../utils/logger.js';

interface LogRow {
  id: string;
  user_id: string | null;
  monitor_id: string | null;
  action: string;
  level: string;
  detail: Record<string, unknown> | null;
  created_at: Date;
}

function toEntry(row: LogRow): LogEntry {
  return {
    id: Number(row.id),
    userId: row.user_id,
    monitorId: row.monitor_id,
    action: row.action,
    level: (row.level as LogLevel) ?? 'info',
    detail: row.detail,
    createdAt: row.created_at.toISOString(),
  };
}

export interface WriteLogInput {
  userId?: string | null;
  monitorId?: string | null;
  action: string;
  level?: LogLevel;
  detail?: Record<string, unknown>;
}

/** Appends an audit entry. Never throws — logging must not break requests. */
export async function writeLog(input: WriteLogInput): Promise<void> {
  try {
    await query(
      `INSERT INTO logs (user_id, monitor_id, action, level, detail)
       VALUES ($1, $2, $3, $4, $5)`,
      [
        input.userId ?? null,
        input.monitorId ?? null,
        input.action,
        input.level ?? 'info',
        input.detail ? JSON.stringify(input.detail) : null,
      ],
    );
  } catch (err) {
    logger.warn('Failed to write audit log', { error: String(err) });
  }
}

export interface ListLogsFilter {
  monitorId?: string;
  level?: LogLevel;
  limit: number;
  offset: number;
}

export async function listLogs(filter: ListLogsFilter): Promise<{ items: LogEntry[]; total: number }> {
  const where: string[] = [];
  const values: unknown[] = [];
  let i = 1;
  if (filter.monitorId) {
    where.push(`monitor_id = $${i++}`);
    values.push(filter.monitorId);
  }
  if (filter.level) {
    where.push(`level = $${i++}`);
    values.push(filter.level);
  }
  const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';

  const totalRes = await query<{ count: string }>(
    `SELECT count(*)::text AS count FROM logs ${whereSql}`,
    values,
  );
  const rows = await query<LogRow>(
    `SELECT * FROM logs ${whereSql} ORDER BY created_at DESC LIMIT $${i++} OFFSET $${i}`,
    [...values, filter.limit, filter.offset],
  );
  return { items: rows.rows.map(toEntry), total: Number(totalRes.rows[0].count) };
}
