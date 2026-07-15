import { AsyncLocalStorage } from 'node:async_hooks';
import pg from 'pg';
import { env } from '../config/env.js';

/** Admin/superuser pool — migrations, role provisioning, cross-tenant DDL. */
export const adminPool = new pg.Pool({
  host: env.db.host,
  port: env.db.port,
  user: env.db.user,
  password: env.db.password,
  database: env.db.database,
  max: 5,
  idleTimeoutMillis: 30_000,
});

/**
 * Runtime pool — connects as the non-superuser app role so Row-Level Security
 * is enforced on every query. All request-path code uses this pool.
 */
export const pool = new pg.Pool({
  host: env.db.host,
  port: env.db.port,
  user: env.appDb.user,
  password: env.appDb.password,
  database: env.db.database,
  max: 20,
  idleTimeoutMillis: 30_000,
});

interface TenantContext {
  client: pg.PoolClient;
  tenantId: string;
}

/** Holds the per-request tenant-scoped connection (see middleware/tenant.ts). */
export const tenantStore = new AsyncLocalStorage<TenantContext>();

/** Returns the tenant id for the current async context, if any. */
export function currentTenantId(): string | undefined {
  return tenantStore.getStore()?.tenantId;
}

/**
 * Runs a query. When a tenant context is active the query runs on that
 * connection (which has `app.tenant_id` set, so RLS applies); otherwise it uses
 * the shared pool (used by migrations and system tasks).
 */
export async function query<T extends pg.QueryResultRow = pg.QueryResultRow>(
  text: string,
  params?: unknown[],
): Promise<pg.QueryResult<T>> {
  const ctx = tenantStore.getStore();
  const runner = ctx?.client ?? pool;
  return runner.query<T>(text, params as never);
}

/**
 * Checks out a connection, sets the tenant, runs `fn` within a tenant context,
 * then resets and releases. Used by non-HTTP entry points (e.g. the WebSocket
 * server) that still need tenant-scoped access.
 */
export async function runWithTenant<T>(tenantId: string, fn: () => Promise<T>): Promise<T> {
  const client = await pool.connect();
  try {
    await client.query("SELECT set_config('app.tenant_id', $1, false)", [tenantId]);
    return await tenantStore.run({ client, tenantId }, fn);
  } finally {
    await client.query('RESET app.tenant_id').catch(() => undefined);
    client.release();
  }
}

export async function withTransaction<T>(
  fn: (client: pg.PoolClient) => Promise<T>,
): Promise<T> {
  const ctx = tenantStore.getStore();
  // Reuse the tenant-scoped connection so RLS still applies inside the tx.
  if (ctx?.client) {
    const client = ctx.client;
    await client.query('BEGIN');
    try {
      const result = await fn(client);
      await client.query('COMMIT');
      return result;
    } catch (err) {
      await client.query('ROLLBACK');
      throw err;
    }
  }

  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (err) {
    await client.query('ROLLBACK');
    throw err;
  } finally {
    client.release();
  }
}
