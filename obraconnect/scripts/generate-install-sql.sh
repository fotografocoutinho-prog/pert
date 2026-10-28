#!/usr/bin/env bash
#
# Regenera database/sql/obraconnect-install.sql a partir das migrações e seeders.
# Precisa de uma ligação MySQL/MariaDB configurada no .env (nada é escrito na BD:
# as migrações correm em modo "pretend").
#
set -euo pipefail
cd "$(dirname "$0")/.."
php artisan obraconnect:install-sql
