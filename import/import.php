<?php
// ─────────────────────────────────────────────────────────────────────────────
// Imports the bdocodex JSON dumps in this folder into the database.
//
//   php import/import.php           update items/details, replace all recipes
//   php import/import.php --fresh   drop and recreate every table first
//
// Market prices from raw_item_prices.json are only used for items that have
// no price yet; run update_prices.php to refresh prices.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";

set_time_limit(0);
ini_set("memory_limit", "2G");

$fresh = in_array("--fresh", $argv, true);
$pdo   = db();
$start = microtime(true);
$warnings = [];

function readJson(string $filename): array
{
    $path = __DIR__ . "/" . $filename;
    if (!is_file($path)) {
        throw new RuntimeException("$filename not found");
    }

    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException("$filename is not valid JSON: " . json_last_error_msg());
    }

    return $data;
}

function step(string $message): void
{
    echo $message, "\n";
}

echo "=== BDO Import ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Schema
// ─────────────────────────────────────────────────────────────────────────────
if ($fresh) {
    step("Dropping tables...");
    foreach (["recipe_outputs", "recipe_inputs", "recipes", "item_prices", "item_details", "items"] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
    }
}

step("Applying database/schema.sql...");
$schema = preg_replace('/^\s*--.*$/m', "", file_get_contents(__DIR__ . "/../database/schema.sql"));
foreach (array_filter(array_map("trim", explode(";", $schema))) as $statement) {
    $pdo->exec($statement);
}

$pdo->beginTransaction();

// ─────────────────────────────────────────────────────────────────────────────
// STEP 1: items.json → items
// ─────────────────────────────────────────────────────────────────────────────
step("Importing items...");

$stmt = $pdo->prepare("
    INSERT INTO items (id, name, grade, grade_name, icon, link)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name), grade = VALUES(grade), grade_name = VALUES(grade_name),
        icon = VALUES(icon), link = VALUES(link)
");

$itemNames = [];  // id => name, used to validate references below
foreach (readJson("items.json") as $item) {
    $stmt->execute([
        $item["id"],
        trim($item["name"]),
        $item["grade"] ?? 0,
        $item["grade_name"] ?? null,
        $item["icon"] ?? null,
        $item["link"] ?? null,
    ]);
    $itemNames[$item["id"]] = trim($item["name"]);
}
step("  " . count($itemNames) . " items");

// ─────────────────────────────────────────────────────────────────────────────
// STEP 2: item_descriptions.json → item_details
// ─────────────────────────────────────────────────────────────────────────────
step("Importing item details...");

