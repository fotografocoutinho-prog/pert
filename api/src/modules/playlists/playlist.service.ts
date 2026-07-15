import type {
  CreatePlaylistInput,
  Playlist,
  PlaylistItem,
  PlaylistItemInput,
} from '@signage/shared';
import { query, withTransaction } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';

interface PlaylistRow {
  id: string;
  name: string;
  loop: boolean;
  shuffle: boolean;
  priority: number;
  start_date: Date | null;
  end_date: Date | null;
  active: boolean;
  created_at: Date;
  updated_at: Date;
}

interface ItemRow {
  id: string;
  content_id: string;
  position: number;
  duration_seconds: string;
  scale_mode: string;
  transition: string;
}

function toItem(row: ItemRow): PlaylistItem {
  return {
    id: row.id,
    contentId: row.content_id,
    position: row.position,
    durationSeconds: Number(row.duration_seconds),
    scaleMode: (row.scale_mode as PlaylistItem['scaleMode']) ?? 'fit',
    transition: (row.transition as PlaylistItem['transition']) ?? 'fade',
  };
}

function toPlaylist(row: PlaylistRow, items: PlaylistItem[]): Playlist {
  return {
    id: row.id,
    name: row.name,
    loop: row.loop,
    shuffle: row.shuffle,
    priority: row.priority,
    startDate: row.start_date?.toISOString() ?? null,
    endDate: row.end_date?.toISOString() ?? null,
    active: row.active,
    items,
    createdAt: row.created_at.toISOString(),
    updatedAt: row.updated_at.toISOString(),
  };
}

async function loadItems(playlistId: string): Promise<PlaylistItem[]> {
  const { rows } = await query<ItemRow>(
    'SELECT * FROM playlist_items WHERE playlist_id = $1 ORDER BY position ASC',
    [playlistId],
  );
  return rows.map(toItem);
}

export async function listPlaylists(): Promise<Playlist[]> {
  const { rows } = await query<PlaylistRow>('SELECT * FROM playlists ORDER BY name ASC');
  return Promise.all(rows.map(async (r) => toPlaylist(r, await loadItems(r.id))));
}

export async function getPlaylist(id: string): Promise<Playlist> {
  const { rows } = await query<PlaylistRow>('SELECT * FROM playlists WHERE id = $1', [id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Playlist not found');
  return toPlaylist(rows[0], await loadItems(id));
}

export async function createPlaylist(input: CreatePlaylistInput): Promise<Playlist> {
  const { rows } = await query<PlaylistRow>(
    `INSERT INTO playlists (name, loop, shuffle, priority, start_date, end_date)
     VALUES ($1, $2, $3, $4, $5, $6) RETURNING *`,
    [
      input.name,
      input.loop ?? true,
      input.shuffle ?? false,
      input.priority ?? 0,
      input.startDate ?? null,
      input.endDate ?? null,
    ],
  );
  return toPlaylist(rows[0], []);
}

export async function updatePlaylist(
  id: string,
  input: Partial<CreatePlaylistInput> & { active?: boolean },
): Promise<Playlist> {
  const columns: Record<string, string> = {
    name: 'name',
    loop: 'loop',
    shuffle: 'shuffle',
    priority: 'priority',
    startDate: 'start_date',
    endDate: 'end_date',
    active: 'active',
  };
  const sets: string[] = [];
  const values: unknown[] = [];
  let i = 1;
  for (const [key, column] of Object.entries(columns)) {
    const value = (input as Record<string, unknown>)[key];
    if (value !== undefined) {
      sets.push(`${column} = $${i++}`);
      values.push(value);
    }
  }
  if (sets.length > 0) {
    sets.push('updated_at = now()');
    values.push(id);
    const { rowCount } = await query(
      `UPDATE playlists SET ${sets.join(', ')} WHERE id = $${i}`,
      values,
    );
    if (!rowCount) throw new HttpError(404, 'not_found', 'Playlist not found');
  }
  return getPlaylist(id);
}

/** Replaces the full ordered item list in a single transaction. */
export async function setItems(playlistId: string, items: PlaylistItemInput[]): Promise<Playlist> {
  await getPlaylist(playlistId); // 404 if missing
  await withTransaction(async (client) => {
    await client.query('DELETE FROM playlist_items WHERE playlist_id = $1', [playlistId]);
    for (let position = 0; position < items.length; position++) {
      const item = items[position];
      await client.query(
        `INSERT INTO playlist_items
           (playlist_id, content_id, position, duration_seconds, scale_mode, transition)
         VALUES ($1, $2, $3, $4, $5, $6)`,
        [
          playlistId,
          item.contentId,
          position,
          item.durationSeconds ?? 10,
          item.scaleMode ?? 'fit',
          item.transition ?? 'fade',
        ],
      );
    }
    await client.query('UPDATE playlists SET updated_at = now() WHERE id = $1', [playlistId]);
  });
  return getPlaylist(playlistId);
}

export async function duplicatePlaylist(id: string): Promise<Playlist> {
  const source = await getPlaylist(id);
  const copy = await createPlaylist({
    name: `${source.name} (copy)`,
    loop: source.loop,
    shuffle: source.shuffle,
    priority: source.priority,
    startDate: source.startDate,
    endDate: source.endDate,
  });
  return setItems(
    copy.id,
    source.items.map((it) => ({
      contentId: it.contentId,
      durationSeconds: it.durationSeconds,
      scaleMode: it.scaleMode,
      transition: it.transition,
    })),
  );
}

export async function deletePlaylist(id: string): Promise<void> {
  const { rowCount } = await query('DELETE FROM playlists WHERE id = $1', [id]);
  if (!rowCount) throw new HttpError(404, 'not_found', 'Playlist not found');
}
