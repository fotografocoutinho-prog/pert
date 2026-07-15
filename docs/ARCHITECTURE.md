# Architecture

## Overview

```
┌──────────────┐      REST (HTTPS)      ┌──────────────┐
│  Backoffice  │ ─────────────────────▶ │              │
│  React + MUI │ ◀───────────────────── │     API      │
└──────────────┘                        │  Express +   │
                                        │  WebSocket   │
┌──────────────┐   WebSocket (/ws)      │              │
│    Player     │ ◀────── heartbeat ───▶ │   Node.js    │
│  Electron     │ ─────── telemetry ───▶ │              │
│  (Raspberry)  │ ◀────── commands ───── └──────┬───────┘
└──────────────┘                                │ pg
                                        ┌───────▼──────┐
                                        │  PostgreSQL  │
                                        └──────────────┘
```

## Packages

Every package is TypeScript and shares one type contract (`@signage/shared`), so a
change to an API response type surfaces as a compile error in the backoffice and
player. The WebSocket message shapes live in `shared/src/protocol/ws.ts` and are the
single source of truth for both ends of the socket.

### API (`api/`)

Layered per feature module (`modules/<feature>/{routes,controller,service}`):

- **routes** — wiring, auth guards, rate limiting, request validation (Zod)
- **controller** — HTTP concerns, parse/response shaping
- **service** — business logic and SQL

Cross-cutting pieces:

- `db/pool.ts` — pooled `pg` client + `withTransaction` helper
- `db/migrate.ts` — forward-only SQL migrations tracked in `schema_migrations`
- `middleware/` — `auth` (JWT), `error` (central handler), `validate`, `rateLimit`
- `ws/hub.ts` — in-memory registry of connected players (live presence)
- `ws/server.ts` — upgrades HTTP to WS, authenticates, routes player messages

### Authentication

- Short-lived **access token** (JWT, default 15 min) on every request.
- Rotating **refresh token** — stored only as a SHA-256 hash; each use revokes the
  old row and issues a new pair, so token theft is detectable and bounded.
- Passwords hashed with bcrypt.
- Role-based access control: `admin`, `operator`, `client`.

### Real-time channel

Players hold a persistent WebSocket. The server tracks live sockets in `hub`, so
"online/offline" reflects actual connectivity, not a stale timestamp. Players send
`hello` + periodic `heartbeat` (with telemetry); the server pushes `command` and
`sync` messages. Commands (restart, clear cache, screenshot, …) are dispatched to a
specific device and acknowledged.

### Storage

Media is written through a small `StorageDriver` interface (local filesystem today).
Files are content-addressed by SHA-256 checksum so the player can verify integrity
and skip re-downloading unchanged assets. An S3 driver can replace the local one
without touching callers.

### Player (`player/`)

- **main process** — creates a full-screen kiosk `BrowserWindow`, enables hardware
  video decoding, holds a single-instance lock, and reloads the renderer if it
  crashes (watchdog).
- **preload** — a minimal, context-isolated bridge (`window.signage`).
- **renderer** — connects the WebSocket, plays the assigned playlist (images honour a
  duration, videos play to the end) with cross-fade transitions, and reconnects/
  resyncs automatically when the network returns.

## Data model

`users`, `refresh_tokens`, `monitors`, `contents`, `playlists`, `playlist_items`,
`layouts`, `schedules`, `telemetry`, `logs`, `settings`. See
`database/migrations/001_init.sql`.

## Scaling notes

- The API is stateless except for the WS `hub`. To run multiple API instances, the
  hub moves to Redis pub/sub (planned) so a command reaches whichever node holds the
  device socket.
- PostgreSQL is the single source of truth; telemetry is append-only and can be
  partitioned/rolled up for thousands of devices.
- Static content and the backoffice bundle are served by nginx / a CDN.
