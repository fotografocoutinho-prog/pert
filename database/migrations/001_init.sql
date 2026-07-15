-- Digital Signage — initial schema
-- Idempotent: safe to run multiple times.

CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "citext";

-- ---------------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------------
DO $$ BEGIN
  CREATE TYPE user_role AS ENUM ('admin', 'operator', 'client');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

CREATE TABLE IF NOT EXISTS users (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  email         CITEXT UNIQUE NOT NULL,
  name          TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role          user_role NOT NULL DEFAULT 'operator',
  active        BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Refresh tokens (rotating)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refresh_tokens (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash  TEXT NOT NULL,
  expires_at  TIMESTAMPTZ NOT NULL,
  revoked_at  TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user ON refresh_tokens(user_id);

-- ---------------------------------------------------------------------------
-- Layouts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS layouts (
  id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name       TEXT NOT NULL,
  preset     TEXT NOT NULL DEFAULT 'single',
  zones      JSONB NOT NULL DEFAULT '[]'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Contents (media library)
-- ---------------------------------------------------------------------------
DO $$ BEGIN
  CREATE TYPE content_kind AS ENUM ('image', 'video', 'audio', 'pdf');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

CREATE TABLE IF NOT EXISTS contents (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name             TEXT NOT NULL,
  kind             content_kind NOT NULL,
  mime_type        TEXT NOT NULL,
  size_bytes       BIGINT NOT NULL,
  duration_seconds NUMERIC,
  width            INTEGER,
  height           INTEGER,
  storage_key      TEXT NOT NULL,
  thumbnail_key    TEXT,
  checksum         TEXT NOT NULL,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Playlists
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playlists (
  id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name       TEXT NOT NULL,
  loop       BOOLEAN NOT NULL DEFAULT TRUE,
  shuffle    BOOLEAN NOT NULL DEFAULT FALSE,
  priority   INTEGER NOT NULL DEFAULT 0,
  start_date TIMESTAMPTZ,
  end_date   TIMESTAMPTZ,
  active     BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS playlist_items (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  playlist_id      UUID NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
  content_id       UUID NOT NULL REFERENCES contents(id) ON DELETE CASCADE,
  position         INTEGER NOT NULL DEFAULT 0,
  duration_seconds NUMERIC NOT NULL DEFAULT 10,
  scale_mode       TEXT NOT NULL DEFAULT 'fit',
  transition       TEXT NOT NULL DEFAULT 'fade',
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_playlist_items_playlist ON playlist_items(playlist_id, position);

-- ---------------------------------------------------------------------------
-- Monitors (screens / devices)
-- ---------------------------------------------------------------------------
DO $$ BEGIN
  CREATE TYPE monitor_status AS ENUM ('online', 'offline');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

CREATE TABLE IF NOT EXISTS monitors (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name           TEXT NOT NULL,
  group_name     TEXT,
  location       TEXT,
  resolution     TEXT,
  orientation    TEXT NOT NULL DEFAULT 'landscape',
  status         monitor_status NOT NULL DEFAULT 'offline',
  last_seen_at   TIMESTAMPTZ,
  player_version TEXT,
  ip_address     TEXT,
  os             TEXT,
  uptime_seconds BIGINT,
  layout_id      UUID REFERENCES layouts(id) ON DELETE SET NULL,
  playlist_id    UUID REFERENCES playlists(id) ON DELETE SET NULL,
  pairing_code   TEXT UNIQUE,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_monitors_group ON monitors(group_name);

-- ---------------------------------------------------------------------------
-- Schedules
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedules (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name        TEXT NOT NULL,
  monitor_id  UUID REFERENCES monitors(id) ON DELETE CASCADE,
  group_name  TEXT,
  playlist_id UUID NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
  priority    INTEGER NOT NULL DEFAULT 0,
  start_date  TIMESTAMPTZ,
  end_date    TIMESTAMPTZ,
  start_time  TEXT,
  end_time    TEXT,
  weekdays    INTEGER[] NOT NULL DEFAULT '{}',
  active      BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Telemetry samples (device health history)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS telemetry (
  id             BIGSERIAL PRIMARY KEY,
  monitor_id     UUID NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
  cpu_percent    NUMERIC,
  ram_percent    NUMERIC,
  temperature_c  NUMERIC,
  free_disk_bytes BIGINT,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_telemetry_monitor ON telemetry(monitor_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- Audit log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS logs (
  id         BIGSERIAL PRIMARY KEY,
  user_id    UUID REFERENCES users(id) ON DELETE SET NULL,
  monitor_id UUID REFERENCES monitors(id) ON DELETE SET NULL,
  action     TEXT NOT NULL,
  level      TEXT NOT NULL DEFAULT 'info',
  detail     JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_logs_created ON logs(created_at DESC);

-- ---------------------------------------------------------------------------
-- Settings (key/value)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  key        TEXT PRIMARY KEY,
  value      JSONB NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
