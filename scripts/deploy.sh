#!/usr/bin/env bash
# Build and run the full stack via Docker Compose, then seed the admin user.
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "Creating .env from .env.example (edit secrets before production use)"
  cp .env.example .env
fi

echo "==> Building and starting containers"
docker compose up -d --build

echo "==> Waiting for the API to become healthy"
for i in $(seq 1 60); do
  if curl -sf http://localhost:4000/health >/dev/null 2>&1; then
    echo "API is up"
    break
  fi
  sleep 2
done

echo "==> Seeding the default admin user"
docker compose exec -T api node api/dist/db/seed.js

echo ""
echo "Done."
echo "  Backoffice : http://localhost:8080"
echo "  API docs   : http://localhost:4000/docs"
echo "  Login      : admin@signage.local / admin123  (change this!)"
