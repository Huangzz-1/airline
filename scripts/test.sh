#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Explicitly isolated test databases on all three MySQL servers. Never drop application data.
for node in db1 db2 db3; do
  docker compose exec -T "$node" sh -s < scripts/prepare-test-db.sh
done
docker compose run --rm -e APP_ENV=testing -e DB_DATABASE=airline_test -e DB_USERNAME=airline_test app php artisan migrate --force
docker compose run --rm -e APP_ENV=testing -e DB_DATABASE=airline_test -e DB_USERNAME=airline_test app php artisan test "$@"
