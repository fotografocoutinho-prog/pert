import 'dotenv/config';

function required(name: string, fallback?: string): string {
  const value = process.env[name] ?? fallback;
  if (value === undefined) {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

function int(name: string, fallback: number): number {
  const raw = process.env[name];
  if (raw === undefined) return fallback;
  const parsed = Number.parseInt(raw, 10);
  return Number.isNaN(parsed) ? fallback : parsed;
}

export const env = {
  nodeEnv: process.env.NODE_ENV ?? 'development',
  isProd: process.env.NODE_ENV === 'production',
  apiPort: int('API_PORT', 4000),
  corsOrigin: process.env.CORS_ORIGIN ?? 'http://localhost:5173',
  storageDir: process.env.STORAGE_DIR ?? './storage',

  db: {
    host: process.env.POSTGRES_HOST ?? 'localhost',
    port: int('POSTGRES_PORT', 5432),
    user: process.env.POSTGRES_USER ?? 'signage',
    password: process.env.POSTGRES_PASSWORD ?? 'signage',
    database: process.env.POSTGRES_DB ?? 'signage',
  },

  jwt: {
    accessSecret: required('JWT_ACCESS_SECRET', 'dev-access-secret'),
    refreshSecret: required('JWT_REFRESH_SECRET', 'dev-refresh-secret'),
    accessTtl: int('JWT_ACCESS_TTL', 900),
    refreshTtl: int('JWT_REFRESH_TTL', 1_209_600),
  },
} as const;
