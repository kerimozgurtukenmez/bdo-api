<?php
// ─────────────────────────────────────────────────────────────────────────────
// Downloads the icons of every recipe and every item used in a recipe into
// public/icons/, so the site does not depend on hotlinking the source site.
//
//   php import/download_icons.php
//
// Icons already on disk are skipped, so an interrupted run can simply be
// started again. Run it after import.php when new recipes arrive.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";

set_time_limit(0);

const REQUEST_DELAY = 400;  // ms between downloads
const MAX_RETRIES   = 3;
const USER_AGENT    = "bdo-craft-calculator (icon cache, permitted by bdocodex)";

$icons = config("icons");
$start = microtime(true);

$paths = db()->query("
    SELECT DISTINCT i.icon FROM items i
    WHERE i.icon IS NOT NULL
      AND i.id IN (SELECT item_id FROM recipe_inputs UNION SELECT item_id FROM recipe_outputs)
    UNION
    SELECT DISTINCT icon FROM recipes WHERE icon IS NOT NULL
")->fetchAll(PDO::FETCH_COLUMN);

$missing = array_values(array_filter($paths, fn($p) => !is_file($icons["dir"] . "/" . $p)));

echo "=== Icons: " . count($paths) . " needed, " . count($missing) . " to download ===\n\n";

function download(string $url): ?string
{
    for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_REFERER        => config("icons")["source"] . "/",  // the source blocks other referers
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

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

$saved = $failed = 0;
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

    $body = download($icons["source"] . "/" . $path);
    if ($body === null) {
        echo "  failed: $path\n";
        $failed++;
        continue;
    }

    $file = $icons["dir"] . "/" . $path;
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    file_put_contents("$file.tmp", $body);
    rename("$file.tmp", $file);
    $saved++;

    if (($n + 1) % 250 === 0) {
        printf("  %d/%d\n", $n + 1, count($missing));
    }
}

printf("\n=== Done in %.0fs: %d downloaded, %d failed ===\n", microtime(true) - $start, $saved, $failed);
exit($failed > 0 ? 1 : 0);
