<?php
// ─────────────────────────────────────────────────────────────────────────────
// Checks the imported data for signs of a broken scrape or import and prints
// a short report: counts with a few examples. import.php runs it at the end.
//
//   php bin/check_data.php        exits with 1 when a problem was found
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/CraftCalculator.php";
require __DIR__ . "/../src/profits.php";
require __DIR__ . "/../src/checks.php";

$checks = data_checks(db());
echo "Data checks\n" . data_check_report($checks);
exit(array_filter($checks, fn($c) => $c["level"] === "problem" && $c["count"] > 0) ? 1 : 0);
