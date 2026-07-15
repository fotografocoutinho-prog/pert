import { createHash } from 'node:crypto';
import { createWriteStream } from 'node:fs';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

export interface UpdateManifest {
  version: string;
  url: string;
  checksum: string;
  notes: string | null;
}

/** Parses a dotted version into numeric parts (non-numeric segments -> 0). */
function parts(version: string): number[] {
  return version
    .replace(/^v/, '')
    .split('.')
    .map((p) => Number.parseInt(p, 10) || 0);
}

/** Returns >0 if a>b, <0 if a<b, 0 if equal. */
export function compareVersions(a: string, b: string): number {
  const pa = parts(a);
  const pb = parts(b);
  const len = Math.max(pa.length, pb.length);
  for (let i = 0; i < len; i++) {
    const diff = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (diff !== 0) return Math.sign(diff);
  }
  return 0;
}

export function isNewerVersion(candidate: string, current: string): boolean {
  return compareVersions(candidate, current) > 0;
}

/**
 * Decides whether to roll back to the previous version. A freshly applied
 * update that fails to boot cleanly `threshold` times is considered bad.
 */
export function shouldRollback(consecutiveBootFailures: number, threshold = 3): boolean {
  return consecutiveBootFailures >= threshold;
}

interface UpdaterState {
  currentVersion: string;
  previousVersion: string | null;
  bootFailures: number;
}

/**
 * Manages OTA bundles under a directory:
 *   <dir>/state.json          bookkeeping
 *   <dir>/versions/<version>  extracted/downloaded bundle
 * The main process wires relaunch; this class handles fetch, verification,
 * staging and the rollback decision.
 */
export class Updater {
  constructor(
    private readonly dir: string,
    private readonly apiUrl: string,
    private readonly token: string,
  ) {}

  private get versionsDir(): string {
    return join(this.dir, 'versions');
  }

  private get statePath(): string {
    return join(this.dir, 'state.json');
  }

  async loadState(fallbackVersion: string): Promise<UpdaterState> {
    try {
      return JSON.parse(await readFile(this.statePath, 'utf8')) as UpdaterState;
    } catch {
      return { currentVersion: fallbackVersion, previousVersion: null, bootFailures: 0 };
    }
  }

  private async saveState(state: UpdaterState): Promise<void> {
    await mkdir(this.dir, { recursive: true });
    await writeFile(this.statePath, JSON.stringify(state, null, 2));
  }

  async checkManifest(): Promise<UpdateManifest | null> {
    const res = await fetch(`${this.apiUrl}/api/updates/player/latest`, {
      headers: { Authorization: `Bearer ${this.token}` },
    });
    if (res.status === 204 || !res.ok) return null;
    return (await res.json()) as UpdateManifest;
  }

  /** Downloads and verifies a bundle into the versions dir. Returns its path. */
  async stage(manifest: UpdateManifest): Promise<string> {
    await mkdir(this.versionsDir, { recursive: true });
    const dest = join(this.versionsDir, manifest.version);
    const tmp = `${dest}.download`;
    const res = await fetch(manifest.url);
    if (!res.ok || !res.body) throw new Error(`bundle download failed ${res.status}`);
    await pipeline(Readable.fromWeb(res.body as never), createWriteStream(tmp));

    const hash = createHash('sha256');
    hash.update(await readFile(tmp));
    if (hash.digest('hex') !== manifest.checksum) {
      await rm(tmp, { force: true });
      throw new Error('bundle checksum mismatch');
    }
    const { rename } = await import('node:fs/promises');
    await rename(tmp, dest);
    return dest;
  }

  /** Records a boot outcome and rolls the pointer back if the update is bad. */
  async recordBoot(success: boolean, fallbackVersion: string): Promise<UpdaterState> {
    const state = await this.loadState(fallbackVersion);
    if (success) {
      state.bootFailures = 0;
    } else {
      state.bootFailures += 1;
      if (shouldRollback(state.bootFailures) && state.previousVersion) {
        state.currentVersion = state.previousVersion;
        state.previousVersion = null;
        state.bootFailures = 0;
      }
    }
    await this.saveState(state);
    return state;
  }

  async promote(newVersion: string, fallbackVersion: string): Promise<UpdaterState> {
    const state = await this.loadState(fallbackVersion);
    state.previousVersion = state.currentVersion;
    state.currentVersion = newVersion;
    state.bootFailures = 0;
    await this.saveState(state);
    return state;
  }
}
