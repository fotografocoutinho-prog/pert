import type { Request, Response } from 'express';
import { pool } from '../../db/pool.js';
import { hub } from '../../ws/hub.js';
import { env } from '../../config/env.js';

const startedAt = Date.now();

/**
 * Prometheus text-format metrics for Grafana / Prometheus scraping. Reports
 * platform-level, non-tenant-scoped figures (safe to expose to ops tooling).
 * Optionally protected by METRICS_TOKEN.
 */
export async function metricsHandler(req: Request, res: Response): Promise<void> {
  if (env.metricsToken) {
    const provided = req.query.token ?? req.headers['x-metrics-token'];
    if (provided !== env.metricsToken) {
      res.status(401).type('text/plain').send('unauthorized');
      return;
    }
  }

  const [tenants, users] = await Promise.all([
    pool.query<{ count: string }>('SELECT count(*)::text AS count FROM tenants'),
    pool.query<{ count: string }>('SELECT count(*)::text AS count FROM users'),
  ]);

  const lines = [
    '# HELP signage_uptime_seconds API process uptime in seconds.',
    '# TYPE signage_uptime_seconds gauge',
    `signage_uptime_seconds ${Math.floor((Date.now() - startedAt) / 1000)}`,
    '# HELP signage_monitors_online Currently connected players (this node).',
    '# TYPE signage_monitors_online gauge',
    `signage_monitors_online ${hub.onlineIds().length}`,
    '# HELP signage_tenants_total Number of tenants.',
    '# TYPE signage_tenants_total gauge',
    `signage_tenants_total ${Number(tenants.rows[0].count)}`,
    '# HELP signage_users_total Number of users.',
    '# TYPE signage_users_total gauge',
    `signage_users_total ${Number(users.rows[0].count)}`,
  ];

  res.type('text/plain; version=0.0.4').send(lines.join('\n') + '\n');
}
