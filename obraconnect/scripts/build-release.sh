#!/usr/bin/env bash
#
# Gera o ZIP instalável do ObraConnect para cPanel:
#   - frontend compilado (public/build)
#   - vendor/ de produção (sem dependências de desenvolvimento)
#   - SQL de instalação para o phpMyAdmin (database/sql/)
#
# Uso:  scripts/build-release.sh [pasta-de-saída]     (por omissão: dist/)
# Requer: PHP 8.3+, Composer, Node 20+, zip, rsync.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(mkdir -p "${1:-$ROOT/dist}" && cd "${1:-$ROOT/dist}" && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
WORK="$(mktemp -d)"
STAGE="$WORK/obraconnect"
trap 'rm -rf "$WORK"' EXIT

echo "→ ObraConnect $VERSION"

cd "$ROOT"
echo "→ A compilar o frontend"
npm ci --no-audit --no-fund
npm run typecheck
npm run build

echo "→ A copiar ficheiros"
rsync -a --exclude-from="$ROOT/scripts/release-exclude.txt" "$ROOT/" "$STAGE/"

echo "→ A instalar dependências PHP de produção"
cd "$STAGE"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

# Pastas de runtime vazias mas presentes (com permissões de escrita no servidor).
for dir in storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
           storage/framework/views storage/logs bootstrap/cache; do
    mkdir -p "$dir"
done
rm -f bootstrap/cache/*.php

test -f public/build/manifest.json || { echo "ERRO: public/build/manifest.json em falta"; exit 1; }
test -f database/sql/obraconnect-install.sql || { echo "ERRO: database/sql/obraconnect-install.sql em falta"; exit 1; }

echo "→ A criar o ZIP"
ZIP="$OUT/obraconnect-$VERSION.zip"
rm -f "$ZIP"
cd "$WORK"
zip -qr -9 "$ZIP" obraconnect
(cd "$OUT" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")

echo "✓ $ZIP ($(du -h "$ZIP" | cut -f1))"
echo "  SHA-256: $(cut -d' ' -f1 "$ZIP.sha256")"
