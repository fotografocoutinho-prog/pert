-- Phase 3 — remote management & reliability

-- Latest remote screenshot per monitor (one row per monitor, upserted).
CREATE TABLE IF NOT EXISTS screenshots (
  monitor_id  UUID PRIMARY KEY REFERENCES monitors(id) ON DELETE CASCADE,
  storage_key TEXT NOT NULL,
  mime_type   TEXT NOT NULL DEFAULT 'image/webp',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Published player releases for OTA updates.
CREATE TABLE IF NOT EXISTS player_releases (
  id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  version    TEXT NOT NULL,
  url        TEXT NOT NULL,
  checksum   TEXT NOT NULL,
  notes      TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_player_releases_created ON player_releases(created_at DESC);

-- Index to fetch a monitor's recent logs quickly.
CREATE INDEX IF NOT EXISTS idx_logs_monitor ON logs(monitor_id, created_at DESC);
