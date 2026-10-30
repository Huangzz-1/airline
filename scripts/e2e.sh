#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
./scripts/test.sh --filter=test_three_nodes_are_independent_and_own_the_correct_tables
compose=(docker compose -f compose.yaml -f compose.test.yaml)
cleanup() { "${compose[@]}" rm -sf e2e-mail-worker e2e-worker e2e; }
trap cleanup EXIT
"${compose[@]}" up -d e2e e2e-worker e2e-mail-worker
for attempt in {1..30}; do
  if curl --fail --silent http://127.0.0.1:8001/health/ready >/dev/null; then break; fi
  sleep 1
done
curl --fail --silent http://127.0.0.1:8001/health/ready
npm run test:e2e -- "$@"
