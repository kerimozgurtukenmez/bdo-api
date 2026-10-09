#!/usr/bin/env bash
# Updates a server to the latest version on GitHub and restarts the worker.
#   ./deploy/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== Pulling the latest version"
git pull --ff-only

echo "== Building the site"
(cd site && npm ci --no-audit --no-fund && npm run build)

echo "== Updating the database schema"
php api/bin/migrate.php

echo "== Checking"
php api/bin/doctor.php

if systemctl list-unit-files 'bdo-worker@.service' >/dev/null 2>&1; then
    echo "== Restarting the worker"
    sudo systemctl restart "bdo-worker@$USER"
fi
echo "Done."
