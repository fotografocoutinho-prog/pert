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

## Phase 2 — Layouts, zones & scheduling

- Layout editor (1/2/3/4 zones) with independent per-zone playlists
- Zone kinds: video, image, clock, text, RSS, HTML, website, YouTube, weather
- Orientation (landscape/portrait) + dynamic rotation without restart
- Resolution handling: auto-scale, 720p→8K, custom, aspect ratios, fit/fill/stretch
- Scheduling: dates, times, weekdays, campaigns, priorities
- Thumbnail generation + media probing (duration/resolution) via ffmpeg/sharp

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
