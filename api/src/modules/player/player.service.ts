import type {
  PlayerState,
  ResolvedPlaylist,
  ResolvedZone,
} from '@signage/shared';
import { query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';
import { listSchedules, selectActiveSchedule } from '../schedules/schedule.service.js';

interface MonitorRow {
  id: string;
  orientation: string;
  resolution: string | null;
  group_name: string | null;
  layout_id: string | null;
  playlist_id: string | null;
}

interface ZoneJson {
  id: string;
  kind: ResolvedZone['kind'];
  x: number;
  y: number;
  width: number;
  height: number;
  config: Record<string, unknown>;
  playlistId: string | null;
}

interface ResolvedItemRow {
  content_id: string;
  kind: ResolvedPlaylist['items'][number]['kind'];
  mime_type: string;
  duration_seconds: string;
  scale_mode: string;
  transition: string;
  loop: boolean;
  shuffle: boolean;
}

/** Loads a playlist and joins each item with its content metadata. */
async function resolvePlaylist(playlistId: string): Promise<ResolvedPlaylist | null> {
  const { rows } = await query<ResolvedItemRow>(
    `SELECT pi.content_id, c.kind, c.mime_type, pi.duration_seconds,
            pi.scale_mode, pi.transition, p.loop, p.shuffle
       FROM playlists p
       JOIN playlist_items pi ON pi.playlist_id = p.id
       JOIN contents c ON c.id = pi.content_id
      WHERE p.id = $1
      ORDER BY pi.position ASC`,
    [playlistId],
  );
  if (rows.length === 0) {
    // Playlist may exist but be empty; return an empty resolved playlist.
    const meta = await query<{ loop: boolean; shuffle: boolean }>(
      'SELECT loop, shuffle FROM playlists WHERE id = $1',
      [playlistId],
    );
    if (meta.rows.length === 0) return null;
    return { id: playlistId, loop: meta.rows[0].loop, shuffle: meta.rows[0].shuffle, items: [] };
  }
  return {
    id: playlistId,
    loop: rows[0].loop,
    shuffle: rows[0].shuffle,
    items: rows.map((r) => ({
      contentId: r.content_id,
      kind: r.kind,
      mimeType: r.mime_type,
      durationSeconds: Number(r.duration_seconds),
      scaleMode: (r.scale_mode as ResolvedPlaylist['items'][number]['scaleMode']) ?? 'fit',
      transition: (r.transition as ResolvedPlaylist['items'][number]['transition']) ?? 'fade',
    })),
  };
}

export async function resolvePlayerState(monitorId: string, now = new Date()): Promise<PlayerState> {
  const { rows } = await query<MonitorRow>(
    'SELECT id, orientation, resolution, group_name, layout_id, playlist_id FROM monitors WHERE id = $1',
    [monitorId],
  );
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Monitor not found');
  const monitor = rows[0];

  // Scheduling: an active schedule overrides the monitor's default playlist.
  const schedules = await listSchedules();
  const active = selectActiveSchedule(schedules, monitor.id, monitor.group_name, now);
  const fallbackPlaylistId = active?.playlistId ?? monitor.playlist_id;

  const fallbackPlaylist = fallbackPlaylistId ? await resolvePlaylist(fallbackPlaylistId) : null;

  let layout: PlayerState['layout'] = null;
  if (monitor.layout_id) {
    const layoutRows = await query<{ zones: ZoneJson[] }>(
      'SELECT zones FROM layouts WHERE id = $1',
      [monitor.layout_id],
    );
    if (layoutRows.rows.length > 0) {
      const zones = layoutRows.rows[0].zones ?? [];
      const resolvedZones: ResolvedZone[] = [];
      for (const z of zones) {
        const playlist = z.playlistId ? await resolvePlaylist(z.playlistId) : null;
        resolvedZones.push({
          id: z.id,
          kind: z.kind,
          x: z.x,
          y: z.y,
          width: z.width,
          height: z.height,
          config: z.config ?? {},
          playlist,
        });
      }
      layout = { id: monitor.layout_id, zones: resolvedZones };
    }
  }

  return {
    monitorId: monitor.id,
    orientation: monitor.orientation === 'portrait' ? 'portrait' : 'landscape',
    resolution: monitor.resolution,
    layout,
    fallbackPlaylist,
    activeScheduleId: active?.id ?? null,
    generatedAt: now.toISOString(),
  };
}
