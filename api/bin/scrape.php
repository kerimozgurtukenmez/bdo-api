<?php
// ─────────────────────────────────────────────────────────────────────────────
// Downloads items and recipes from bdocodex.com (with the site owner's
// permission) and writes the JSON files that import.php reads.
//
//   php bin/scrape.php              use cached responses younger than 24h
//   php bin/scrape.php --refresh    always download again
//
// Four requests in total: the item list and the cooking, alchemy and
// processing recipe lists (the same data the bdocodex list pages load).
// Raw responses are cached in data/cache/. Nothing is written unless every
// response parses and looks complete, and a summary of what changed since the
// previous files is printed.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/bdocodex.php";

set_time_limit(0);
ini_set("memory_limit", "3G");

const CACHE_DIR     = DATA_DIR . "/cache";
const CACHE_MAX_AGE = 24 * 3600;
const REQUEST_DELAY = 3;  // seconds between requests
const USER_AGENT    = "bdo-craft-calculator (data import, permitted by bdocodex)";

// name => [query, page that loads it, minimum plausible row count]
const DATASETS = [
    "items"      => ["a=items",                    "/items/",            60000],
    "cooking"    => ["a=recipes&type=culinary&id=1", "/recipes/culinary/", 250],
    "alchemy"    => ["a=recipes&type=alchemy&id=1",  "/recipes/alchemy/",  150],
    "processing" => ["a=mrecipes&id=1",             "/mrecipes/",         5000],
];

$refresh = in_array("--refresh", $argv, true);

echo "=== bdocodex scrape ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Download
// ─────────────────────────────────────────────────────────────────────────────

function fetchRows(string $name, bool $refresh): array
{
    [$query, $page, $minRows] = DATASETS[$name];
    $cacheFile = CACHE_DIR . "/$name.json";

    if (!$refresh && is_file($cacheFile) && time() - filemtime($cacheFile) < CACHE_MAX_AGE) {
        echo "  $name: cached (" . round((time() - filemtime($cacheFile)) / 3600, 1) . "h old)\n";
        $body = file_get_contents($cacheFile);
    } else {
        static $requests = 0;
        if ($requests++ > 0) {
            sleep(REQUEST_DELAY);
        }

        $url = BDOCODEX_URL . "/query.php?$query&l=" . BDOCODEX_LANG;
        echo "  $name: downloading $url\n";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_REFERER        => BDOCODEX_URL . "/" . BDOCODEX_LANG . $page,
            CURLOPT_ENCODING       => "",  // accept gzip
        ]);
        $body   = (string)curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($status !== 200) {
            throw new RuntimeException("$name: HTTP $status $error");
        }
    }

    $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', "", $body), true);  // responses start with a BOM
    $rows = $data["aaData"] ?? null;
    if (!is_array($rows) || count($rows) < $minRows) {
        throw new RuntimeException("$name: unexpected response (" . (is_array($rows) ? count($rows) . " rows" : "no aaData") . ")");
    }

    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    file_put_contents($cacheFile, $body);

    return $rows;
}

// ─────────────────────────────────────────────────────────────────────────────
// Run
// ─────────────────────────────────────────────────────────────────────────────

echo "Downloading...\n";
$parsed = [];
foreach (array_keys(DATASETS) as $name) {
    $rows = fetchRows($name, $refresh);
    $parsed[$name] = $name === "items" ? codex_parse_items($rows) : codex_parse_recipes($rows, $name);
}

// What changed compared to the files we are about to replace
function changes(string $file, array $new, callable $fingerprint): string
{
    if (!is_file($file)) {
        return count($new) . " (new file)";
    }

    $old = [];
    foreach (json_decode(file_get_contents($file), true) ?: [] as $row) {
        $old[$row["id"]] = $fingerprint($row);
    }

    $added = $changed = 0;
    $seen  = [];
    foreach ($new as $row) {
        $seen[$row["id"]] = true;
        if (!isset($old[$row["id"]])) {
            $added++;
        } elseif ($old[$row["id"]] !== $fingerprint($row)) {
            $changed++;
        }
    }
    $removed = count(array_diff_key($old, $seen));

    return count($new) . " ($added added, $removed removed, $changed changed)";
}

$files = [
    "items"      => "items.json",
    "cooking"    => "recipes_cooking.json",
    "alchemy"    => "recipes_alchemy.json",
    "processing" => "recipes_processing.json",
];

echo "\nWriting...\n";
foreach ($files as $name => $file) {
    $path = DATA_DIR . "/$file";
    $fingerprint = $name === "items"
        ? fn($r) => [$r["name"], $r["grade"], $r["icon"]]
        : fn($r) => [$r["name"], $r["skill_level"], $r["ingredients"], $r["output"], $r["ingredient_ids"]];

    $summary = changes($path, $parsed[$name], $fingerprint);

    // Write next to the target first so a failure never leaves half a file
    $json = json_encode($parsed[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents("$path.tmp", $json);
    rename("$path.tmp", $path);

    printf("  %-24s %s\n", $file, $summary);
}

echo "\n=== Done. Run import.php to load the new data. ===\n";
