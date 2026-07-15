import type { Request, Response } from 'express';
import { query } from '../../db/pool.js';
import { hub } from '../../ws/hub.js';

export async function statsHandler(_req: Request, res: Response): Promise<void> {
  const onlineIds = hub.onlineIds();

  const [{ rows: monitorRows }, { rows: storageRows }, { rows: playlistRows }] = await Promise.all([
    query<{ count: string }>('SELECT count(*)::text AS count FROM monitors'),
    query<{ total: string | null }>('SELECT sum(size_bytes)::text AS total FROM contents'),
    query<{ count: string }>("SELECT count(*)::text AS count FROM playlists WHERE active = true"),
  ]);

  const totalMonitors = Number(monitorRows[0].count);
  const online = onlineIds.length;

  res.json({
    monitors: {
      total: totalMonitors,
      online,
      offline: Math.max(0, totalMonitors - online),
    },
    storageBytes: Number(storageRows[0].total ?? 0),
    activePlaylists: Number(playlistRows[0].count),
    alerts: totalMonitors > 0 && online === 0
      ? [{ level: 'warning', message: 'All monitors are offline' }]
      : [],
  });
}
