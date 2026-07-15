import crypto from 'node:crypto';
import { createReadStream } from 'node:fs';
import { access, mkdir, rename, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import type { Readable } from 'node:stream';
import { env } from '../../config/env.js';
import { logger } from '../../utils/logger.js';

/**
 * Storage abstraction. Callers compute checksum/size on the local upload before
 * `put`, so both drivers stay simple. Reads return a stream for the download
 * routes. Local is the default; S3 is enabled with STORAGE_DRIVER=s3.
 */
export interface StorageDriver {
  keyFor(filename: string): string;
  put(key: string, localPath: string): Promise<void>;
  putBuffer(key: string, buffer: Buffer): Promise<void>;
  read(key: string): Promise<Readable>;
  remove(key: string): Promise<void>;
  exists(key: string): Promise<boolean>;
}

function makeKey(filename: string): string {
  const id = crypto.randomUUID();
  const safe = filename.replace(/[^\w.-]+/g, '_');
  return `${id.slice(0, 2)}/${id}-${safe}`;
}

class LocalStorage implements StorageDriver {
  private readonly root = resolve(env.storageDir);

  private abs(key: string): string {
    return join(this.root, key);
  }

  keyFor(filename: string): string {
    return makeKey(filename);
  }

  async put(key: string, localPath: string): Promise<void> {
    const dest = this.abs(key);
    await mkdir(dirname(dest), { recursive: true });
    await rename(localPath, dest);
  }

  async putBuffer(key: string, buffer: Buffer): Promise<void> {
    const dest = this.abs(key);
    await mkdir(dirname(dest), { recursive: true });
    await writeFile(dest, buffer);
  }

  async read(key: string): Promise<Readable> {
    return createReadStream(this.abs(key));
  }

  async remove(key: string): Promise<void> {
    await rm(this.abs(key), { force: true });
  }

  async exists(key: string): Promise<boolean> {
    try {
      await access(this.abs(key));
      return true;
    } catch {
      return false;
    }
  }
}

/** S3 driver — requires `@aws-sdk/client-s3` (loaded lazily). */
class S3Storage implements StorageDriver {
  private clientPromise: Promise<{ client: unknown; lib: Record<string, unknown> }> | null = null;

  private async client(): Promise<{ client: unknown; lib: Record<string, unknown> }> {
    if (!this.clientPromise) {
      this.clientPromise = (async () => {
        const lib = (await import('@aws-sdk/client-s3' as string)) as Record<string, unknown>;
        const S3Client = lib.S3Client as new (cfg: unknown) => unknown;
        const client = new S3Client({
          region: env.storage.s3.region,
          endpoint: env.storage.s3.endpoint || undefined,
          forcePathStyle: !!env.storage.s3.endpoint,
          credentials: {
            accessKeyId: env.storage.s3.accessKeyId,
            secretAccessKey: env.storage.s3.secretAccessKey,
          },
        });
        return { client, lib };
      })();
    }
    return this.clientPromise;
  }

  keyFor(filename: string): string {
    return makeKey(filename);
  }

  private async send(commandName: string, input: Record<string, unknown>): Promise<unknown> {
    const { client, lib } = await this.client();
    const Command = lib[commandName] as new (i: unknown) => unknown;
    return (client as { send: (c: unknown) => Promise<unknown> }).send(new Command(input));
  }

  async put(key: string, localPath: string): Promise<void> {
    const body = createReadStream(localPath);
    await this.send('PutObjectCommand', { Bucket: env.storage.s3.bucket, Key: key, Body: body });
  }

  async putBuffer(key: string, buffer: Buffer): Promise<void> {
    await this.send('PutObjectCommand', { Bucket: env.storage.s3.bucket, Key: key, Body: buffer });
  }

  async read(key: string): Promise<Readable> {
    const res = (await this.send('GetObjectCommand', {
      Bucket: env.storage.s3.bucket,
      Key: key,
    })) as { Body: Readable };
    return res.Body;
  }

  async remove(key: string): Promise<void> {
    await this.send('DeleteObjectCommand', { Bucket: env.storage.s3.bucket, Key: key });
  }

  async exists(key: string): Promise<boolean> {
    try {
      await this.send('HeadObjectCommand', { Bucket: env.storage.s3.bucket, Key: key });
      return true;
    } catch {
      return false;
    }
  }
}

export const storage: StorageDriver =
  env.storage.driver === 's3' ? new S3Storage() : new LocalStorage();

logger.info('Storage driver initialised', { driver: env.storage.driver });
