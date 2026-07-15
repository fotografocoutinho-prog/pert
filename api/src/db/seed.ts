import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import bcrypt from 'bcryptjs';
import { adminPool, pool, query, runWithTenant } from './pool.js';
import { runMigrations } from './migrate.js';
import { logger } from '../utils/logger.js';

const here = dirname(fileURLToPath(import.meta.url));
const seedFile = join(here, '..', '..', '..', 'database', 'seed', 'seed.sql');

// The fixed default tenant created by migration 003.
const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-0000000000d1';

const DEFAULT_ADMIN = {
  email: 'admin@signage.local',
  name: 'Administrator',
  password: 'admin123',
};

async function seed(): Promise<void> {
  await runMigrations();

  const hash = await bcrypt.hash(DEFAULT_ADMIN.password, 10);
  await pool.query(
    `INSERT INTO users (tenant_id, email, name, password_hash, role)
     VALUES ($1, $2, $3, $4, 'admin')
     ON CONFLICT (email) DO NOTHING`,
    [DEFAULT_TENANT_ID, DEFAULT_ADMIN.email, DEFAULT_ADMIN.name, hash],
  );

  // Demo data lives in the default tenant; RLS requires an active tenant context.
  const sql = await readFile(seedFile, 'utf8');
  await runWithTenant(DEFAULT_TENANT_ID, async () => {
    await query(sql);
  });

  logger.info(`Seed complete. Admin login: ${DEFAULT_ADMIN.email} / ${DEFAULT_ADMIN.password}`);
}

seed()
  .then(() => Promise.allSettled([pool.end(), adminPool.end()]))
  .then(() => process.exit(0))
  .catch((err) => {
    logger.error('Seed failed', { error: String(err) });
    process.exit(1);
  });
