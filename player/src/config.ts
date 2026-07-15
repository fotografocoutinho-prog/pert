import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { app } from 'electron';

export interface PlayerConfig {
  apiUrl: string;
  wsUrl: string;
  monitorId: string;
  token: string;
}

/**
 * Loads config from (in order of precedence):
 *  1. environment variables (SIGNAGE_API_URL, SIGNAGE_MONITOR_ID, SIGNAGE_TOKEN)
 *  2. a config.json next to the app (userData dir)
 * Falls back to localhost defaults so the shell always launches.
 */
export function loadConfig(): PlayerConfig {
  const fileConfig = readFileConfig();
  const apiUrl = process.env.SIGNAGE_API_URL ?? fileConfig.apiUrl ?? 'http://localhost:4000';
  const monitorId = process.env.SIGNAGE_MONITOR_ID ?? fileConfig.monitorId ?? '';
  const token = process.env.SIGNAGE_TOKEN ?? fileConfig.token ?? '';
  const wsUrl = apiUrl.replace(/^http/, 'ws');
  return { apiUrl, wsUrl, monitorId, token };
}

function readFileConfig(): Partial<PlayerConfig> {
  try {
    const path = join(app.getPath('userData'), 'config.json');
    if (existsSync(path)) return JSON.parse(readFileSync(path, 'utf8')) as Partial<PlayerConfig>;
  } catch {
    /* ignore malformed config */
  }
  return {};
}
