<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once "../config/database.php";
$pdo = connect();

$id      = $_GET["id"]      ?? null;
$source  = $_GET["source"]  ?? null;  // 'cooking', 'alchemy', 'processing'
$category = $_GET["category"] ?? null; // 'Heating', 'Grinding' vb.
$item_id  = $_GET["item_id"]  ?? null;
$search   = $_GET["search"]   ?? null;

// ── Grouped: aynı çıktıyı veren tarifleri grupla ──────────────────────────
if ($item_id && isset($_GET["grouped"])) {

    // Bu item'ı üreten tüm tarifleri bul
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.source,
            r.name,
            r.category,
            r.skill_level,
            r.proc_rate,
            r.proc_amount
        FROM recipes r
        WHERE EXISTS (
            SELECT 1 FROM recipe_outputs ro
            WHERE ro.recipe_id     = r.id
            AND   ro.recipe_source = r.source
            AND   ro.item_id       = ?
        )
        ORDER BY r.source ASC, r.category ASC
    ");
    $stmt->execute([$item_id]);
    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($recipes)) {
        echo json_encode([
            "item_id"  => (int)$item_id,
            "recipes"  => [],
            "message"  => "No recipes found for this item"
        ]);
        exit;
    }

    // Her tarif için malzemeleri çek
    $inputStmt = $pdo->prepare("
        SELECT
            ri.item_id,
            ri.qty_min,
            ri.qty_max,
            ri.is_key,
            ri.is_alternative,
            ri.slot_item_id,
            i.name,
            i.icon,
            i.grade,
            i.grade_name,
            d.buy_price,
            d.sell_price,
            p.last_sold_price
        FROM recipe_inputs ri
        JOIN  items i            ON i.id      = ri.item_id
        LEFT JOIN item_details d ON d.item_id = ri.item_id
        LEFT JOIN item_prices  p ON p.item_id = ri.item_id
        WHERE ri.recipe_id     = ?
        AND   ri.recipe_source = ?
        ORDER BY ri.is_alternative ASC, ri.slot_item_id ASC, ri.id ASC
    ");

    // Sonuçları kaynak + kategoriye göre grupla
    // Örnek: cooking > Cooking, processing > Heating, processing > Grinding
    $grouped = [];

    foreach ($recipes as $recipe) {
        $key = $recipe["source"] . "_" . $recipe["category"];

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                "source"      => $recipe["source"],
                "category"    => $recipe["category"],
                "skill_level" => $recipe["skill_level"],
                "proc_rate"   => $recipe["proc_rate"],
                "proc_amount" => $recipe["proc_amount"],
                "recipes"     => []
            ];
        }

        // Malzemeleri çek
        $inputStmt->execute([$recipe["id"], $recipe["source"]]);
        $ingredients = $inputStmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped[$key]["recipes"][] = [
            "recipe_id"   => $recipe["id"],
            "ingredients" => $ingredients
        ];
    }

    // Output item bilgisini de ekle
    $itemStmt = $pdo->prepare("
        SELECT i.id, i.name, i.icon, i.grade, i.grade_name,
               p.last_sold_price, p.base_price, d.buy_price, d.sell_price
        FROM items i
        LEFT JOIN item_prices  p ON p.item_id = i.id
        LEFT JOIN item_details d ON d.item_id = i.id
        WHERE i.id = ?
    ");
    $itemStmt->execute([$item_id]);
    $outputItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "output_item"   => $outputItem,
        "total_recipes" => count($recipes),
        "groups"        => array_values($grouped)
    ]);
    exit;
}

