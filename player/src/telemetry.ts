import os from 'node:os';
import { readFile, statfs } from 'node:fs/promises';

export interface Telemetry {
  cpuPercent: number | null;
  ramPercent: number | null;
  temperatureC: number | null;
  freeDiskBytes: number | null;
  uptimeSeconds: number;
  online: boolean;
}

/** Reads the SoC temperature on Raspberry Pi / Linux, if exposed. */
async function readTemperature(): Promise<number | null> {
  try {
    const raw = await readFile('/sys/class/thermal/thermal_zone0/temp', 'utf8');
    const milli = Number(raw.trim());
    return Number.isFinite(milli) ? Math.round(milli / 1000) : null;
  } catch {
    return null;
  }
}

async function readFreeDisk(path: string): Promise<number | null> {
  try {
    const s = await statfs(path);
    return s.bavail * s.bsize;
  } catch {
    return null;
  }
}

/** Approximate CPU load from the 1-minute load average over core count. */
function cpuPercent(): number | null {
  const cores = os.cpus().length || 1;
  const load1 = os.loadavg()[0];
  if (!Number.isFinite(load1)) return null;
  return Math.min(100, Math.round((load1 / cores) * 100));
}

export async function collectTelemetry(diskPath: string): Promise<Telemetry> {
  const total = os.totalmem();
  const free = os.freemem();
  const [temperatureC, freeDiskBytes] = await Promise.all([
    readTemperature(),
    readFreeDisk(diskPath),
  ]);
  return {
    cpuPercent: cpuPercent(),
    ramPercent: total > 0 ? Math.round((1 - free / total) * 100) : null,
    temperatureC,
    freeDiskBytes,
    uptimeSeconds: Math.round(os.uptime()),
    online: true,
  };
}
