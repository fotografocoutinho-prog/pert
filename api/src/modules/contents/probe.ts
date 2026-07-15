import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import crypto from 'node:crypto';
import sharp from 'sharp';
import type { ContentKind } from '@signage/shared';
import { logger } from '../../utils/logger.js';

const execFileAsync = promisify(execFile);

export interface ProbeResult {
  width: number | null;
  height: number | null;
  durationSeconds: number | null;
  thumbnail: Buffer | null;
}

const THUMB_WIDTH = 400;
const EMPTY: ProbeResult = { width: null, height: null, durationSeconds: null, thumbnail: null };

/** Probes a local file for dimensions/duration and builds a thumbnail buffer. */
export async function probe(localPath: string, kind: ContentKind): Promise<ProbeResult> {
  try {
    if (kind === 'image') return await probeImage(localPath);
    if (kind === 'video') return await probeVideo(localPath);
  } catch (err) {
    logger.warn('Media probe failed; continuing without metadata', { error: String(err) });
  }
  return EMPTY;
}

async function probeImage(localPath: string): Promise<ProbeResult> {
  const meta = await sharp(localPath).metadata();
  const thumbnail = await sharp(localPath)
    .resize({ width: THUMB_WIDTH, withoutEnlargement: true })
    .webp({ quality: 70 })
    .toBuffer();
  return { width: meta.width ?? null, height: meta.height ?? null, durationSeconds: null, thumbnail };
}

async function probeVideo(localPath: string): Promise<ProbeResult> {
  let width: number | null = null;
  let height: number | null = null;
  let durationSeconds: number | null = null;

  if (await hasBinary('ffprobe')) {
    try {
      const { stdout } = await execFileAsync('ffprobe', [
        '-v', 'error',
        '-select_streams', 'v:0',
        '-show_entries', 'stream=width,height:format=duration',
        '-of', 'json',
        localPath,
      ]);
      const parsed = JSON.parse(stdout) as {
        streams?: { width?: number; height?: number }[];
        format?: { duration?: string };
      };
      width = parsed.streams?.[0]?.width ?? null;
      height = parsed.streams?.[0]?.height ?? null;
      const d = Number(parsed.format?.duration);
      durationSeconds = Number.isFinite(d) ? Math.round(d) : null;
    } catch (err) {
      logger.warn('ffprobe failed', { error: String(err) });
    }
  }

  let thumbnail: Buffer | null = null;
  if (await hasBinary('ffmpeg')) {
    const tmp = join(tmpdir(), `thumb-${crypto.randomUUID()}.webp`);
    try {
      await execFileAsync('ffmpeg', [
        '-y', '-i', localPath, '-ss', '00:00:01.000', '-vframes', '1',
        '-vf', `scale=${THUMB_WIDTH}:-1`, tmp,
      ]);
      thumbnail = await readFile(tmp);
    } catch (err) {
      logger.warn('ffmpeg thumbnail failed', { error: String(err) });
    } finally {
      await rm(tmp, { force: true });
    }
  }

  return { width, height, durationSeconds, thumbnail };
}

const binaryCache = new Map<string, boolean>();

async function hasBinary(bin: string): Promise<boolean> {
  const cached = binaryCache.get(bin);
  if (cached !== undefined) return cached;
  try {
    await execFileAsync(bin, ['-version']);
    binaryCache.set(bin, true);
    return true;
  } catch {
    binaryCache.set(bin, false);
    return false;
  }
}
