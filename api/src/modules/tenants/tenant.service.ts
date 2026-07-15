import type { License, PlanName, Tenant } from '@signage/shared';
import { PLAN_LIMITS } from '@signage/shared';
import { pool, query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';
import { hashPassword } from '../../utils/password.js';

interface TenantRow {
  id: string;
  name: string;
  plan: string;
  max_screens: number | null;
  active: boolean;
  created_at: Date;
  updated_at: Date;
}

function toTenant(row: TenantRow): Tenant {
  return {
    id: row.id,
    name: row.name,
    plan: row.plan as PlanName,
    maxScreens: row.max_screens,
    active: row.active,
    createdAt: row.created_at.toISOString(),
    updatedAt: row.updated_at.toISOString(),
  };
}

/** The tenants table is platform-level (not RLS scoped); use the pool directly. */
export async function getTenant(id: string): Promise<Tenant> {
  const { rows } = await pool.query<TenantRow>('SELECT * FROM tenants WHERE id = $1', [id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Tenant not found');
  return toTenant(rows[0]);
}

/**
 * Provisions a new tenant plus its first admin user — a platform-level
 * operation used for onboarding new organizations.
 */
export async function provisionTenant(input: {
  name: string;
  plan?: PlanName;
  adminEmail: string;
  adminName: string;
  adminPassword: string;
}): Promise<{ tenant: Tenant; adminId: string }> {
  const plan = input.plan ?? 'free';
  const maxScreens = PLAN_LIMITS[plan];
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const tenantRes = await client.query<TenantRow>(
      `INSERT INTO tenants (name, plan, max_screens) VALUES ($1, $2, $3) RETURNING *`,
      [input.name, plan, maxScreens],
    );
    const tenant = toTenant(tenantRes.rows[0]);
    const hash = await hashPassword(input.adminPassword);
    const userRes = await client.query<{ id: string }>(
      `INSERT INTO users (tenant_id, email, name, password_hash, role)
       VALUES ($1, $2, $3, $4, 'admin') RETURNING id`,
      [tenant.id, input.adminEmail, input.adminName, hash],
    );
    await client.query('COMMIT');
    return { tenant, adminId: userRes.rows[0].id };
  } catch (err) {
    await client.query('ROLLBACK');
    if (String(err).includes('duplicate key')) {
      throw new HttpError(409, 'conflict', 'Email already in use');
    }
    throw err;
  } finally {
    client.release();
  }
}

/** Current license + screen usage for the active tenant. */
export async function getLicense(tenantId: string): Promise<License> {
  const tenant = await getTenant(tenantId);
  // Runs in tenant context, so this count is already scoped by RLS.
  const { rows } = await query<{ count: string }>('SELECT count(*)::text AS count FROM monitors');
  const usedScreens = Number(rows[0].count);
  return {
    plan: tenant.plan,
    maxScreens: tenant.maxScreens,
    usedScreens,
    remainingScreens: tenant.maxScreens === null ? null : Math.max(0, tenant.maxScreens - usedScreens),
  };
}

/** Throws if the tenant has reached its screen limit. */
export async function assertScreenQuota(tenantId: string): Promise<void> {
  const license = await getLicense(tenantId);
  if (license.remainingScreens !== null && license.remainingScreens <= 0) {
    throw new HttpError(
      402,
      'quota_exceeded',
      `Screen limit reached for the ${license.plan} plan (${license.maxScreens}). Upgrade to add more.`,
    );
  }
}
