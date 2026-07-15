import { readdir, readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { adminPool } from './pool.js';
import { env } from '../config/env.js';
import { logger } from '../utils/logger.js';

const here = dirname(fileURLToPath(import.meta.url));
// api/src/db -> repo/database/migrations
const migrationsDir = join(here, '..', '..', '..', 'database', 'migrations');

async function ensureMigrationsTable(): Promise<void> {
  await adminPool.query(`
    CREATE TABLE IF NOT EXISTS schema_migrations (
      filename   TEXT PRIMARY KEY,
      applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    );
  `);
}

async function appliedMigrations(): Promise<Set<string>> {
  const { rows } = await adminPool.query<{ filename: string }>(
    'SELECT filename FROM schema_migrations',
  );
  return new Set(rows.map((r) => r.filename));
}

const IDENT = /^[a-zA-Z_][a-zA-Z0-9_]*$/;

/**
 * Provisions the non-superuser runtime role and grants it DML on every table,
 * so Row-Level Security applies to it. Idempotent.
 */
export async function ensureAppRole(): Promise<void> {
  const user = env.appDb.user;
  if (!IDENT.test(user)) throw new Error(`Invalid APP_DB_USER: ${user}`);
  if (user === env.db.user) {
    throw new Error('APP_DB_USER must differ from POSTGRES_USER (RLS is bypassed by the owner)');
  }
  const pass = env.appDb.password.replace(/'/g, "''");

  await adminPool.query(`DO $$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${user}') THEN
      CREATE ROLE ${user} LOGIN NOSUPERUSER NOBYPASSRLS;
    END IF;
  END $$;`);
  await adminPool.query(`ALTER ROLE ${user} WITH LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD '${pass}'`);
  await adminPool.query(`GRANT USAGE ON SCHEMA public TO ${user}`);
  await adminPool.query(
    `GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ${user}`,
  );
  await adminPool.query(`GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ${user}`);
  await adminPool.query(
    `ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${user}`,
  );
  await adminPool.query(
    `ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO ${user}`,
  );
  logger.info('App role ensured', { role: user });
}

export async function runMigrations(): Promise<void> {
  await ensureMigrationsTable();
  const applied = await appliedMigrations();
  const files = (await readdir(migrationsDir))
    .filter((f) => f.endsWith('.sql'))
    .sort();

  for (const file of files) {
    if (applied.has(file)) continue;
    const sql = await readFile(join(migrationsDir, file), 'utf8');
    logger.info(`Applying migration ${file}`);
    const client = await adminPool.connect();
    try {
      await client.query('BEGIN');
      await client.query(sql);
      await client.query('INSERT INTO schema_migrations (filename) VALUES ($1)', [file]);
      await client.query('COMMIT');
    } catch (err) {
      await client.query('ROLLBACK');
      throw err;
    } finally {
      client.release();
    }
  }

  // Re-grant after any new tables were created so the app role can use them.
  await ensureAppRole();
  logger.info('Migrations up to date');
}

// Allow running directly: `tsx src/db/migrate.ts`
if (import.meta.url === `file://${process.argv[1]}`) {
  runMigrations()
    .then(() => adminPool.end())
    .then(() => process.exit(0))
    .catch((err) => {
      logger.error('Migration failed', { error: String(err) });
      process.exit(1);
    });
}
