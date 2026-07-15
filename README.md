# Digital Signage Platform

A professional, self-hosted digital signage system — a from-scratch alternative to
Yodeck / Screenly / Xibo. Manage screens, upload media, build playlists and layouts,
and drive Raspberry Pi (or Windows/Linux) players in real time.

> **Status:** Phase 1 (foundation) complete and verified end-to-end — authentication,
> database, media library, playlists, dashboard, the real-time device channel, and a
> working Electron kiosk player. Later phases add layouts/zones, scheduling, widgets,
> remote screenshots, and OTA updates. See [docs/ROADMAP.md](docs/ROADMAP.md).

## Monorepo layout

| Path          | Description                                                        |
| ------------- | ------------------------------------------------------------------ |
| `shared/`     | TypeScript types + WebSocket protocol shared by every package      |
| `database/`   | SQL migrations and seed data                                       |
| `api/`        | Node + Express REST API, JWT auth, WebSocket hub, Swagger docs     |
| `backoffice/` | React + TypeScript + Material UI management console                |
| `player/`     | Electron kiosk player (Chromium, Raspberry Pi ready)              |

## Tech stack

- **Backend:** Node.js, Express, `ws` (WebSocket), JWT (access + rotating refresh), `pg`
- **Database:** PostgreSQL 16
- **Frontend:** React 18, TypeScript, Material UI, TanStack Query, Vite
- **Player:** Electron + Chromium kiosk mode
- **Infra:** Docker, Docker Compose, GitHub Actions CI

## Quick start (Docker)

```bash
cp .env.example .env        # edit the secrets
./scripts/deploy.sh         # build, start, migrate, seed
```

- Backoffice: <http://localhost:8080>
- API + Swagger: <http://localhost:4000/docs>
- Default login: `admin@signage.local` / `admin123` **(change immediately)**

## Local development

```bash
npm install
npm run build:shared        # shared types must be built first

# Terminal 1 — Postgres (or use your own)
docker compose up -d db

# Terminal 2 — API
npm run migrate && npm run seed
npm run dev:api             # http://localhost:4000

# Terminal 3 — Backoffice
npm run dev:backoffice      # http://localhost:5173

# Player (needs a display)
npm run dev:player
```

### Environment variables

Copy `.env.example` to `.env`. Key values:

| Variable                              | Purpose                            |
| ------------------------------------- | ---------------------------------- |
| `POSTGRES_*`                          | Database connection                |
| `JWT_ACCESS_SECRET` / `_REFRESH_*`    | Token signing secrets              |
| `STORAGE_DIR`                         | Local media storage root           |
| `CORS_ORIGIN`                         | Allowed backoffice origin          |

## Raspberry Pi player

Provision a Pi (3/4/5, Raspberry Pi OS) as a boot-to-kiosk player with hardware
video decoding and an auto-restart watchdog:

```bash
sudo API_URL=http://<server>:4000 MONITOR_ID=<uuid> TOKEN=<jwt> ./scripts/install-pi.sh
```

Create the monitor in the backoffice first to obtain its `MONITOR_ID`.

## API surface (Phase 1)

| Method | Path                          | Description                       |
| ------ | ----------------------------- | --------------------------------- |
| POST   | `/api/auth/login`             | Authenticate, receive tokens      |
| POST   | `/api/auth/refresh`           | Rotate refresh token              |
| GET    | `/api/auth/me`                | Current user                      |
| GET    | `/api/dashboard/stats`        | Aggregate stats                   |
| CRUD   | `/api/monitors`               | Manage screens                    |
| POST   | `/api/monitors/:id/command`   | Send a remote command             |
| CRUD   | `/api/contents`               | Media library (upload/download)   |
| CRUD   | `/api/playlists`              | Playlists + ordered items         |
| WS     | `/ws?token=&monitorId=`       | Real-time player channel          |

Full interactive docs at `/docs`.

## Testing & CI

```bash
npm run typecheck    # all workspaces
npm test             # unit tests
npm run build        # compile everything
```

GitHub Actions runs the same steps against a PostgreSQL service on every push/PR.

## License

MIT
