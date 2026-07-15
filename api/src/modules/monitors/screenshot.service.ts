import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { query } from '../../db/pool.js';
import { storage } from '../contents/storage.js';
import { HttpError } from '../../middleware/error.js';

/** Persists a base64 data-URL screenshot received from a player. */
export async function saveScreenshot(monitorId: string, dataUrl: string): Promise<void> {
  const match = dataUrl.match(/^data:(image\/\w+);base64,(.+)$/);
  if (!match) throw new Error('invalid screenshot data URL');
  const [, mimeType, base64] = match;
  const buffer = Buffer.from(base64, 'base64');

  const key = `screenshots/${monitorId}.img`;
  const dest = storage.absolutePath(key);
  await mkdir(dirname(dest), { recursive: true });
  await writeFile(dest, buffer);

  await query(
    `INSERT INTO screenshots (monitor_id, storage_key, mime_type, created_at)
     VALUES ($1, $2, $3, now())
     ON CONFLICT (monitor_id)
     DO UPDATE SET storage_key = EXCLUDED.storage_key, mime_type = EXCLUDED.mime_type, created_at = now()`,
    [monitorId, key, mimeType],
  );
}

export async function getLatestScreenshot(
  monitorId: string,
): Promise<{ path: string; mimeType: string; createdAt: string }> {
  const { rows } = await query<{ storage_key: string; mime_type: string; created_at: Date }>(
    'SELECT storage_key, mime_type, created_at FROM screenshots WHERE monitor_id = $1',
    [monitorId],
  );
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'No screenshot captured yet');
  return {
    path: storage.absolutePath(rows[0].storage_key),
    mimeType: rows[0].mime_type,
    createdAt: rows[0].created_at.toISOString(),
  };
}
