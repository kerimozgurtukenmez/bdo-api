<?php
// ─────────────────────────────────────────────────────────────────────────────
// Downloads the icons of every recipe and every item used in a recipe into
// public/icons/, so the site does not depend on hotlinking the source site.
//
//   php bin/download_icons.php
//
// Icons already on disk are skipped, so an interrupted run can simply be
// started again. Run it after import.php when new recipes arrive.
//
// The source rate-limits per IP, so downloads are sequential. Cooking and
// alchemy icons come first: they are the ones most pages show.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";

set_time_limit(0);

const REQUEST_DELAY       = 200;  // ms between downloads
const MAX_RETRIES         = 3;
const MAX_FAILED_IN_A_ROW = 10;   // the source or the connection is down: stop
const PROGRESS_EVERY      = 25;
const USER_AGENT          = "bdo-craft-calculator (icon cache, permitted by bdocodex)";

$icons = config("icons");
$start = microtime(true);

// Every icon we need, cooking/alchemy first, then processing, then recipe icons
$paths = db()->query("
    SELECT i.icon, MIN(CASE WHEN t.recipe_source = 'processing' THEN 1 ELSE 0 END) AS priority
    FROM (
        SELECT item_id, recipe_source FROM recipe_inputs
        UNION
        SELECT item_id, recipe_source FROM recipe_outputs
    ) t
    JOIN items i ON i.id = t.item_id
    WHERE i.icon IS NOT NULL
    GROUP BY i.icon
    UNION
    SELECT icon, 2 FROM recipes WHERE icon IS NOT NULL
    ORDER BY priority, icon
")->fetchAll(PDO::FETCH_COLUMN);
$paths = array_values(array_unique($paths));

$missing = array_values(array_filter($paths, fn($p) => !is_file($icons["dir"] . "/" . $p)));

echo "=== Icons: " . count($paths) . " needed, " . count($missing) . " to download ===\n\n";

// One connection for the whole run (keep-alive): no TLS handshake per icon
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => USER_AGENT,
    CURLOPT_REFERER        => $icons["source"] . "/",  // the source blocks other referers
]);

function download(CurlHandle $curl, string $url): ?string
{
    for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
        curl_setopt($curl, CURLOPT_URL, $url);
        $body   = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $type   = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

        if ($status === 200 && str_starts_with($type, "image/") && is_string($body) && $body !== "") {
            return $body;
        }
        if ($status === 404) {
            return null;
        }
        sleep(5 * $attempt);
    }
    return null;
}

function progress(int $done, int $total, float $start): void
{
    $elapsed = microtime(true) - $start;
    $left    = $done > 0 ? $elapsed / $done * ($total - $done) : 0;
    printf("  %d/%d (%d%%) · %s elapsed · ~%s left\n", $done, $total, $done * 100 / $total,
        duration($elapsed), duration($left));
}

function duration(float $seconds): string
{
    return $seconds >= 3600
        ? sprintf("%dh %02dm", $seconds / 3600, (int)($seconds / 60) % 60)
        : sprintf("%dm %02ds", $seconds / 60, (int)$seconds % 60);
}

$saved = $failed = $failedInARow = 0;
foreach ($missing as $n => $path) {
    if ($n > 0) {
        usleep(REQUEST_DELAY * 1000);
    }

    // Paths come from our own database, but never let one escape the icons folder
    if (str_contains($path, "..") || !preg_match('#^[\w/.-]+\.(webp|png|jpg)$#', $path)) {
        echo "  skipped suspicious path: $path\n";
        $failed++;
        continue;
    }

    $body = download($curl, $icons["source"] . "/" . $path);
    if ($body === null) {
        echo "  failed: $path\n";
        $failed++;
        if (++$failedInARow >= MAX_FAILED_IN_A_ROW) {
            echo "\nToo many failures in a row; check the connection and run this script again.\n";
            break;
        }
        continue;
    }
    $failedInARow = 0;

    $file = $icons["dir"] . "/" . $path;
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    file_put_contents("$file.tmp", $body);
    rename("$file.tmp", $file);
    $saved++;

    if (($n + 1) % PROGRESS_EVERY === 0) {
        progress($n + 1, count($missing), $start);
    }
}

printf("\n=== Done in %s: %d downloaded, %d failed (run again to retry failures) ===\n",
    duration(microtime(true) - $start), $saved, $failed);
exit($failed > 0 ? 1 : 0);
