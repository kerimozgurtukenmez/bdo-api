<?php
// ─────────────────────────────────────────────────────────────────────────────
// Brings the database up to date with database/schema.sql: creates missing
// tables and adds new columns. Safe to run any time; data is kept.
//
//   php bin/migrate.php
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/importer.php";

apply_schema(db());
echo "Database schema is up to date.\n";
