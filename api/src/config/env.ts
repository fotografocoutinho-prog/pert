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
  metricsToken: process.env.METRICS_TOKEN ?? '',
  redisUrl: process.env.REDIS_URL ?? '',

  mqtt: {
    url: process.env.MQTT_URL ?? '',
    topicPrefix: process.env.MQTT_TOPIC_PREFIX ?? 'signage',
    // Home Assistant MQTT discovery prefix (default HA convention).
    haDiscoveryPrefix: process.env.MQTT_HA_DISCOVERY_PREFIX ?? 'homeassistant',
  },

  storage: {
    driver: (process.env.STORAGE_DRIVER ?? 'local') as 'local' | 's3',
    s3: {
      bucket: process.env.S3_BUCKET ?? '',
      region: process.env.S3_REGION ?? 'us-east-1',
      endpoint: process.env.S3_ENDPOINT ?? '',
      accessKeyId: process.env.S3_ACCESS_KEY_ID ?? '',
      secretAccessKey: process.env.S3_SECRET_ACCESS_KEY ?? '',
    },
  },

  // Admin/superuser connection — runs migrations and DDL (creates the app role).
  db: {
    host: process.env.POSTGRES_HOST ?? 'localhost',
    port: int('POSTGRES_PORT', 5432),
    user: process.env.POSTGRES_USER ?? 'signage',
    password: process.env.POSTGRES_PASSWORD ?? 'signage',
    database: process.env.POSTGRES_DB ?? 'signage',
  },

  // Runtime connection — a NON-superuser role so Row-Level Security is enforced.
  // Must differ from the admin user; auto-provisioned during migration.
  appDb: {
    user: process.env.APP_DB_USER ?? 'signage_app',
    password: process.env.APP_DB_PASSWORD ?? 'signage_app',
  },

  jwt: {
    accessSecret: required('JWT_ACCESS_SECRET', 'dev-access-secret'),
    refreshSecret: required('JWT_REFRESH_SECRET', 'dev-refresh-secret'),
    accessTtl: int('JWT_ACCESS_TTL', 900),
    refreshTtl: int('JWT_REFRESH_TTL', 1_209_600),
  },
} as const;
