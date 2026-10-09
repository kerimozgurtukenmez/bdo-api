<?php
// Parsing the list data bdocodex.com pages load (query.php → {"aaData": rows}).
// Rows mix plain values with HTML snippets. Used by import/scrape.php.

declare(strict_types=1);

const BDOCODEX_URL  = "https://bdocodex.com";
const BDOCODEX_LANG = "us";

const GRADE_NAMES = [0 => "White", 1 => "Green", 2 => "Blue", 3 => "Gold", 4 => "Orange", 5 => "Unknown"];

function codex_text(string $html): string
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
}

function codex_icon_url(string $html): ?string
{
    return preg_match('#\[img src="([^"]+)"#', $html, $m) ? BDOCODEX_URL . $m[1] : null;
}

function codex_grade(string $html): int
{
    return preg_match('#item_grade_(\d)#', $html, $m) ? (int)$m[1] : 0;
}

// Ingredient or product cell: one icon block per item with its quantity ("5" or "1~4")
function codex_item_cell(string $html): array
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

// Rows of query.php?a=items, sorted by id
function codex_parse_items(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        // [id, icon html, name html, ?, ?, grade, ?]
        $id    = (int)$row[0];
        $grade = (int)$row[5];
        $items[$id] = [
            "id"         => $id,
            "name"       => codex_text($row[2]),
            "grade"      => $grade,
            "grade_name" => GRADE_NAMES[$grade] ?? "Unknown",
            "icon"       => codex_icon_url($row[1]),
            "link"       => BDOCODEX_URL . "/" . BDOCODEX_LANG . "/item/$id/",
        ];
    }

    ksort($items);
    return array_values($items);
}

// Rows of query.php?a=recipes (cooking, alchemy) or a=mrecipes (processing), sorted by id
function codex_parse_recipes(array $rows, string $source): array
{
    $path = $source === "processing" ? "mrecipe" : "recipe";

    $recipes = [];
    foreach ($rows as $row) {
        // [id, icon html, name html, category, {display, sort_value}, exp,
        //  ingredients html, weight, products html, ingredient_ids, ...]
        $id    = (int)$row[0];
        $exp   = preg_replace('/\D/', "", (string)$row[5]);  // "1'000" → 1000
        $grade = codex_grade($row[2]);

        $recipes[] = [
            "id"             => $id,
            "name"           => codex_text($row[2]),
            "grade"          => $grade,
            "grade_name"     => GRADE_NAMES[$grade] ?? "Unknown",
            "icon"           => codex_icon_url($row[1]),
            "link"           => BDOCODEX_URL . "/" . BDOCODEX_LANG . "/$path/$id/",
            "category"       => trim((string)$row[3]),
            "skill_level"    => $row[4]["display"] ?? null,
            "skill_sort"     => (int)($row[4]["sort_value"] ?? 0),
            "exp"            => $exp === "" ? null : (int)$exp,
            "weight"         => $row[7] === "" || $row[7] === null ? null : (float)$row[7],
            "ingredients"    => codex_item_cell((string)$row[6]),
            "output"         => codex_item_cell((string)$row[8]),
            "ingredient_ids" => json_decode((string)$row[9], true) ?: [],
        ];
    }

    usort($recipes, fn($a, $b) => $a["id"] <=> $b["id"]);
    return $recipes;
}
