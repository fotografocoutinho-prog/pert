import type { LoginResponse, User } from '@signage/shared';
import { query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';
import { hashPassword, sha256, verifyPassword } from '../../utils/password.js';
import { signAccessToken, signRefreshToken, verifyRefreshToken } from '../../utils/jwt.js';
import { writeLog } from '../audit/audit.service.js';
import { env } from '../../config/env.js';

interface UserRow {
  id: string;
  email: string;
  name: string;
  role: 'admin' | 'operator' | 'client';
  active: boolean;
  password_hash: string;
  created_at: Date;
  updated_at: Date;
}

function toUser(row: UserRow): User {
  return {
    id: row.id,
    email: row.email,
    name: row.name,
    role: row.role,
    active: row.active,
    createdAt: row.created_at.toISOString(),
    updatedAt: row.updated_at.toISOString(),
  };
}

async function issueTokens(user: User): Promise<LoginResponse['tokens']> {
  const accessToken = signAccessToken({ sub: user.id, role: user.role, email: user.email });
  const refreshToken = signRefreshToken({ sub: user.id });
  const expiresAt = new Date(Date.now() + env.jwt.refreshTtl * 1000);
  await query(
    `INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES ($1, $2, $3)`,
    [user.id, sha256(refreshToken), expiresAt],
  );
  return { accessToken, refreshToken, expiresIn: env.jwt.accessTtl };
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const { rows } = await query<UserRow>('SELECT * FROM users WHERE email = $1', [email]);
  const row = rows[0];
  if (!row || !row.active) {
    throw new HttpError(401, 'invalid_credentials', 'Invalid email or password');
  }
  const ok = await verifyPassword(password, row.password_hash);
  if (!ok) {
    throw new HttpError(401, 'invalid_credentials', 'Invalid email or password');
  }
  const user = toUser(row);
  const tokens = await issueTokens(user);
  await writeLog({ userId: user.id, action: 'auth.login', detail: { email: user.email } });
  return { user, tokens };
}

export async function refresh(refreshToken: string): Promise<LoginResponse> {
  let payload: { sub: string };
  try {
    payload = verifyRefreshToken(refreshToken);
  } catch {
    throw new HttpError(401, 'invalid_token', 'Invalid refresh token');
  }

  const tokenHash = sha256(refreshToken);
  const stored = await query<{ id: string }>(
    `SELECT id FROM refresh_tokens
     WHERE user_id = $1 AND token_hash = $2 AND revoked_at IS NULL AND expires_at > now()`,
    [payload.sub, tokenHash],
  );
  if (stored.rows.length === 0) {
    throw new HttpError(401, 'invalid_token', 'Refresh token revoked or expired');
  }

  // Rotate: revoke the used token, then issue a fresh pair.
  await query('UPDATE refresh_tokens SET revoked_at = now() WHERE id = $1', [stored.rows[0].id]);

  const { rows } = await query<UserRow>('SELECT * FROM users WHERE id = $1', [payload.sub]);
  if (rows.length === 0) throw new HttpError(401, 'invalid_token', 'User not found');

  const user = toUser(rows[0]);
  const tokens = await issueTokens(user);
  return { user, tokens };
}

export async function logout(refreshToken: string): Promise<void> {
  await query('UPDATE refresh_tokens SET revoked_at = now() WHERE token_hash = $1', [
    sha256(refreshToken),
  ]);
}

export async function getUserById(id: string): Promise<User> {
  const { rows } = await query<UserRow>('SELECT * FROM users WHERE id = $1', [id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'User not found');
  return toUser(rows[0]);
}

export async function createUser(input: {
  email: string;
  name: string;
  password: string;
  role: 'admin' | 'operator' | 'client';
}): Promise<User> {
  const hash = await hashPassword(input.password);
  try {
    const { rows } = await query<UserRow>(
      `INSERT INTO users (email, name, password_hash, role)
       VALUES ($1, $2, $3, $4) RETURNING *`,
      [input.email, input.name, hash, input.role],
    );
    return toUser(rows[0]);
  } catch (err) {
    if (String(err).includes('duplicate key')) {
      throw new HttpError(409, 'conflict', 'Email already in use');
    }
    throw err;
  }
}
