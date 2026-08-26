/**
 * Autenticação do backoffice.
 *
 * - Password guardada como hash scrypt (nunca em claro).
 * - Sessão = token aleatório de 32 bytes; o servidor só guarda o hash SHA-256.
 * - Cookie HttpOnly + SameSite=Lax; os pedidos que alteram dados exigem
 *   também o cabeçalho X-Requested-With (proteção CSRF simples e eficaz).
 */
import crypto from 'node:crypto';
import { promisify } from 'node:util';
import { safeEqual } from './util.js';

const scrypt = promisify(crypto.scrypt);

export const SESSION_COOKIE = 'mv_session';
const SESSION_TTL_MS = 12 * 60 * 60 * 1000;   // 12 horas
const KEY_LEN = 64;

export async function hashPassword(password) {
  const salt = crypto.randomBytes(16).toString('hex');
  const derived = await scrypt(password, salt, KEY_LEN);
  return `scrypt$${salt}$${derived.toString('hex')}`;
}

export async function verifyPassword(password, stored) {
  if (typeof stored !== 'string') return false;
  const [algo, salt, hash] = stored.split('$');
  if (algo !== 'scrypt' || !salt || !hash) return false;
  const derived = await scrypt(password, salt, KEY_LEN);
  return safeEqual(derived.toString('hex'), hash);
}

const tokenDigest = (token) => crypto.createHash('sha256').update(token).digest('hex');

export class AuthService {
  /** @param {import('./store.js').JsonStore} store */
  constructor(store) {
    this.store = store;
  }

  /** Cria a conta na primeira arrancada, com a password do ambiente. */
  async bootstrap(initialPassword) {
    return this.store.update(async (data) => {
      if (!data.passwordHash) {
        const password = initialPassword && initialPassword.length >= 6 ? initialPassword : '1000viagens';
        data.passwordHash = await hashPassword(password);
        data.mustChangePassword = !initialPassword || initialPassword === 'mudar-esta-password';
        data.createdAt = new Date().toISOString();
        data.sessions = data.sessions || [];
        return { created: true, usedFallback: !initialPassword };
      }
      return { created: false };
    });
  }

  async status() {
    const data = await this.store.read();
    return {
      mustChangePassword: Boolean(data.mustChangePassword),
      lastLoginAt: data.lastLoginAt || '',
      failedAttempts: data.failedAttempts || 0,
    };
  }

  /** Valida a password e devolve um token de sessão. */
  async login(password, meta = {}) {
    const data = await this.store.read();
    const ok = await verifyPassword(String(password || ''), data.passwordHash);
    if (!ok) {
      await this.store.update((d) => {
        d.failedAttempts = (d.failedAttempts || 0) + 1;
        d.lastFailedAt = new Date().toISOString();
      });
      return null;
    }
    const token = crypto.randomBytes(32).toString('base64url');
    const now = Date.now();
    await this.store.update((d) => {
      d.sessions = (d.sessions || []).filter((s) => s.expiresAt > now);
      d.sessions.push({
        digest: tokenDigest(token),
        createdAt: new Date(now).toISOString(),
        expiresAt: now + SESSION_TTL_MS,
        ip: meta.ip || '',
        agent: String(meta.agent || '').slice(0, 180),
      });
      if (d.sessions.length > 20) d.sessions = d.sessions.slice(-20);
      d.failedAttempts = 0;
      d.lastLoginAt = new Date(now).toISOString();
    });
    return { token, expiresAt: now + SESSION_TTL_MS };
  }

  /** Devolve a sessão se o token for válido; caso contrário, null. */
  async resolve(token) {
    if (!token) return null;
    const data = await this.store.read();
    const digest = tokenDigest(token);
    const session = (data.sessions || []).find((s) => s.digest === digest);
    if (!session) return null;
    if (session.expiresAt <= Date.now()) return null;
    return session;
  }

  async logout(token) {
    if (!token) return;
    const digest = tokenDigest(token);
    await this.store.update((d) => {
      d.sessions = (d.sessions || []).filter((s) => s.digest !== digest);
    });
  }

  /** Muda a password e termina todas as outras sessões. */
  async changePassword(currentPassword, newPassword) {
    const data = await this.store.read();
    const ok = await verifyPassword(String(currentPassword || ''), data.passwordHash);
    if (!ok) return { ok: false, error: 'A password atual não está correta.' };
    if (typeof newPassword !== 'string' || newPassword.length < 8) {
      return { ok: false, error: 'A nova password tem de ter pelo menos 8 caracteres.' };
    }
    const hash = await hashPassword(newPassword);
    await this.store.update((d) => {
      d.passwordHash = hash;
      d.mustChangePassword = false;
      d.sessions = [];
      d.passwordChangedAt = new Date().toISOString();
    });
    return { ok: true };
  }
}