$stmt = $pdo->prepare("
    INSERT INTO item_details (item_id, name_kr, category, weight, description, bound_on_obtain,
                              personal_trade, buy_price, sell_price, vendor_sold, warehouse_capacity)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name_kr = VALUES(name_kr), category = VALUES(category), weight = VALUES(weight),
        description = VALUES(description), bound_on_obtain = VALUES(bound_on_obtain),
        personal_trade = VALUES(personal_trade), buy_price = VALUES(buy_price),
        sell_price = VALUES(sell_price), vendor_sold = VALUES(vendor_sold),
        warehouse_capacity = VALUES(warehouse_capacity)
");

// The game data gives every item a buy price, but only items whose description
// says a vendor sells them can actually be bought for it.
// ("purchased from" is left out: it also matches "made with X purchased from a Shop")
$vendorPattern = '/\b(can|may) be (bought|purchased)\b|\bpurchas(e|able) (it )?(from|at)\b|\bsold by\b/i';

$count = $vendors = 0;
foreach (readJson("item_descriptions.json") as $item) {
    if (!isset($itemNames[$item["id"]])) {
        $warnings[] = "item_details: unknown item {$item['id']}";
        continue;
    }

    $vendorSold = preg_match($vendorPattern, $item["description"] ?? "") ? 1 : 0;
    $stmt->execute([
        $item["id"],
        $item["name_kr"] ?? null,
        $item["category"] ?? null,
        $item["weight"] ?? null,
        $item["description"] ?? null,
        empty($item["bound_on_obtain"]) ? 0 : 1,
        empty($item["personal_trade_available"]) ? 0 : 1,
        $item["buy_price"] ?? 0,
        $item["sell_price"] ?? 0,
        $vendorSold,
        $item["warehouse_capacity"] ?? null,
    ]);
    $count++;
    $vendors += $vendorSold;
}
step("  $count item details ($vendors sold by NPC vendors)");

// ─────────────────────────────────────────────────────────────────────────────
// STEP 3: raw_item_prices.json → item_prices (only where no price exists yet)
// ─────────────────────────────────────────────────────────────────────────────
step("Importing seed prices...");

$stmt = $pdo->prepare("
    INSERT IGNORE INTO item_prices (item_id, base_price, current_stock, total_trades, price_min,
                                    price_max, last_sold_price, last_sold_time)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$count = 0;
foreach (readJson("raw_item_prices.json") as $item) {
    if (!isset($itemNames[$item["id"]])) {
        continue;
    }
    $stmt->execute([
        $item["id"],
        $item["basePrice"] ?? 0,
        $item["currentStock"] ?? 0,
        $item["totalTrades"] ?? 0,
        $item["priceMin"] ?? 0,
        $item["priceMax"] ?? 0,
        $item["lastSoldPrice"] ?? 0,
        $item["lastSoldTime"] ?? 0,
    ]);
    $count += $stmt->rowCount();
}
step("  $count new prices");

// ─────────────────────────────────────────────────────────────────────────────
// STEP 4: recipes_*.json → recipes, recipe_inputs, recipe_outputs
// ─────────────────────────────────────────────────────────────────────────────

// ingredient_ids lists the slots in recipe order, each slot as its default
// ingredient followed by the items that may replace it:
//   [default0, alt, alt, default1, default2, alt, ...]
// Returns slot => list of alternative item ids, or null if the list does not
// follow that layout.
function groupAlternatives(array $ingredients, array $ingredientIds): ?array
{
    $alternatives = array_fill(0, count($ingredients), []);
    $next = 0;        // next default we expect to see
    $slot = null;     // slot the current alternatives belong to

    foreach ($ingredientIds as $id) {
        if ($next < count($ingredients) && $id === $ingredients[$next]["item_id"]) {
            $slot = $next++;
            continue;
        }
        if ($slot === null) {
            return null;
        }
        if ($id !== $ingredients[$slot]["item_id"] && !in_array($id, $alternatives[$slot], true)) {
            $alternatives[$slot][] = $id;
        }
    }

    return $next === count($ingredients) ? $alternatives : null;
}

// The product the recipe is named after; otherwise the first output.
function mainOutputIndex(array $recipe, array $itemNames): int
{
    foreach ($recipe["output"] as $i => $out) {
        if (strcasecmp($itemNames[$out["item_id"]] ?? "", trim($recipe["name"])) === 0) {
            return $i;
        }
    }
    return 0;
}

$pdo->exec("DELETE FROM recipes");  // inputs/outputs are removed by ON DELETE CASCADE

$recipeStmt = $pdo->prepare("
    INSERT INTO recipes (source, id, name, category, grade, grade_name, icon, link,
                         skill_level, skill_sort, exp, ingredients_weight)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$inputStmt = $pdo->prepare("
    INSERT INTO recipe_inputs (recipe_source, recipe_id, slot, item_id, qty_min, qty_max,
                               is_key, is_alternative, slot_item_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$outputStmt = $pdo->prepare("
    INSERT INTO recipe_outputs (recipe_source, recipe_id, item_id, qty_min, qty_max, is_main)
    VALUES (?, ?, ?, ?, ?, ?)
");

foreach (RECIPE_SOURCES as $source) {
    step("Importing $source recipes...");

    $imported = 0;
    $skipped  = [];
    $ungrouped = 0;

    foreach (readJson("recipes_$source.json") as $recipe) {
        $label = "$source #{$recipe['id']} {$recipe['name']}";

        // Broken scrapes: a recipe needs ingredients and products to be usable
        if (empty($recipe["ingredients"]) || empty($recipe["output"])) {
            $skipped[] = $label;
            continue;
        }

        $refs = array_merge(
            array_column($recipe["ingredients"], "item_id"),
            array_column($recipe["output"], "item_id"),
            $recipe["ingredient_ids"] ?? []
        );
        $unknown = array_filter($refs, fn($id) => !isset($itemNames[$id]));
        if ($unknown) {
            $warnings[] = "$label: unknown item(s) " . implode(", ", array_unique($unknown)) . " — skipped";
            $skipped[] = $label;
            continue;
        }

        $alternatives = groupAlternatives($recipe["ingredients"], $recipe["ingredient_ids"] ?? []);
        if ($alternatives === null) {
            $alternatives = array_fill(0, count($recipe["ingredients"]), []);
            $ungrouped++;
            $warnings[] = "$label: ingredient_ids do not match ingredients, alternatives ignored";
        }

        // Scraped "proc_rate" is the total weight of the ingredients and
        // "proc_amount" duplicates the processing category, so it is not stored.
        $recipeStmt->execute([
            $source,
            $recipe["id"],
            trim($recipe["name"]),
            $recipe["category"],
            $recipe["grade"] ?? 0,
            $recipe["grade_name"] ?? null,
            $recipe["icon"] ?? null,
            $recipe["link"] ?? null,
            $recipe["skill_level"] ?? null,
            $recipe["skill_sort"] ?? 0,
            $recipe["exp"] ?? null,
            isset($recipe["proc_rate"]) ? round($recipe["proc_rate"], 2) : null,
        ]);

        foreach ($recipe["ingredients"] as $slot => $ing) {
            $qty = $ing["qty_min"] ?? 1;
            $inputStmt->execute([
                $source, $recipe["id"], $slot, $ing["item_id"],
                $qty, $ing["qty_max"] ?? $qty,
                empty($ing["is_key"]) ? 0 : 1, 0, null,
            ]);

            // Substitutes are used in the same amount as the default ingredient
            foreach ($alternatives[$slot] as $altId) {
                $inputStmt->execute([
                    $source, $recipe["id"], $slot, $altId,
                    $qty, $ing["qty_max"] ?? $qty,
                    0, 1, $ing["item_id"],
                ]);
            }
        }

        $main = mainOutputIndex($recipe, $itemNames);
        foreach ($recipe["output"] as $i => $out) {
            $outputStmt->execute([
                $source, $recipe["id"], $out["item_id"],
                $out["qty_min"] ?? 1, $out["qty_max"] ?? 1,
                $i === $main ? 1 : 0,
            ]);
        }

        $imported++;
    }

    step("  $imported imported, " . count($skipped) . " skipped (no ingredients/products or unknown items)"
        . ($ungrouped ? ", $ungrouped without alternatives" : ""));
}

$pdo->commit();

// ─────────────────────────────────────────────────────────────────────────────
// Report
// ─────────────────────────────────────────────────────────────────────────────
if ($warnings) {
    echo "\nWarnings (" . count($warnings) . "):\n";
    foreach (array_slice($warnings, 0, 20) as $w) {
        echo "  - $w\n";
    }
    if (count($warnings) > 20) {
        echo "  ... and " . (count($warnings) - 20) . " more\n";
    }
}

printf("\n=== Import complete in %.1fs ===\n", microtime(true) - $start);