if ($id && $source) {
    // ── Tek tarif: malzemeleri ve çıktılarıyla birlikte getir ──────────────
    $stmt = $pdo->prepare("
        SELECT r.*
        FROM recipes r
        WHERE r.id = ? AND r.source = ?
    ");
    $stmt->execute([$id, $source]);
    $recipe = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipe) {
        http_response_code(404);
        echo json_encode(["error" => "Recipe not found"]);
        exit;
    }

    // Malzemeleri getir
    $inputStmt = $pdo->prepare("
        SELECT
            ri.item_id,
            ri.qty_min,
            ri.qty_max,
            ri.is_key,
            ri.is_alternative,
            ri.slot_item_id,
            i.name,
            i.icon,
            i.grade,
            i.grade_name,
            d.buy_price,
            d.sell_price,
            p.last_sold_price
        FROM recipe_inputs ri
        JOIN items i             ON i.id       = ri.item_id
        LEFT JOIN item_details d ON d.item_id  = ri.item_id
        LEFT JOIN item_prices  p ON p.item_id  = ri.item_id
        WHERE ri.recipe_id = ? AND ri.recipe_source = ?
        ORDER BY ri.is_alternative ASC, ri.slot_item_id ASC, ri.id ASC
    ");
    $inputStmt->execute([$id, $source]);
    $ingredients = $inputStmt->fetchAll(PDO::FETCH_ASSOC);

    // Çıktıları getir
    $outputStmt = $pdo->prepare("
        SELECT
            ro.item_id,
            ro.qty_min,
            ro.qty_max,
            i.name,
            i.icon,
            i.grade,
            i.grade_name,
            p.last_sold_price,
            p.base_price
        FROM recipe_outputs ro
        JOIN items i            ON i.id      = ro.item_id
        LEFT JOIN item_prices p ON p.item_id = ro.item_id
        WHERE ro.recipe_id = ? AND ro.recipe_source = ?
    ");
    $outputStmt->execute([$id, $source]);
    $outputs = $outputStmt->fetchAll(PDO::FETCH_ASSOC);

    $recipe["ingredients"] = $ingredients;
    $recipe["outputs"]     = $outputs;

    echo json_encode($recipe);

} else {
    // ── Liste ───────────────────────────────────────────────────────────────
    $page   = max(1, intval($_GET["page"] ?? 1));
    $limit  = (int)50;
    $offset = (int)(($page - 1) * $limit);

    $where  = [];
    $params = [];

    // source filtresi: cooking, alchemy, processing
    if ($source) {
        $where[]  = "r.source = ?";
        $params[] = $source;
    }

    // category filtresi: Heating, Grinding, Chopping vb.
    if ($category) {
        $where[]  = "r.category = ?";
        $params[] = $category;
    }

    // Bu item_id'yi çıktı olarak üreten tarifler
    if ($item_id) {
        $where[]  = "EXISTS (SELECT 1 FROM recipe_outputs ro WHERE ro.recipe_id = r.id AND ro.recipe_source = r.source AND ro.item_id = ?)";
        $params[] = $item_id;
    }

    // İsme göre arama
    if ($search) {
        $where[]  = "r.name LIKE ?";
        $params[] = "%" . $search . "%";
    }

    $whereSQL = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.source,
            r.name,
            r.category,
            r.grade,
            r.grade_name,
            r.icon,
            r.skill_level,
            r.exp,
            r.proc_rate,
            r.proc_amount
        FROM recipes r
        $whereSQL
        ORDER BY r.source ASC, r.category ASC, r.skill_sort ASC
        LIMIT ? OFFSET ?
    ");

    $i = 1;
    foreach ($params as $param) {
        $stmt->bindValue($i, $param, PDO::PARAM_STR);
        $i++;
    }
    $stmt->bindValue($i,     $limit,  PDO::PARAM_INT);
    $stmt->bindValue($i + 1, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Toplam sayı
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM recipes r $whereSQL");
    $i = 1;
    foreach ($params as $param) {
        $countStmt->bindValue($i, $param, PDO::PARAM_STR);
        $i++;
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();

    echo json_encode([
        "data"        => $recipes,
        "total"       => (int)$total,
        "page"        => $page,
        "per_page"    => $limit,
        "total_pages" => ceil($total / $limit)
    ]);
}
?>