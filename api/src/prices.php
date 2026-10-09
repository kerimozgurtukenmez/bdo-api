<?php
// Central Market price history (table item_price_history)

declare(strict_types=1);

// One point per day for the last $days days, oldest first: [{t, price, stock}]
// t = the day at 00:00 UTC, so it is the same date in every time zone
function price_history(int $itemId, int $days): array
{
    $rows = query("
        SELECT day, price, stock
        FROM item_price_history
        WHERE item_id = ? AND day > CURDATE() - INTERVAL ? DAY
        ORDER BY day
    ", [$itemId, $days])->fetchAll();

    return array_map(fn($row) => ["t" => (int)strtotime($row["day"] . " 00:00:00 UTC"), "price" => $row["price"], "stock" => $row["stock"]], $rows);
}

// Today's price of the given items, from item_prices (run after a price update)
function record_price_history(array $itemIds): int
{
    if (!$itemIds) {
        return 0;
    }

    $ids = array_values(array_map("intval", $itemIds));
    return query("
        INSERT INTO item_price_history (item_id, day, price, stock)
        SELECT item_id, CURDATE(), base_price, current_stock
        FROM item_prices
        WHERE base_price > 0 AND item_id IN (" . placeholders($ids) . ")
        ON DUPLICATE KEY UPDATE price = VALUES(price), stock = VALUES(stock)
    ", $ids)->rowCount();
}
