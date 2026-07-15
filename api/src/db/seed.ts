import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import bcrypt from 'bcryptjs';
import { pool } from './pool.js';
import { runMigrations } from './migrate.js';
import { logger } from '../utils/logger.js';

const here = dirname(fileURLToPath(import.meta.url));
const seedFile = join(here, '..', '..', '..', 'database', 'seed', 'seed.sql');

const DEFAULT_ADMIN = {
  email: 'admin@signage.local',
  name: 'Administrator',
  password: 'admin123',
};

async function seed(): Promise<void> {
  await runMigrations();

  const hash = await bcrypt.hash(DEFAULT_ADMIN.password, 10);
  await pool.query(
    `INSERT INTO users (email, name, password_hash, role)
     VALUES ($1, $2, $3, 'admin')
     ON CONFLICT (email) DO NOTHING`,
    [DEFAULT_ADMIN.email, DEFAULT_ADMIN.name, hash],
  );

  const sql = await readFile(seedFile, 'utf8');
  await pool.query(sql);

  logger.info(`Seed complete. Admin login: ${DEFAULT_ADMIN.email} / ${DEFAULT_ADMIN.password}`);
}

seed()
  .then(() => pool.end())
  .then(() => process.exit(0))
  .catch((err) => {
    logger.error('Seed failed', { error: String(err) });
    process.exit(1);
  });
