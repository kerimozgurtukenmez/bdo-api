<?php
// ─────────────────────────────────────────────────────────────────────────────
// Downloads items, recipes, mastery tables and the Imperial delivery boxes'
// item pages from bdocodex.com (with the site owner's permission) and writes
// the JSON files in data/ that import.php reads.
//
//   php bin/scrape.php              use cached responses younger than 24h
//   php bin/scrape.php --refresh    always download again
//
// About 20 requests: the item list, the cooking, alchemy and processing
// recipe lists, two mastery tables (the data the bdocodex list pages load),
// and one item page per Imperial box for its price. Raw responses are cached
// in data/cache/. Nothing is written unless every response parses and looks
// complete, and a summary of what changed is printed.
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
    "items"           => ["a=items",                      "/items/",            60000],
    "cooking"         => ["a=recipes&type=culinary&id=1", "/recipes/culinary/", 250],
    "alchemy"         => ["a=recipes&type=alchemy&id=1",  "/recipes/alchemy/",  150],
    "processing"      => ["a=mrecipes&id=1",              "/mrecipes/",         5000],
    "mastery_cooking" => ["a=cookingmastery",             "/cookingmastery/",   50],
    "mastery_alchemy" => ["a=alchemymastery",             "/alchemymastery/",   50],
    "mastery_processing" => ["a=processingmastery",       "/processingmastery/", 50],
];

// Recipe categories whose products are delivered for silver
const IMPERIAL_CATEGORIES = ["Imperial Cuisine", "Imperial Alchemy"];

$refresh = in_array("--refresh", $argv, true);

echo "=== bdocodex scrape ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Download
// ─────────────────────────────────────────────────────────────────────────────

// The response for a path on bdocodex, from the cache when it is fresh enough
function downloadCodex(string $path, string $referer, string $cacheName, bool $refresh): string
{
    static $requests = 0;

    $cacheFile = CACHE_DIR . "/$cacheName";
    if (!$refresh && is_file($cacheFile) && time() - filemtime($cacheFile) < CACHE_MAX_AGE) {
        return file_get_contents($cacheFile);
    }

    if ($requests++ > 0) {
        sleep(REQUEST_DELAY);
    }

    $ch = curl_init(BDOCODEX_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_REFERER        => BDOCODEX_URL . "/" . BDOCODEX_LANG . $referer,
        CURLOPT_ENCODING       => "",  // accept gzip
    ]);
    $body   = (string)curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException("$path: HTTP $status $error");
    }

    if (!is_dir(dirname($cacheFile))) {
        mkdir(dirname($cacheFile), 0755, true);
    }
    file_put_contents($cacheFile, $body);

    return $body;
}

function fetchRows(string $name, bool $refresh): array
{
    [$query, $page, $minRows] = DATASETS[$name];
    echo "  $name\n";

    $body = downloadCodex("/query.php?$query&l=" . BDOCODEX_LANG, $page, "$name.json", $refresh);
    $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', "", $body), true);  // responses start with a BOM
    $rows = $data["aaData"] ?? null;
    if (!is_array($rows) || count($rows) < $minRows) {
        throw new RuntimeException("$name: unexpected response (" . (is_array($rows) ? count($rows) . " rows" : "no aaData") . ")");
    }

    return $rows;
}

// ─────────────────────────────────────────────────────────────────────────────
// Run
// ─────────────────────────────────────────────────────────────────────────────

echo "Downloading lists...\n";
$parsed = [];
foreach (array_keys(DATASETS) as $name) {
    $rows = fetchRows($name, $refresh);
    $parsed[$name] = match (true) {
        $name === "items"                  => codex_parse_items($rows),
        $name === "mastery_processing"     => codex_parse_processing_mastery($rows),
        str_starts_with($name, "mastery_") => codex_parse_mastery($rows, substr($name, 8)),
        default                            => codex_parse_recipes($rows, $name),
    };
}

// Imperial delivery boxes: products of the Imperial recipes named "... Box"
$itemNames = array_column($parsed["items"], "name", "id");
$boxIds = [];
foreach ($parsed["processing"] as $recipe) {
    if (in_array($recipe["category"], IMPERIAL_CATEGORIES, true)) {
        foreach ($recipe["output"] as $out) {
            if (str_contains($itemNames[$out["item_id"]] ?? "", "Box")) {
                $boxIds[$out["item_id"]] = true;
            }
        }
    }
}
ksort($boxIds);

echo "\nDownloading " . count($boxIds) . " Imperial box pages...\n";
$itemPages = [];
$withoutPrice = [];
foreach (array_keys($boxIds) as $id) {
    $html   = downloadCodex("/" . BDOCODEX_LANG . "/item/$id/", "/items/", "pages/item_$id.html", $refresh);
    $prices = codex_item_page_prices($html);
    if ($prices["buy_price"] === null) {
        $withoutPrice[] = "$id {$itemNames[$id]}";
        continue;
    }
    $itemPages[$id] = ["id" => $id] + $prices;
    printf("  %-34s %s\n", $itemNames[$id], number_format($prices["buy_price"]));
}
// One page without a price is an odd item; most of them means the page layout changed
if (count($withoutPrice) > count($boxIds) / 2) {
    throw new RuntimeException("No buy price on most item pages: has the bdocodex layout changed?");
}
foreach ($withoutPrice as $item) {
    echo "  (no price on the page of $item, skipped)\n";
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

// Write next to the target first so a failure never leaves half a file
function writeJson(string $file, array $data): void
{
    $path = DATA_DIR . "/$file";
    file_put_contents("$path.tmp", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);
}

$files = [
    "items"      => "items.json",
    "cooking"    => "recipes_cooking.json",
    "alchemy"    => "recipes_alchemy.json",
    "processing" => "recipes_processing.json",
];

echo "\nWriting...\n";
foreach ($files as $name => $file) {
    $fingerprint = $name === "items"
        ? fn($r) => [$r["name"], $r["grade"], $r["icon"]]
        : fn($r) => [$r["name"], $r["skill_level"], $r["ingredients"], $r["output"], $r["ingredient_ids"]];

    $summary = changes(DATA_DIR . "/$file", $parsed[$name], $fingerprint);
    writeJson($file, $parsed[$name]);
    printf("  %-24s %s\n", $file, $summary);
}

writeJson("mastery.json", ["cooking" => $parsed["mastery_cooking"], "alchemy" => $parsed["mastery_alchemy"], "processing" => $parsed["mastery_processing"]]);
printf("  %-24s %d cooking + %d alchemy + %d processing rows\n", "mastery.json",
    count($parsed["mastery_cooking"]), count($parsed["mastery_alchemy"]), count($parsed["mastery_processing"]));

$summary = changes(DATA_DIR . "/item_pages.json", array_values($itemPages), fn($r) => [$r["buy_price"], $r["sell_price"]]);
writeJson("item_pages.json", array_values($itemPages));
printf("  %-24s %s\n", "item_pages.json", $summary);

echo "\n=== Done. Run import.php to load the new data. ===\n";
