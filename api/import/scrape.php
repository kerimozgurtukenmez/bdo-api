<?php
// ─────────────────────────────────────────────────────────────────────────────
// Downloads items and recipes from bdocodex.com (with the site owner's
// permission) and writes the JSON files that import.php reads.
//
//   php import/scrape.php              use cached responses younger than 24h
//   php import/scrape.php --refresh    always download again
//
// Four requests in total: the item list and the cooking, alchemy and
// processing recipe lists (the same data the bdocodex list pages load).
// Raw responses are cached in import/cache/. Nothing is written unless every
// response parses and looks complete, and a summary of what changed since the
// previous files is printed.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

set_time_limit(0);
ini_set("memory_limit", "3G");

const BASE_URL      = "https://bdocodex.com";
const LANG          = "us";
const CACHE_DIR     = __DIR__ . "/cache";
const CACHE_MAX_AGE = 24 * 3600;
const REQUEST_DELAY = 3;  // seconds between requests
const USER_AGENT    = "bdo-craft-calculator (data import, permitted by bdocodex)";

const GRADE_NAMES = [0 => "White", 1 => "Green", 2 => "Blue", 3 => "Gold", 4 => "Orange", 5 => "Unknown"];

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

        $url = BASE_URL . "/query.php?$query&l=" . LANG;
        echo "  $name: downloading $url\n";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_REFERER        => BASE_URL . "/" . LANG . $page,
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
// Parse — the list rows mix plain values with HTML snippets
// ─────────────────────────────────────────────────────────────────────────────

function text(string $html): string
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
}

function iconUrl(string $html): ?string
{
    return preg_match('#\[img src="([^"]+)"#', $html, $m) ? BASE_URL . $m[1] : null;
}

function gradeFromClass(string $html): int
{
    return preg_match('#item_grade_(\d)#', $html, $m) ? (int)$m[1] : 0;
}

// Ingredient or product cell: one icon block per item with its quantity
function itemCell(string $html): array
{
    preg_match_all('#<div class="iconset_wrapper_medium inlinediv">(.*?)</a></div>#s', $html, $blocks);

    $items = [];
    foreach ($blocks[1] as $block) {
        if (!preg_match('#/item/(\d+)/#', $block, $id)) {
            continue;
        }
        preg_match('#quantity_small nowrap">\s*([\d~]+)\s*<#', $block, $q);
        $qty = explode("~", $q[1] ?? "1");

        $item = [
            "item_id" => (int)$id[1],
            "qty_min" => (int)$qty[0],
            "qty_max" => (int)($qty[1] ?? $qty[0]),
        ];
        if (str_contains($block, 'data-tiptype="recipekey"')) {
            $item["is_key"] = true;
        }
        $items[] = $item;
    }

    return $items;
}

function parseItems(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        // [id, icon html, name html, ?, ?, grade, ?]
        $id    = (int)$row[0];
        $grade = (int)$row[5];
        $items[$id] = [
            "id"         => $id,
            "name"       => text($row[2]),
            "grade"      => $grade,
            "grade_name" => GRADE_NAMES[$grade] ?? "Unknown",
            "icon"       => iconUrl($row[1]),
            "link"       => BASE_URL . "/" . LANG . "/item/$id/",
        ];
    }

    ksort($items);
    return array_values($items);
}

function parseRecipes(array $rows, string $source): array
{
    $path = $source === "processing" ? "mrecipe" : "recipe";

    $recipes = [];
    foreach ($rows as $row) {
        // [id, icon html, name html, category, {display, sort_value}, exp,
        //  ingredients html, weight, products html, ingredient_ids, ...]
        $id    = (int)$row[0];
        $exp   = preg_replace('/\D/', "", (string)$row[5]);  // "1'000" → 1000
        $grade = gradeFromClass($row[2]);

        $recipes[] = [
            "id"             => $id,
            "name"           => text($row[2]),
            "grade"          => $grade,
            "grade_name"     => GRADE_NAMES[$grade] ?? "Unknown",
            "icon"           => iconUrl($row[1]),
            "link"           => BASE_URL . "/" . LANG . "/$path/$id/",
            "category"       => trim((string)$row[3]),
            "skill_level"    => $row[4]["display"] ?? null,
            "skill_sort"     => (int)($row[4]["sort_value"] ?? 0),
            "exp"            => $exp === "" ? null : (int)$exp,
            "weight"         => $row[7] === "" || $row[7] === null ? null : (float)$row[7],
            "ingredients"    => itemCell((string)$row[6]),
            "output"         => itemCell((string)$row[8]),
            "ingredient_ids" => json_decode((string)$row[9], true) ?: [],
        ];
    }

    usort($recipes, fn($a, $b) => $a["id"] <=> $b["id"]);
    return $recipes;
}

// ─────────────────────────────────────────────────────────────────────────────
// Run
// ─────────────────────────────────────────────────────────────────────────────

echo "Downloading...\n";
$parsed = [];
foreach (array_keys(DATASETS) as $name) {
    $rows = fetchRows($name, $refresh);
    $parsed[$name] = $name === "items" ? parseItems($rows) : parseRecipes($rows, $name);
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
    $path = __DIR__ . "/$file";
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
