import type { PlayStats } from '@signage/shared';
import { query } from '../../db/pool.js';

/** Records a proof-of-play event (tenant scoped via RLS + column default). */
export async function recordPlayEvent(
  monitorId: string,
  contentId: string,
  durationSeconds: number,
): Promise<void> {
  await query(
    `INSERT INTO play_events (monitor_id, content_id, duration_seconds)
     VALUES ($1, $2, $3)`,
    [monitorId, contentId, durationSeconds],
  );
}

export async function getPlayStats(from: Date, to: Date): Promise<PlayStats> {
  const { rows } = await query<{
    content_id: string | null;
    content_name: string | null;
    plays: string;
    total_seconds: string | null;
  }>(
    `SELECT pe.content_id,
            c.name AS content_name,
            count(*)::text AS plays,
            coalesce(sum(pe.duration_seconds), 0)::text AS total_seconds
       FROM play_events pe
       LEFT JOIN contents c ON c.id = pe.content_id
      WHERE pe.played_at >= $1 AND pe.played_at <= $2
      GROUP BY pe.content_id, c.name
      ORDER BY plays DESC`,
    [from.toISOString(), to.toISOString()],
  );

  const byContent = rows.map((r) => ({
    contentId: r.content_id ?? 'deleted',
    contentName: r.content_name ?? '(deleted content)',
    plays: Number(r.plays),
    totalSeconds: Number(r.total_seconds ?? 0),
  }));

  return {
    from: from.toISOString(),
    to: to.toISOString(),
    totalPlays: byContent.reduce((sum, c) => sum + c.plays, 0),
    byContent,
  };
}
