<?php
// GET prices.php?item_id=9213&days=30
//
// Central Market price history of an item, one point per day, oldest first:
// { item_id, region, days, points: [{ t, price, stock }] }   (t = the day at 00:00 UTC)
// Points start on the first day bin/update_prices.php recorded the item.

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/prices.php";
api_init();

$itemId = param_int("item_id") ?? throw new ApiError("'item_id' is required");
$days   = param_int("days", 30, 1, 365);

if (!query("SELECT 1 FROM items WHERE id = ?", [$itemId])->fetchColumn()) {
    throw new ApiError("Item not found", 404);
}

json_out([
    "item_id" => $itemId,
    "region"  => config("market")["region"],
    "days"    => $days,
    "points"  => price_history($itemId, $days),
]);
