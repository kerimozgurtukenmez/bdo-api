<?php
// Sanity checks of the imported data (bin/check_data.php, end of import.php).
// Each check counts rows and shows a few examples, so a broken scrape or
// import is noticed without reading the data.

declare(strict_types=1);

// [["level" => problem|info, "check" => text, "count" => n, "examples" => [names]]]
function data_checks(PDO $pdo): array
{
    $recipeItems = "(SELECT item_id FROM recipe_inputs UNION SELECT item_id FROM recipe_outputs)";
    $checks = [
        ["problem", "Cooking/alchemy products with more than 12 recipes (variants not folded?)", "
            SELECT CONCAT(i.name, ' (', COUNT(*), ')') FROM recipe_outputs ro JOIN items i ON i.id = ro.item_id
            WHERE ro.is_main = 1 AND ro.recipe_source <> 'processing'
            GROUP BY ro.item_id HAVING COUNT(*) > 12"],
        ["problem", "Recipes without ingredients or without a main product", "
            SELECT CONCAT(r.source, ':', r.id, ' ', r.name) FROM recipes r
            WHERE NOT EXISTS (SELECT 1 FROM recipe_inputs x WHERE x.recipe_source = r.source AND x.recipe_id = r.id)
               OR NOT EXISTS (SELECT 1 FROM recipe_outputs x WHERE x.recipe_source = r.source AND x.recipe_id = r.id AND x.is_main = 1)"],
        ["problem", "Amounts of zero or an output range upside down", "
            SELECT CONCAT(recipe_source, ':', recipe_id) FROM recipe_outputs WHERE qty_min = 0 OR qty_max < qty_min
            UNION ALL SELECT CONCAT(recipe_source, ':', recipe_id) FROM recipe_inputs WHERE qty_min = 0"],
        ["problem", "Recipe items without a name", "
            SELECT t.item_id FROM $recipeItems t JOIN items i ON i.id = t.item_id WHERE TRIM(i.name) = ''"],
        ["problem", "Market prices outside the market's own min–max", "
            SELECT CONCAT(i.name, ' ', p.base_price) FROM item_prices p JOIN items i ON i.id = p.item_id
            WHERE p.base_price > 0 AND p.price_max > 0 AND (p.base_price < p.price_min OR p.base_price > p.price_max)"],
        ["info", "Recipe items without any price (not on the market, not sold by a vendor)", "
            SELECT i.name FROM $recipeItems t JOIN items i ON i.id = t.item_id
            LEFT JOIN item_prices p ON p.item_id = t.item_id LEFT JOIN item_details d ON d.item_id = t.item_id
            WHERE COALESCE(p.base_price, 0) = 0 AND COALESCE(d.vendor_sold, 0) = 0"],
        ["info", "Market prices older than a day", "
            SELECT i.name FROM item_prices p JOIN items i ON i.id = p.item_id
            WHERE p.base_price > 0 AND p.updated_at < NOW() - INTERVAL 1 DAY"],
        ["info", "Substitute amounts estimated from the grade", "
            SELECT CONCAT(i.name, ' x', ri.qty_min) FROM recipe_inputs ri JOIN items i ON i.id = ri.item_id WHERE ri.qty_estimated = 1"],
    ];

    $result = [];
    foreach ($checks as [$level, $check, $sql]) {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $result[] = ["level" => $level, "check" => $check, "count" => count($rows), "examples" => array_slice(array_unique($rows), 0, 3)];
    }

    // Hand-made corrections that point at items the data no longer has
    $defaults = require __DIR__ . "/../config/recipe_defaults.php";
    $ids = array_merge($defaults["buy"], array_keys($defaults["recipe"]));
    $known = $ids ? $pdo->query("SELECT id FROM items WHERE id IN (" . implode(",", array_map("intval", $ids)) . ")")->fetchAll(PDO::FETCH_COLUMN) : [];
    $gone = array_values(array_diff($ids, $known));
    $result[] = ["level" => "problem", "check" => "config/recipe_defaults.php items that do not exist", "count" => count($gone), "examples" => array_slice($gone, 0, 3)];

    return $result;
}

// The checks as lines of text; problems first
function data_check_report(array $checks): string
{
    usort($checks, fn($a, $b) => [$a["level"] !== "problem", $a["check"]] <=> [$b["level"] !== "problem", $b["check"]]);
    $lines = [];
    foreach ($checks as $c) {
        $mark = $c["count"] === 0 ? "ok     " : ($c["level"] === "problem" ? "PROBLEM" : "info   ");
        $lines[] = sprintf("  %s %6d  %s%s", $mark, $c["count"], $c["check"],
            $c["count"] ? " — e.g. " . implode(", ", $c["examples"]) : "");
    }
    return implode("\n", $lines) . "\n";
}
