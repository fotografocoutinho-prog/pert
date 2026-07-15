import crypto from 'node:crypto';
import { createReadStream } from 'node:fs';
import { mkdir, rm, stat } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { env } from '../../config/env.js';

/**
 * Local filesystem storage. The interface is intentionally small so an S3
 * driver can be swapped in later without touching callers.
 */
export interface StorageDriver {
  keyFor(filename: string): string;
  absolutePath(key: string): string;
  remove(key: string): Promise<void>;
  checksum(key: string): Promise<string>;
  size(key: string): Promise<number>;
}

class LocalStorage implements StorageDriver {
  private readonly root = resolve(env.storageDir);

  keyFor(filename: string): string {
    const id = crypto.randomUUID();
    const safe = filename.replace(/[^\w.-]+/g, '_');
    return join(id.slice(0, 2), `${id}-${safe}`);
  }

  absolutePath(key: string): string {
    return join(this.root, key);
  }

  async ensureDir(key: string): Promise<void> {
    await mkdir(dirname(this.absolutePath(key)), { recursive: true });
  }

  async remove(key: string): Promise<void> {
    await rm(this.absolutePath(key), { force: true });
  }

  async checksum(key: string): Promise<string> {
    return new Promise((resolvePromise, reject) => {
      const hash = crypto.createHash('sha256');
      const stream = createReadStream(this.absolutePath(key));
      stream.on('data', (chunk) => hash.update(chunk));
      stream.on('end', () => resolvePromise(hash.digest('hex')));
      stream.on('error', reject);
    });
  }

  async size(key: string): Promise<number> {
    const s = await stat(this.absolutePath(key));
    return s.size;
  }
}

export const storage = new LocalStorage();
