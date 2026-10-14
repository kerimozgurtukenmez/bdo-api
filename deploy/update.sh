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

# New import rules apply to the data already downloaded (about 10 s; prices are kept)
if [ -f api/data/items.json ]; then
    echo "== Importing the game data again"
    php api/bin/import.php
fi

echo "== Checking"
php api/bin/doctor.php

UNIT=/etc/systemd/system/bdo-worker@.service
if [ -f "$UNIT" ]; then
    # A changed service file only takes effect once systemd reloads it
    if ! cmp -s deploy/bdo-worker@.service "$UNIT"; then
        echo "== Updating the worker's service file"
        sudo cp deploy/bdo-worker@.service "$UNIT"
        sudo systemctl daemon-reload
    fi
    echo "== Restarting the worker"
    sudo systemctl restart "bdo-worker@$USER"
fi
echo "Done."
