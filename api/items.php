<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once "../config/database.php";
$pdo = connect();

$id = $_GET["id"] ?? null;

if ($id) {
    $stmt = $pdo->prepare("
        SELECT 
            i.id,
            i.name,
            i.grade,
            i.grade_name,
            i.icon,
            i.link,
            d.name_kr,
            d.description,
            d.category,
            d.weight,
            d.warehouse_capacity,
            d.buy_price,
            d.sell_price,
            d.bound_on_obtain,
            d.personal_trade,
            p.base_price,
            p.current_stock,
            p.last_sold_price,
            p.price_min,
            p.price_max
        FROM items i
        LEFT JOIN item_details d ON d.item_id = i.id
        LEFT JOIN item_prices  p ON p.item_id = i.id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(["error" => "Item not found"]);
        exit;
    }
    
    echo json_encode($item);
} else {
    $page = max(1, intval($_GET["page"] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;

    $limit = (int)$limit;
    $offset = (int)$offset;

    $search = $_GET["search"] ?? null;

    if ($search) {
        $stmt = $pdo->prepare("
            SELECT i.id, i.name, i.grade, i.grade_name, i.icon
            FROM items i
            WHERE i.name LIKE ?
            ORDER BY i.name ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, "%" . $search . "%", PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT i.id, i.name, i.grade, i.grade_name, i.icon
            FROM items i
            ORDER BY i.id ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
    }

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($search) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE name LIKE ?");
        $countStmt->execute(["%" . $search . "%"]);
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM items");
    }
    $total = $countStmt->fetchColumn();

    echo json_encode([
        "data"          => $items,
        "total"         => (int)$total,
        "page"          => $page,
        "per_page"      => $limit,
        "total_pages"   => ceil($total / $limit)
    ]);
}
?>