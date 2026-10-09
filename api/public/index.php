<?php
// API overview and health check

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
api_init();

$stats = query("
    SELECT
        (SELECT COUNT(*) FROM items)                       AS items,
        (SELECT COUNT(*) FROM recipes)                     AS recipes,
        (SELECT COUNT(*) FROM item_prices WHERE base_price > 0) AS market_prices,
        (SELECT MAX(updated_at) FROM item_prices)          AS prices_updated_at
")->fetch();

json_out([
    "name"      => "BDO Craft API",
    "region"    => config("market")["region"],
    "stats"     => $stats,
    "endpoints" => [
        "items.php"   => [
            "?id=9065"                      => "One item with details, price and the recipes that make it",
            "?search=milk&craftable=1"      => "Search items; craftable=1 or source=cooking limits to items with a recipe",
        ],
        "recipes.php" => [
            "?source=cooking&id=106"        => "One recipe with ingredient slots (incl. substitutes) and products",
            "?item_id=9003&grouped=1"       => "Every recipe that makes an item, grouped by life skill and category",
            "?source=processing&category=Heating&skill=Beginner&search=iron" => "List recipes; with_ingredients=1 adds default ingredients",
            "?ingredient_id=9065"           => "Recipes that use an item",
            "?categories=1"                 => "Recipe categories per life skill, with counts",
        ],
        "craft.php"   => [
            "?item_id=9003&qty=100"         => "Full crafting plan: materials to buy, steps, cost, recipe tree",
            "&recipe[9003]=cooking:106"     => "Use a specific recipe for an item",
            "&buy=9017,9018"                => "Buy these items instead of crafting them",
            "&substitute[7313]=7304"        => "Use a substitute ingredient where the recipe allows it",
            "&mode=cheapest"                => "Buy intermediates from the market when cheaper than crafting them",
            "&yield=min"                    => "Products per craft: min, avg (default) or max",
        ],
    ],
]);
