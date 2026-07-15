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

## Phase 3 — Remote management & reliability

- Remote screenshot capture and streaming
- Device health: CPU, RAM, temperature, free disk, network state, uptime
- Logs viewer + audit trail UI
- Incremental/differential content sync with checksums and local cache
- OTA player updates with rollback

## Phase 4 — Scale & platform

- Redis-backed WS hub for horizontal scaling (thousands of devices)
- Multi-tenant (clients / companies), licensing, billing
- S3 storage driver, CDN delivery
- Statistics & proof-of-play reporting
- Integrations: MQTT, Home Assistant, Grafana
- Optional: programmatic advertising, AI, people counting
