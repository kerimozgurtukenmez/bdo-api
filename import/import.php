<?php
//no time limit
set_time_limit(0);

//include connection folder
require_once "../config/database.php";
$pdo = connect();

//print it.
ob_implicit_flush(true);

echo "=== BDO Import Starting ===\n\n";

// ─────────────────────────────────────────────────────
// Function: read JSON files and turn it to php format
// ─────────────────────────────────────────────────────
function readJson($filename) {
    $path = __DIR__ . "/" . $filename;

    if(!file_exists($path)) {
        die("ERROR: $filename not found!\n");
    }

    $content = file_get_contents($path);
    $data = json_decode($content, true);

    if ($data === null) {
        die("ERROR: $filename is not valid JSON!\n");
    }

    return $data;
}

// ───────────────────────────────────
// STEP 1: items.json -> items table
// ───────────────────────────────────
echo "Importing items...\n";

$items = readJson("items.json");

$stmt = $pdo->prepare("
    INSERT INTO items (id, name, grade, grade_name, icon, link)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name       = VALUES(name),
        grade      = VALUES(grade),
        grade_name = VALUES(grade_name),
        icon       = VALUES(icon),
        link       = VALUES(link)
");

$count = 0;
foreach ($items as $item) {
    $stmt->execute([
        $item["id"],
        $item["name"],
        $item["grade"],
        $item["grade_name"],
        $item["icon"],
        $item["link"] ?? null
    ]);
    $count++;
}

echo " Done: $count items imported.\n\n";

// ───────────────────────────────────────────────────────
// STEP 2: item_descriptions.json -> item_details table
// ───────────────────────────────────────────────────────
echo "Importing item details...\n";

$descriptions = readJson("item_descriptions.json");

$stmt = $pdo->prepare("
    INSERT INTO item_details (item_id, name_kr, category, weight, description, bound_on_obtain, personal_trade, buy_price, sell_price, warehouse_capacity)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name_kr            = VALUES(name_kr),
        category           = VALUES(category),
        weight             = VALUES(weight),
        description        = VALUES(description),
        bound_on_obtain    = VALUES(bound_on_obtain),
        personal_trade     = VALUES(personal_trade),
        buy_price          = VALUES(buy_price),
        sell_price         = VALUES(sell_price),
        warehouse_capacity = VALUES(warehouse_capacity)
");

$count = 0;
foreach ($descriptions as $id => $item) {
    $check = $pdo->prepare("SELECT id FROM items WHERE id = ?");
    $check->execute([$item["id"]]);

    if ($check->fetch()) {
        $stmt->execute([
            $item["id"],
            $item["name_kr"]                  ?? null,
            $item["category"]                 ?? null,
            $item["weight"]                   ?? null,
            $item["description"]              ?? null,
            $item["bound_on_obtain"]          ? 1 : 0,
            $item["personal_trade_available"] ? 1 : 0,
            $item["buy_price"]                ?? 0,
            $item["sell_price"]               ?? 0,
            $item["warehouse_capacity"]       ?? null
        ]);
        $count++;
    }
}

echo " Done: $count item details imported.\n\n";

// ─────────────────────────────────────────────
// STEP 3: raw_item_prices.json -> item_prices table
// ─────────────────────────────────────────────
echo "Importing item prices...\n";

$prices = readJson("raw_item_prices.json");

$stmt = $pdo->prepare("
    INSERT INTO item_prices (item_id, base_price, current_stock, total_trades, price_min, price_max, last_sold_price, last_sold_time)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        base_price      = VALUES(base_price),
        current_stock   = VALUES(current_stock),
        total_trades    = VALUES(total_trades),
        price_min       = VALUES(price_min),
        price_max       = VALUES(price_max),
        last_sold_price = VALUES(last_sold_price),
        last_sold_time  = VALUES(last_sold_time),
        updated_at      = CURRENT_TIMESTAMP
");

$count = 0;
foreach ($prices as $id => $item) {
    $check = $pdo->prepare("SELECT id FROM items WHERE id = ?");
    $check->execute([$item["id"]]);

    if ($check->fetch()) {
        $stmt->execute([
            $item["id"],
            $item["basePrice"]     ?? 0,
            $item["currentStock"]  ?? 0,
            $item["totalTrades"]   ?? 0,
            $item["priceMin"]      ?? 0,  // $item düzeltildi, $items değil
            $item["priceMax"]      ?? 0,  // $item düzeltildi, $items değil
            $item["lastSoldPrice"] ?? 0,
            $item["lastSoldTime"]  ?? 0
        ]);
        $count++;
    }
}

echo " Done: $count prices imported.\n\n";

// ─────────────────────────────────────────────
// STEP 4: import recipes
// ─────────────────────────────────────────────
function importRecipes($pdo, $filename, $source) {
    echo "Importing recipes from $filename...\n";

    $recipes = readJson($filename);

    $recipeStmt = $pdo->prepare("
        INSERT INTO recipes (id, source, name, category, grade, grade_name, icon, skill_level, skill_sort, exp, proc_rate, proc_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            source      = VALUES(source),
            name        = VALUES(name),
            category    = VALUES(category),
            skill_level = VALUES(skill_level),
            exp         = VALUES(exp),
            proc_rate   = VALUES(proc_rate),
            proc_amount = VALUES(proc_amount)
    ");

    // is_alternative: bu malzeme alternatif mi? (0=default, 1=alternatif)
    // slot_item_id:   alternatif ise, hangi default malzemenin yerine geçiyor?
    $inputStmt = $pdo->prepare("
        INSERT INTO recipe_inputs (recipe_id, recipe_source, item_id, qty_min, qty_max, is_key, is_alternative, slot_item_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $outputStmt = $pdo->prepare("
        INSERT INTO recipe_outputs (recipe_id, recipe_source, item_id, qty_min, qty_max)
        VALUES (?, ?, ?, ?, ?)
    ");

    $count = 0;
    foreach ($recipes as $recipe) {
        $recipeStmt->execute([
            $recipe["id"],
            $source,
            $recipe["name"],
            $recipe["category"],
            $recipe["grade"]       ?? 0,
            $recipe["grade_name"]  ?? "White",
            $recipe["icon"]        ?? null,
            $recipe["skill_level"] ?? null,
            $recipe["skill_sort"]  ?? 0,
            $recipe["exp"]         ?? 0,
            $recipe["proc_rate"]   ?? 1.0,
            $recipe["proc_amount"] ?? 1
        ]);

        // Eski input/output'ları temizle
        $pdo->prepare("DELETE FROM recipe_inputs  WHERE recipe_id = ? AND recipe_source = ?")->execute([$recipe["id"], $source]);
        $pdo->prepare("DELETE FROM recipe_outputs WHERE recipe_id = ? AND recipe_source = ?")->execute([$recipe["id"], $source]);

        // ── Alternatifleri bul ──────────────────────────────────────────────
        // ingredient_ids sıralamasından hangi alternatifin hangi slota ait
        // olduğunu ve qty'sini çıkarıyoruz
        $default_ids  = array_column($recipe["ingredients"], "item_id");
        $alternatives = []; // [ ['item_id'=>x, 'qty'=>y, 'slot_item_id'=>z], ... ]

        if (!empty($recipe["ingredient_ids"])) {
            $current_slot = null;
            $current_qty  = 1;

            foreach ($recipe["ingredient_ids"] as $iid) {
                if (in_array($iid, $default_ids)) {
                    // Default malzeme — yeni slot başlıyor
                    $ing = array_values(array_filter(
                        $recipe["ingredients"],
                        fn($i) => $i["item_id"] === $iid
                    ))[0];
                    $current_slot = $iid;
                    $current_qty  = $ing["qty_min"] ?? 1;
                } else {
                    // Alternatif malzeme — mevcut slota bağla
                    if ($current_slot !== null) {
                        $alternatives[] = [
                            "item_id"      => $iid,
                            "qty"          => $current_qty,
                            "slot_item_id" => $current_slot
                        ];
                    }
                }
            }
        }

        // ── Default malzemeleri yaz ─────────────────────────────────────────
        foreach ($recipe["ingredients"] as $ing) {
            $inputStmt->execute([
                $recipe["id"],
                $source,
                $ing["item_id"],
                $ing["qty_min"] ?? 1,
                $ing["qty_max"] ?? 1,
                isset($ing["is_key"]) ? 1 : 0,
                0,    // is_alternative = false
                null  // slot_item_id = null (default malzeme)
            ]);
        }

        // ── Alternatif malzemeleri yaz ──────────────────────────────────────
        foreach ($alternatives as $alt) {
            $inputStmt->execute([
                $recipe["id"],
                $source,
                $alt["item_id"],
                $alt["qty"],
                $alt["qty"],
                0,                   // is_key = false
                1,                   // is_alternative = true
                $alt["slot_item_id"] // hangi default malzemenin yerine geçiyor
            ]);
        }

        // ── Çıktıları yaz ───────────────────────────────────────────────────
        foreach ($recipe["output"] as $out) {
            $outputStmt->execute([
                $recipe["id"],
                $source,
                $out["item_id"],
                $out["qty_min"] ?? 1,
                $out["qty_max"] ?? 1
            ]);
        }

        $count++;
    }

    echo "  Done: $count recipes imported.\n\n";
}

importRecipes($pdo, "recipes_cooking.json",    "cooking");
importRecipes($pdo, "recipes_alchemy.json",    "alchemy");
importRecipes($pdo, "recipes_processing.json", "processing");

echo "=== Import Complete! ===\n";
?>
