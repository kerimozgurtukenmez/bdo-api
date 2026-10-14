<?php
// ─────────────────────────────────────────────────────────────────────────────
// Downloads the bdocodex page of every item used in a recipe and keeps what
// the in-game tooltip says: description ("How to Obtain: Purchase from ..."),
// NPC buy price, weight, bound. import.php reads the result (data/item_info.json):
// NPC goods get their vendor price, items the game replaced are marked.
//
//   php bin/scrape_items.php              every item without a page, or with one older than 30 days
//   php bin/scrape_items.php --limit=500  at most 500 downloads (the worker does this daily)
//
// One page per item, 3 s apart: the first full run takes a few hours. Pages
// are cached in data/cache/pages/, so a stopped run continues where it was.
// Reads the recipe lists in data/, so run bin/scrape.php first.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/bdocodex.php";

set_time_limit(0);

const PAGE_MAX_AGE = 30 * 24 * 3600;

$limit = null;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
    }
}

// Every item a recipe uses or makes, substitutes included
$ids = [];
foreach (["cooking", "alchemy", "processing"] as $source) {
    $file = DATA_DIR . "/recipes_$source.json";
    if (!is_file($file)) {
        exit("$file not found: run bin/scrape.php first.\n");
    }
    foreach (json_decode((string)file_get_contents($file), true) as $recipe) {
        foreach ([...$recipe["ingredients"], ...$recipe["output"]] as $entry) {
            $ids[$entry["item_id"]] = true;
        }
        foreach ($recipe["ingredient_ids"] ?? [] as $id) {
            $ids[$id] = true;
        }
    }
}
$ids = array_keys($ids);
sort($ids);

$fresh = fn(int $id) => is_file($f = CODEX_CACHE_DIR . "/pages/item_$id.html") && time() - filemtime($f) < PAGE_MAX_AGE;
$todo  = array_values(array_filter($ids, fn($id) => !$fresh($id)));
if ($limit !== null) {
    $todo = array_slice($todo, 0, $limit);
}
printf("%d recipe items, %d pages to download%s\n", count($ids), count($todo),
    $todo ? sprintf(" (about %d min)", ceil(count($todo) * CODEX_REQUEST_DELAY / 60)) : "");

$failed = 0;
foreach ($todo as $n => $id) {
    try {
        codex_download("/" . BDOCODEX_LANG . "/item/$id/", "/items/", "pages/item_$id.html", true, PAGE_MAX_AGE);
    } catch (RuntimeException $e) {
        echo "  item $id: " . $e->getMessage() . "\n";
        if (++$failed >= 10) {
            echo "Stopping after 10 failures; run again later.\n";
            break;
        }
    }
    if (($n + 1) % 100 === 0) {
        printf("  %d/%d\n", $n + 1, count($todo));
    }
}

// Everything on disk, also pages from earlier runs
$info = [];
foreach ($ids as $id) {
    $file = CODEX_CACHE_DIR . "/pages/item_$id.html";
    if (is_file($file)) {
        $info[] = ["id" => $id] + codex_parse_item_page((string)file_get_contents($file));
    }
}
file_put_contents(DATA_DIR . "/item_info.json", json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
printf("item_info.json: %d of %d items have a page%s\n", count($info), count($ids), $failed ? ", $failed downloads failed" : "");
exit($failed > 0 ? 1 : 0);
