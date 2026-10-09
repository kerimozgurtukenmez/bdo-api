<?php
// Imperial delivery: boxes packed by Imperial Cuisine / Imperial Alchemy
// processing and delivered to the NPC for base price × (2.5 + mastery bonus).
// Requires src/recipes.php.

declare(strict_types=1);

const IMPERIAL_CATEGORY = ["cooking" => "Imperial Cuisine", "alchemy" => "Imperial Alchemy"];
const IMPERIAL_TIERS    = ["Apprentice", "Skilled", "Professional", "Artisan", "Master", "Guru"];

// "Master's Cooking Box" → "Master"; null for other products of the category
// (event boxes, single dishes, "Precious" boxes)
function imperial_tier(string $name): ?string
{
    $pattern = '/^(' . implode("|", IMPERIAL_TIERS) . ")(?:'s| Cook's| Alchemist's) (?:Cooking|Medicine) Box$/";
    return preg_match($pattern, $name, $m) ? $m[1] : null;
}

// Boxes of a life skill by tier, each with every recipe that packs it. A
// recipe's ingredients carry their price; recipes are cheapest to buy first.
function imperial_boxes(string $skill): array
{
    $recipes = query("
        SELECT r.source, r.id, i.id AS item_id, i.name, i.grade, i.grade_name, i.icon, d.buy_price AS base_price
        FROM recipes r
        JOIN recipe_outputs ro ON ro.recipe_source = r.source AND ro.recipe_id = r.id AND ro.is_main = 1
        JOIN items i ON i.id = ro.item_id
        JOIN item_details d ON d.item_id = i.id AND d.buy_price > 0
        WHERE r.source = 'processing' AND r.category = ?
    ", [IMPERIAL_CATEGORY[$skill]])->fetchAll();

    $recipes = array_values(array_filter($recipes, fn($r) => imperial_tier($r["name"]) !== null));
    $slots   = recipe_slots($recipes, withAlternatives: false);

    $boxes = [];
    foreach ($recipes as $recipe) {
        $boxes[$recipe["item_id"]] ??= [
            "item" => [
                "id"         => $recipe["item_id"],
                "name"       => $recipe["name"],
                "grade"      => $recipe["grade"],
                "grade_name" => $recipe["grade_name"],
                "icon"       => icon_url($recipe["icon"]),
            ],
            "tier"       => imperial_tier($recipe["name"]),
            "base_price" => $recipe["base_price"],
            "recipes"    => [],
        ];

        $boxes[$recipe["item_id"]]["recipes"][] = [
            "key"         => recipe_key($recipe),
            "ingredients" => array_map(fn($slot) => [
                "item"  => [
                    "id"         => $slot["item_id"],
                    "name"       => $slot["name"],
                    "grade"      => $slot["grade"],
                    "grade_name" => $slot["grade_name"],
                    "icon"       => $slot["icon"],
                ],
                "qty"   => $slot["qty_min"],
                "price" => $slot["price"],
            ], $slots[recipe_key($recipe)] ?? []),
        ];
    }

    // Cost of buying every ingredient; recipes with a missing price last
    $cost = function (array $recipe): float {
        $total = 0;
        foreach ($recipe["ingredients"] as $ing) {
            if ($ing["price"] === null) {
                return INF;
            }
            $total += $ing["qty"] * $ing["price"]["unit"];
        }
        return $total;
    };

    foreach ($boxes as &$box) {
        usort($box["recipes"], fn($a, $b) => $cost($a) <=> $cost($b));
    }
    unset($box);

    $boxes = array_values($boxes);
    usort($boxes, fn($a, $b) => array_search($a["tier"], IMPERIAL_TIERS, true) <=> array_search($b["tier"], IMPERIAL_TIERS, true));

    return ["skill" => $skill, "boxes" => $boxes];
}
