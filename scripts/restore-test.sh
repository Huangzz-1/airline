#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
backup_dir="${1:?Provide a backup directory created by scripts/backup.sh}"
for node in db1 db2 db3; do
 test -f "$backup_dir/$node.sql.gz"
 gzip -t "$backup_dir/$node.sql.gz"
done
# This command never selects or resets the airline application database.
for node in db1 db2 db3; do
 docker compose exec -T "$node" sh -s < scripts/prepare-test-db.sh
 gzip -dc "$backup_dir/$node.sql.gz" | docker compose exec -T "$node" sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u airline_test airline_test'
done
printf 'Backup restored only into airline_test on three nodes. Run no tests concurrently.\n'
