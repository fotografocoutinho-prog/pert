import type { Request, Response } from 'express';
import type { PoolClient } from 'pg';
import { pool } from '../db/pool.js';

export interface TenantContext {
  client: PoolClient;
  tenantId: string;
}

/**
 * Checks out a dedicated connection for this request and sets `app.tenant_id`
 * on it so Row-Level Security scopes every query. The connection is reset and
 * released when the response finishes. The returned context must be activated
 * with `tenantStore.run(...)` around `next()` (see the auth middleware) — this
 * is what makes the context visible to the downstream handler chain.
 */
export async function openTenantContext(req: Request, res: Response): Promise<TenantContext> {
  const tenantId = req.user!.tenantId;
  const client = await pool.connect();
  await client.query("SELECT set_config('app.tenant_id', $1, false)", [tenantId]);

  let released = false;
  const release = (): void => {
    if (released) return;
    released = true;
    client.query('RESET app.tenant_id').catch(() => undefined);
    client.release();
  };
  res.on('finish', release);
  res.on('close', release);

  return { client, tenantId };
}
