<?php
// GET items.php?id=9065                  one item: details, price, recipes that make it
// GET items.php?search=milk              search by name (exact and prefix matches first)
//              &craftable=1              only items some recipe makes
//              &source=cooking           only items made with this life skill
//              &page=1&limit=50

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
api_init();

$id = param_int("id");

// ── Single item ──────────────────────────────────────────────────────────────
if ($id !== null) {
    $item = query("
        SELECT i.id, i.name, i.grade, i.grade_name, i.icon, i.link,
               d.name_kr, d.description, d.category, d.weight, d.warehouse_capacity,
               d.bound_on_obtain, d.personal_trade,
               p.current_stock, p.total_trades, p.price_min, p.price_max, p.last_sold_time,
               " . PRICE_COLUMNS . "
        FROM items i
        LEFT JOIN item_details d ON d.item_id = i.id
        LEFT JOIN item_prices  p ON p.item_id = i.id
        WHERE i.id = ?
    ", [$id])->fetch();

    if (!$item) {
        throw new ApiError("Item not found", 404);
    }

    $item = with_price($item, keepRaw: true);
    $item["icon"] = icon_url($item["icon"]);

    // Recipes this item is the main product of
    $item["made_by"] = query("
        SELECT r.source, r.id, r.name, r.category, r.skill_level, ro.qty_min, ro.qty_max
        FROM recipe_outputs ro
        JOIN recipes r ON r.source = ro.recipe_source AND r.id = ro.recipe_id
        WHERE ro.item_id = ? AND ro.is_main = 1
        ORDER BY r.source, r.id
    ", [$id])->fetchAll();

    $item["used_in_recipes"] = (int)query("
        SELECT COUNT(DISTINCT recipe_source, recipe_id) FROM recipe_inputs WHERE item_id = ?
    ", [$id])->fetchColumn();

    json_out($item);
}

// ── List / search ────────────────────────────────────────────────────────────
[$page, $limit, $offset] = pagination();
$search    = param_str("search");
$source    = param_enum("source", RECIPE_SOURCES);
$craftable = param_bool("craftable") || $source !== null;

$where  = [];
$params = [];

if ($search !== null) {
    $where[]  = "i.name LIKE ?";
    $params[] = like_contains($search);
}

if ($craftable) {
    $where[] = "EXISTS (SELECT 1 FROM recipe_outputs ro WHERE ro.item_id = i.id AND ro.is_main = 1"
             . ($source !== null ? " AND ro.recipe_source = ?" : "") . ")";
    if ($source !== null) {
        $params[] = $source;
    }
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Exact name first, then names starting with the search term
$orderSql    = $search !== null ? "i.name = ? DESC, i.name LIKE ? DESC, i.name, i.id" : "i.id";
$orderParams = $search !== null ? [$search, addcslashes($search, "%_\\") . "%"] : [];

$rows = query("
    SELECT i.id, i.name, i.grade, i.grade_name, i.icon,
           (SELECT GROUP_CONCAT(DISTINCT ro.recipe_source ORDER BY ro.recipe_source)
            FROM recipe_outputs ro WHERE ro.item_id = i.id AND ro.is_main = 1) AS sources,
           " . PRICE_COLUMNS . "
    FROM items i
    LEFT JOIN item_details d ON d.item_id = i.id
    LEFT JOIN item_prices  p ON p.item_id = i.id
    $whereSql
    ORDER BY $orderSql
    LIMIT ? OFFSET ?
", [...$params, ...$orderParams, $limit, $offset])->fetchAll();

$items = array_map(fn($row) => [
    "id"         => $row["id"],
    "name"       => $row["name"],
    "grade"      => $row["grade"],
    "grade_name" => $row["grade_name"],
    "icon"       => icon_url($row["icon"]),
    "sources"    => $row["sources"] ? explode(",", $row["sources"]) : [],  // life skills that make it
    "price"      => item_price($row),
], $rows);

$total = (int)query("SELECT COUNT(*) FROM items i $whereSql", $params)->fetchColumn();

json_out(paginated($items, $total, $page, $limit));
