<?php
// ─────────────────────────────────────────────────────────────────────────────
// Imports the bdocodex JSON files in data/ into the database.
//
//   php bin/import.php           update items/details, replace all recipes
//   php bin/import.php --fresh   drop and recreate every table first
//
// Market prices are kept; bin/update_prices.php refreshes them.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/importer.php";

set_time_limit(0);
ini_set("memory_limit", "2G");

$fresh = in_array("--fresh", $argv, true);
$pdo   = db();
$start = microtime(true);
$warnings = [];

// The data files are not in git: bin/scrape.php writes items.json and the
// recipes; item_descriptions.json (item details) is an optional extra.
function readJson(string $filename, bool $optional = false): array
{
    $path = DATA_DIR . "/" . $filename;
    if (!is_file($path)) {
        if ($optional) {
            step("  $filename not found, skipped");
            return [];
        }
        throw new RuntimeException("$filename not found - run bin/scrape.php first");
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
apply_schema($pdo);

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
        icon_path($item["icon"] ?? null),
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

$count = $vendors = $removed = 0;
foreach (readJson("item_descriptions.json", optional: true) as $item) {
    if (!isset($itemNames[$item["id"]])) {
        $removed++;  // details of items no longer in the game
        continue;
    }

    $vendorSold = is_vendor_sold($item["description"] ?? null) ? 1 : 0;
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
step("  $count item details ($vendors sold by NPC vendors)" . ($removed ? ", $removed for removed items skipped" : ""));

// ─────────────────────────────────────────────────────────────────────────────
// STEP 3: recipes_*.json → recipes, recipe_inputs, recipe_outputs
// ─────────────────────────────────────────────────────────────────────────────

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

        $alternatives = group_alternatives($recipe["ingredients"], $recipe["ingredient_ids"] ?? []);
        if ($alternatives === null) {
            $alternatives = array_fill(0, count($recipe["ingredients"]), []);
            $ungrouped++;
            $warnings[] = "$label: ingredient_ids do not match ingredients, alternatives ignored";
        }

        $recipeStmt->execute([
            $source,
            $recipe["id"],
            trim($recipe["name"]),
            $recipe["category"],
            $recipe["grade"] ?? 0,
            $recipe["grade_name"] ?? null,
            icon_path($recipe["icon"] ?? null),
            $recipe["link"] ?? null,
            $recipe["skill_level"] ?? null,
            $recipe["skill_sort"] ?? 0,
            $recipe["exp"] ?? null,
            isset($recipe["weight"]) ? round($recipe["weight"], 2) : null,
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

        $main = main_output_index($recipe, $itemNames);
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

// ─────────────────────────────────────────────────────────────────────────────
// STEP 4: remove items that are no longer in items.json (removed from the game)
// ─────────────────────────────────────────────────────────────────────────────
$stale = array_diff($pdo->query("SELECT id FROM items")->fetchAll(PDO::FETCH_COLUMN), array_keys($itemNames));
foreach (array_chunk($stale, 1000) as $chunk) {
    $pdo->prepare("DELETE FROM items WHERE id IN (" . implode(",", array_fill(0, count($chunk), "?")) . ")")->execute($chunk);
}
step("Removed " . count($stale) . " items that bdocodex no longer lists");

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
