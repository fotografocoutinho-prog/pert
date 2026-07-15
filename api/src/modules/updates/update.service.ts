import type { PlayerRelease, UpdateManifest } from '@signage/shared';
import { query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';

interface ReleaseRow {
  id: string;
  version: string;
  url: string;
  checksum: string;
  notes: string | null;
  created_at: Date;
}

function toRelease(row: ReleaseRow): PlayerRelease {
  return {
    id: row.id,
    version: row.version,
    url: row.url,
    checksum: row.checksum,
    notes: row.notes,
    createdAt: row.created_at.toISOString(),
  };
}

export async function listReleases(): Promise<PlayerRelease[]> {
  const { rows } = await query<ReleaseRow>(
    'SELECT * FROM player_releases ORDER BY created_at DESC',
  );
  return rows.map(toRelease);
}

export async function createRelease(input: {
  version: string;
  url: string;
  checksum: string;
  notes?: string | null;
}): Promise<PlayerRelease> {
  const { rows } = await query<ReleaseRow>(
    `INSERT INTO player_releases (version, url, checksum, notes)
     VALUES ($1, $2, $3, $4) RETURNING *`,
    [input.version, input.url, input.checksum, input.notes ?? null],
  );
  return toRelease(rows[0]);
}

export async function latestManifest(): Promise<UpdateManifest | null> {
  const { rows } = await query<ReleaseRow>(
    'SELECT * FROM player_releases ORDER BY created_at DESC LIMIT 1',
  );
  if (rows.length === 0) return null;
  const r = rows[0];
  return { version: r.version, url: r.url, checksum: r.checksum, notes: r.notes };
}

export async function deleteRelease(id: string): Promise<void> {
  const { rowCount } = await query('DELETE FROM player_releases WHERE id = $1', [id]);
  if (!rowCount) throw new HttpError(404, 'not_found', 'Release not found');
}
