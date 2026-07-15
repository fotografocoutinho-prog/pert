# Roadmap

The platform is delivered in phases. Each phase compiles, is tested, and builds on
the last. Phase 1 is complete.

## ✅ Phase 1 — Foundation (done)

- Monorepo (npm workspaces): `shared`, `api`, `backoffice`, `player`
- PostgreSQL schema + forward-only migrations + seed
- Auth: JWT access + rotating refresh, bcrypt, RBAC (admin/operator/client)
- Security: Helmet, CORS, rate limiting, Zod validation, central error handling
- Media library: upload/download, checksum integrity, type validation
- Playlists: ordered items, duration, scale mode, transitions, duplicate, shuffle/loop
- Monitors: CRUD, remote commands, live presence
- Real-time WebSocket hub: hello / heartbeat / telemetry / commands / sync
- Dashboard aggregation
- Backoffice: login, dashboard, monitors, content, playlists (light/dark, responsive)
- Electron kiosk player: playlist playback, watchdog, offline reconnect/resync
- Swagger docs, Docker Compose, GitHub Actions CI, Raspberry Pi installer

## ✅ Phase 2 — Layouts, zones & scheduling (done)

- Layout editor (1/2/3/4 zones + custom) with independent per-zone playlists
- Zone kinds: video, image, clock, text, RSS/news, HTML, website, YouTube, weather
- Orientation (landscape/portrait) with dynamic rotation applied without restart
- Scale modes fit/fill/stretch per item; content never distorted unless "stretch"
- Scheduling: date range, time window, weekdays, priorities; highest-priority
  matching schedule overrides the monitor's default playlist
- Resolved player-state endpoint (`/api/player/:id/state`) merges layout + zones +
  scheduling into a single render payload
- Image probing (dimensions) + thumbnails via sharp; video duration/thumbnail via
  ffmpeg/ffprobe when available (graceful degradation otherwise)
- Token-in-query media auth so `<img>`/`<video>` and the player can load assets

## ✅ Phase 3 — Remote management & reliability (done)

- Remote screenshot capture (Electron `capturePage` → WebSocket → stored, served
  to the backoffice monitor dialog)
- Device health: CPU, RAM, SoC temperature, free disk and uptime gathered in the
  player's main process; history stored and charted per monitor
- Audit trail: `writeLog` helper wired into login and remote commands, player logs
  persisted, and a filterable Audit-log page
- Incremental/differential content sync: checksum-addressed offline cache in the
  player that only downloads changed assets, verifies integrity, and prunes stale
  files (keeps playing offline, resyncs on reconnect)
- OTA player updates with rollback: release registry + latest manifest endpoint, a
  player updater that compares versions, downloads and checksum-verifies the bundle,
  promotes it, and rolls back automatically after repeated boot failures
  (pure version/rollback logic unit-tested)

## ✅ Phase 4 — Scale & platform (done)

- Multi-tenant isolation via PostgreSQL Row-Level Security: every request runs
  on a connection scoped by `app.tenant_id`, with `FORCE ROW LEVEL SECURITY` and
  a dedicated non-superuser runtime role so isolation is enforced at the database
  (defense in depth — a forgotten `WHERE` cannot leak across tenants)
- Tenant provisioning (new organization + admin) and per-tenant onboarding
- Licensing: plan-based screen limits (free/pro/enterprise) enforced on create,
  with a live license/usage endpoint
- Pluggable WebSocket hub: in-memory for a single node, Redis pub/sub across a
  cluster so commands reach whichever node holds a device socket
- Pluggable storage driver: local filesystem by default, S3 (any S3-compatible
  endpoint) via `STORAGE_DRIVER=s3`; downloads stream through the driver
- Proof-of-play: players report play events over the socket; tenant-scoped
  aggregation endpoint and a Statistics page
- Prometheus `/metrics` endpoint for Grafana dashboards

## ✅ Phase 5 — Administration & integrations (done)

- User management: per-tenant users (list/create/update role & active/delete),
  admin-only, with self-delete protection and strict tenant isolation
- Telemetry sparklines in the monitor health dialog (dependency-free inline SVG)
- MQTT / Home Assistant bridge (env-gated): publishes monitor status and
  telemetry, and announces each screen via Home Assistant MQTT discovery

## Later — optional

- Billing/payments integration on top of the plan model
- CDN delivery in front of S3
- Deeper Grafana panels; programmatic advertising, AI/people-counting (opt-in)
