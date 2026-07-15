import bcrypt from 'bcryptjs';
import crypto from 'node:crypto';

const ROUNDS = 10;

export function hashPassword(plain: string): Promise<string> {
  return bcrypt.hash(plain, ROUNDS);
}

export function verifyPassword(plain: string, hash: string): Promise<boolean> {
  return bcrypt.compare(plain, hash);
}

/** SHA-256 hex, used to store refresh tokens without keeping the raw value. */
export function sha256(value: string): string {
  return crypto.createHash('sha256').update(value).digest('hex');
}
