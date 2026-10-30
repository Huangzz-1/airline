#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
backup_dir="backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$backup_dir"
for node in db1 db2 db3; do
 docker compose exec -T "$node" sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u airline --single-transaction --no-tablespaces --skip-add-locks --set-gtid-purged=OFF airline' | gzip > "$backup_dir/$node.sql.gz"
 gzip -t "$backup_dir/$node.sql.gz"
done
printf 'Three-node logical backup saved in %s\n' "$backup_dir"
printf 'This is not a globally atomic snapshot. Keep APP_KEY separately; do not publish backup files.\n'
