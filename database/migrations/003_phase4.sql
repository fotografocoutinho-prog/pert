-- Phase 4 — multi-tenant, licensing, proof-of-play
--
-- Tenant isolation is enforced with PostgreSQL Row-Level Security. Every
-- request runs on a connection where `app.tenant_id` is set; RLS policies then
-- restrict all reads/writes to that tenant, and column defaults auto-fill
-- tenant_id on insert. FORCE ROW LEVEL SECURITY makes this apply even to the
-- table owner (the application role).

-- ---------------------------------------------------------------------------
-- Tenants (organizations)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name        TEXT NOT NULL,
  plan        TEXT NOT NULL DEFAULT 'free',
  max_screens INTEGER,            -- NULL = unlimited
  active      BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- A fixed default tenant that existing rows are migrated into.
INSERT INTO tenants (id, name, plan, max_screens)
VALUES ('00000000-0000-0000-0000-0000000000d1'::uuid, 'Default', 'enterprise', NULL)
ON CONFLICT (id) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Add tenant_id to scoped tables, backfill, then lock down with RLS.
-- ---------------------------------------------------------------------------
DO $phase4$
DECLARE
  scoped_table TEXT;
  default_tenant CONSTANT UUID := '00000000-0000-0000-0000-0000000000d1';
BEGIN
  -- users and refresh_tokens carry tenant_id but are NOT RLS-scoped: login must
  -- resolve a user by email before any tenant context exists.
  FOR scoped_table IN
    SELECT unnest(ARRAY['users'])
  LOOP
    EXECUTE format('ALTER TABLE %I ADD COLUMN IF NOT EXISTS tenant_id UUID', scoped_table);
    EXECUTE format('UPDATE %I SET tenant_id = %L WHERE tenant_id IS NULL', scoped_table, default_tenant);
    EXECUTE format('ALTER TABLE %I ALTER COLUMN tenant_id SET DEFAULT %L', scoped_table, default_tenant);
  END LOOP;

  FOR scoped_table IN
    SELECT unnest(ARRAY[
      'monitors', 'contents', 'playlists', 'playlist_items',
      'layouts', 'schedules', 'telemetry', 'logs', 'screenshots'
    ])
  LOOP
    EXECUTE format('ALTER TABLE %I ADD COLUMN IF NOT EXISTS tenant_id UUID', scoped_table);
    EXECUTE format('UPDATE %I SET tenant_id = %L WHERE tenant_id IS NULL', scoped_table, default_tenant);
    EXECUTE format(
      'ALTER TABLE %I ALTER COLUMN tenant_id SET DEFAULT current_setting(''app.tenant_id'', true)::uuid',
      scoped_table
    );
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', scoped_table);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', scoped_table);
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %I', scoped_table);
    EXECUTE format(
      'CREATE POLICY tenant_isolation ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)::uuid) '
      || 'WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true)::uuid)',
      scoped_table
    );
    EXECUTE format('CREATE INDEX IF NOT EXISTS idx_%s_tenant ON %I (tenant_id)', scoped_table, scoped_table);
  END LOOP;
END
$phase4$;

-- ---------------------------------------------------------------------------
-- Proof-of-play events
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS play_events (
  id               BIGSERIAL PRIMARY KEY,
  tenant_id        UUID NOT NULL DEFAULT current_setting('app.tenant_id', true)::uuid,
  monitor_id       UUID REFERENCES monitors(id) ON DELETE SET NULL,
  content_id       UUID REFERENCES contents(id) ON DELETE SET NULL,
  duration_seconds NUMERIC,
  played_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE play_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE play_events FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON play_events;
CREATE POLICY tenant_isolation ON play_events
  USING (tenant_id = current_setting('app.tenant_id', true)::uuid)
  WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);
CREATE INDEX IF NOT EXISTS idx_play_events_tenant ON play_events (tenant_id, played_at DESC);
CREATE INDEX IF NOT EXISTS idx_play_events_content ON play_events (content_id);
