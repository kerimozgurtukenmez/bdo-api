<?php
// Crafting calculator: everything needed to make `qty` of an item.
//
// GET craft.php?item_id=9003&qty=100
//     &mode=craft|cheapest           craft: craft everything craftable (default)
//                                    cheapest: buy intermediates when that costs less
//     &yield=avg|min|max             products per craft used for the math (default avg)
//     &recipe[9003]=cooking:106      use this recipe for an item (see recipes.php?grouped=1)
//     &buy=9017,9018  or  buy[]=9017 buy these items instead of crafting them
//     &substitute[7313]=7304         use a substitute ingredient wherever it is allowed
//     &mastery[cooking]=1500         mastery adds products to cooking / alchemy crafts (0–3000);
//                                    mastery[processing] sets the Mass Process size (processing time)
//     &have[9059]=30                 units the player already has; used before crafting or buying
//
// Response: materials to buy, crafting steps in order, total cost and the full
// recipe tree. Totals round crafts up once per item; the tree rounds per branch.

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/recipes.php";
require __DIR__ . "/../src/CraftCalculator.php";
require __DIR__ . "/../src/mastery.php";
api_init();

// "1,2,3" or ids[]=1&ids[]=2 → [1 => true, 2 => true, ...]
function param_id_set(string $name): array
{
    $raw = $_GET[$name] ?? [];
    $raw = is_array($raw) ? $raw : explode(",", (string)$raw);

    $set = [];
    foreach ($raw as $value) {
        $value = trim((string)$value);
        if ($value === "") {
            continue;
        }
        if (!ctype_digit($value)) {
            throw new ApiError("'$name' must be a list of item ids");
        }
        $set[(int)$value] = true;
    }
    return $set;
}

// name[itemId]=value → [itemId => value], values checked against a pattern
function param_id_map(string $name, string $pattern, string $hint): array
{
    $raw = $_GET[$name] ?? [];
    if (!is_array($raw)) {
        throw new ApiError("'$name' must look like {$name}[item id]=$hint");
    }

    $map = [];
    foreach ($raw as $key => $value) {
        if (!ctype_digit((string)$key) || !is_string($value) || !preg_match($pattern, $value)) {
            throw new ApiError("'$name' must look like {$name}[item id]=$hint");
        }
        $map[(int)$key] = $value;
    }
    return $map;
}

// mastery[cooking]=1500 → ["cooking" => 1500]
function param_mastery(): array
{
    $raw = $_GET["mastery"] ?? [];
    $error = "'mastery' must look like mastery[cooking]=1500 (cooking or alchemy, 0–" . MAX_MASTERY . ")";
    if (!is_array($raw)) {
        throw new ApiError($error);
    }

    $mastery = [];
    foreach ($raw as $skill => $value) {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if (!in_array($skill, MASTERY_SKILLS, true) || $value === false || $value < 0 || $value > MAX_MASTERY) {
            throw new ApiError($error);
        }
        $mastery[$skill] = $value;
    }
    return $mastery;
}

// have[9059]=30 → [9059 => 30]; zero amounts are left out
function param_stock(): array
{
    $stock = [];
    foreach (param_id_map("have", '/^\d{1,9}$/', "units you have (0–999999999)") as $itemId => $units) {
        if ((int)$units > 0) {
            $stock[$itemId] = (int)$units;
        }
    }
    return $stock;
}

$itemId = param_int("item_id") ?? param_int("id") ?? throw new ApiError("'item_id' is required");
$qty    = param_int("qty", 1, 1, 1_000_000);

$calculator = new CraftCalculator(
    db(),
    recipeOverrides: param_id_map("recipe", '/^(' . implode("|", RECIPE_SOURCES) . '):\d+$/', "source:recipe id"),
    forceBuy:        param_id_set("buy"),
    substitutes:     array_map("intval", param_id_map("substitute", '/^\d+$/', "substitute item id")),
    yieldMode:       param_enum("yield", ["avg", "min", "max"], "avg"),
    mode:            param_enum("mode", ["craft", "cheapest"], "craft"),
    mastery:         param_mastery(),
    stock:           param_stock(),
);

json_out($calculator->calculate($itemId, $qty));
