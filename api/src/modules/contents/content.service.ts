import { rename } from 'node:fs/promises';
import { mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
import { ACCEPTED_MIME_TYPES, type Content, type ContentKind } from '@signage/shared';
import { query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';
import { storage } from './storage.js';
import { probe } from './probe.js';

interface ContentRow {
  id: string;
  name: string;
  kind: ContentKind;
  mime_type: string;
  size_bytes: string;
  duration_seconds: string | null;
  width: number | null;
  height: number | null;
  storage_key: string;
  thumbnail_key: string | null;
  checksum: string;
  created_at: Date;
  updated_at: Date;
}

function toContent(row: ContentRow): Content {
  return {
    id: row.id,
    name: row.name,
    kind: row.kind,
    mimeType: row.mime_type,
    sizeBytes: Number(row.size_bytes),
    durationSeconds: row.duration_seconds === null ? null : Number(row.duration_seconds),
    width: row.width,
    height: row.height,
    storageKey: row.storage_key,
    thumbnailKey: row.thumbnail_key,
    checksum: row.checksum,
    createdAt: row.created_at.toISOString(),
    updatedAt: row.updated_at.toISOString(),
  };
}

export function kindForMime(mime: string): ContentKind | null {
  return ACCEPTED_MIME_TYPES[mime] ?? null;
}

export async function listContents(): Promise<Content[]> {
  const { rows } = await query<ContentRow>('SELECT * FROM contents ORDER BY created_at DESC');
  return rows.map(toContent);
}

export async function getContent(id: string): Promise<Content> {
  const { rows } = await query<ContentRow>('SELECT * FROM contents WHERE id = $1', [id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Content not found');
  return toContent(rows[0]);
}

interface StoredUpload {
  originalName: string;
  mimeType: string;
  tempPath: string; // absolute path where multer wrote the file
}

/** Moves an uploaded temp file into managed storage and records metadata. */
export async function ingestUpload(upload: StoredUpload): Promise<Content> {
  const kind = kindForMime(upload.mimeType);
  if (!kind) {
    throw new HttpError(415, 'unsupported_media_type', `Unsupported mime type: ${upload.mimeType}`);
  }

  const key = storage.keyFor(upload.originalName);
  const dest = storage.absolutePath(key);
  await mkdir(dirname(dest), { recursive: true });
  await rename(upload.tempPath, dest);

  const [checksum, sizeBytes] = await Promise.all([storage.checksum(key), storage.size(key)]);
  const meta = await probe(key, kind);

  const { rows } = await query<ContentRow>(
    `INSERT INTO contents
       (name, kind, mime_type, size_bytes, storage_key, checksum,
        width, height, duration_seconds, thumbnail_key)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10) RETURNING *`,
    [
      upload.originalName,
      kind,
      upload.mimeType,
      sizeBytes,
      key,
      checksum,
      meta.width,
      meta.height,
      meta.durationSeconds,
      meta.thumbnailKey,
    ],
  );
  return toContent(rows[0]);
}

export async function deleteContent(id: string): Promise<void> {
  const content = await getContent(id);
  await query('DELETE FROM contents WHERE id = $1', [id]);
  await storage.remove(content.storageKey);
  if (content.thumbnailKey) await storage.remove(content.thumbnailKey);
}
