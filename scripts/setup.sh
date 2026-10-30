#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 scripts/configure.py
docker compose build app
# Mount the proxy trust bundle only for the Composer operation, when supplied by managed runtime.
trust_args=()
if [[ -n "${CODEX_PROXY_CERT:-}" ]]; then
  trust_args=(--volume "$CODEX_PROXY_CERT:/run/proxy-ca.pem:ro" -e COMPOSER_CAFILE=/run/proxy-ca.pem -e GIT_SSL_CAINFO=/run/proxy-ca.pem)
fi
docker compose run --rm --no-deps "${trust_args[@]}" -e COMPOSER_HOME=/tmp/composer app composer install --prefer-source --no-interaction
npm ci --no-audit --no-fund
npm run assets
docker compose up -d --wait db1 db2 db3
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --force
docker compose run --rm app php artisan airline:bootstrap-local
docker compose up -d mailpit
docker compose up -d --force-recreate app worker mail-worker
