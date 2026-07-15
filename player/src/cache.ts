import { createHash } from 'node:crypto';
import { createWriteStream } from 'node:fs';
import { access, mkdir, readFile, readdir, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

export interface CacheItem {
  contentId: string;
  checksum: string;
}

/**
 * Downloads content files into a checksum-addressed local cache so the player
 * keeps working offline and only fetches assets that changed. Returns a map of
 * contentId -> local file:// URL for everything that is available locally.
 */
export class ContentCache {
  constructor(
    private readonly dir: string,
    private readonly apiUrl: string,
    private readonly token: string,
  ) {}

  private pathFor(checksum: string): string {
    return join(this.dir, checksum);
  }

  private async exists(path: string): Promise<boolean> {
    try {
      await access(path);
      return true;
    } catch {
      return false;
    }
  }

  /** Ensures every item is cached; returns contentId -> file URL for the ready ones. */
  async sync(items: CacheItem[]): Promise<Record<string, string>> {
    await mkdir(this.dir, { recursive: true });
    const map: Record<string, string> = {};
    for (const item of items) {
      const path = this.pathFor(item.checksum);
      if (!(await this.exists(path))) {
        try {
          await this.download(item.contentId, path);
        } catch {
          continue; // leave uncached; renderer can fall back to the remote URL
        }
      }
      map[item.contentId] = pathToFileURL(path).href;
    }
    await this.prune(items.map((i) => i.checksum));
    return map;
  }

  private async download(contentId: string, dest: string): Promise<void> {
    const url = `${this.apiUrl}/api/contents/${contentId}/download?token=${encodeURIComponent(this.token)}`;
    const res = await fetch(url);
    if (!res.ok || !res.body) throw new Error(`download failed ${res.status}`);
    const tmp = `${dest}.tmp`;
    await pipeline(Readable.fromWeb(res.body as never), createWriteStream(tmp));
    // Verify integrity before publishing into the cache.
    const actual = await this.checksumOf(tmp);
    const expected = dest.split('/').pop();
    if (expected && actual !== expected) {
      await rm(tmp, { force: true });
      throw new Error('checksum mismatch');
    }
    const { rename } = await import('node:fs/promises');
    await rename(tmp, dest);
  }

  private async checksumOf(path: string): Promise<string> {
    const hash = createHash('sha256');
    hash.update(await readFile(path));
    return hash.digest('hex');
  }

  /** Removes cached files that are no longer referenced. */
  private async prune(keep: string[]): Promise<void> {
    const keepSet = new Set(keep);
    try {
      for (const name of await readdir(this.dir)) {
        if (!name.endsWith('.tmp') && !keepSet.has(name)) {
          await rm(join(this.dir, name), { force: true });
        }
      }
    } catch {
      /* ignore prune errors */
    }
  }
}
