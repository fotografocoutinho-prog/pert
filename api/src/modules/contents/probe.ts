import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
import sharp from 'sharp';
import type { ContentKind } from '@signage/shared';
import { storage } from './storage.js';
import { logger } from '../../utils/logger.js';

const execFileAsync = promisify(execFile);

export interface ProbeResult {
  width: number | null;
  height: number | null;
  durationSeconds: number | null;
  thumbnailKey: string | null;
}

const THUMB_WIDTH = 400;

/** Probes a stored file for dimensions/duration and generates a thumbnail. */
export async function probe(storageKey: string, kind: ContentKind): Promise<ProbeResult> {
  try {
    if (kind === 'image') return await probeImage(storageKey);
    if (kind === 'video') return await probeVideo(storageKey);
    if (kind === 'pdf') return await probePdf(storageKey);
  } catch (err) {
    logger.warn('Media probe failed; continuing without metadata', {
      storageKey,
      error: String(err),
    });
  }
  return { width: null, height: null, durationSeconds: null, thumbnailKey: null };
}

async function probeImage(storageKey: string): Promise<ProbeResult> {
  const src = storage.absolutePath(storageKey);
  const meta = await sharp(src).metadata();
  const thumbnailKey = await writeThumbnail(storageKey, sharp(src));
  return {
    width: meta.width ?? null,
    height: meta.height ?? null,
    durationSeconds: null,
    thumbnailKey,
  };
}

async function probePdf(_storageKey: string): Promise<ProbeResult> {
  // First page render requires a PDF rasterizer (added in a later phase).
  return { width: null, height: null, durationSeconds: null, thumbnailKey: null };
}

async function probeVideo(storageKey: string): Promise<ProbeResult> {
  const src = storage.absolutePath(storageKey);
  let width: number | null = null;
  let height: number | null = null;
  let durationSeconds: number | null = null;

  if (await hasFfprobe()) {
    try {
      const { stdout } = await execFileAsync('ffprobe', [
        '-v', 'error',
        '-select_streams', 'v:0',
        '-show_entries', 'stream=width,height:format=duration',
        '-of', 'json',
        src,
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

  let thumbnailKey: string | null = null;
  if (await hasFfmpeg()) {
    thumbnailKey = await writeVideoThumbnail(storageKey, src);
  }

  return { width, height, durationSeconds, thumbnailKey };
}

async function writeThumbnail(storageKey: string, pipeline: sharp.Sharp): Promise<string> {
  const thumbKey = `${storageKey}.thumb.webp`;
  const dest = storage.absolutePath(thumbKey);
  await mkdir(dirname(dest), { recursive: true });
  await pipeline.resize({ width: THUMB_WIDTH, withoutEnlargement: true }).webp({ quality: 70 }).toFile(dest);
  return thumbKey;
}

async function writeVideoThumbnail(storageKey: string, src: string): Promise<string | null> {
  const thumbKey = `${storageKey}.thumb.webp`;
  const dest = storage.absolutePath(thumbKey);
  await mkdir(dirname(dest), { recursive: true });
  try {
    await execFileAsync('ffmpeg', [
      '-y', '-i', src, '-ss', '00:00:01.000', '-vframes', '1',
      '-vf', `scale=${THUMB_WIDTH}:-1`, dest,
    ]);
    return thumbKey;
  } catch (err) {
    logger.warn('ffmpeg thumbnail failed', { error: String(err) });
    return null;
  }
}

let ffprobeAvailable: boolean | null = null;
let ffmpegAvailable: boolean | null = null;

async function hasFfprobe(): Promise<boolean> {
  if (ffprobeAvailable === null) ffprobeAvailable = await binaryExists('ffprobe');
  return ffprobeAvailable;
}

async function hasFfmpeg(): Promise<boolean> {
  if (ffmpegAvailable === null) ffmpegAvailable = await binaryExists('ffmpeg');
  return ffmpegAvailable;
}

async function binaryExists(bin: string): Promise<boolean> {
  try {
    await execFileAsync(bin, ['-version']);
    return true;
  } catch {
    return false;
  }
}
