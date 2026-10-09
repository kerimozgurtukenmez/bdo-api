<?php
// Loading recipe ingredients and products for several recipes at once.
// Recipes are passed as rows with "source" and "id" keys.

declare(strict_types=1);

const SKILL_TIERS = ["Beginner", "Apprentice", "Skilled", "Professional", "Artisan", "Master", "Guru"];

function recipe_key(array $recipe): string
{
    return "{$recipe['source']}:{$recipe['id']}";
}

// WHERE fragment and params matching the given recipes
function recipes_in(array $recipes, string $sourceCol, string $idCol): array
{
    $params = [];
    foreach ($recipes as $r) {
        array_push($params, $r["source"], (int)$r["id"]);
    }
    return ["($sourceCol, $idCol) IN (" . placeholders($recipes, "(?, ?)") . ")", $params];
}

// Ingredient slots per recipe: "source:id" => [slot, ...]. Each slot is the
// default ingredient with its substitutes under "alternatives".
function recipe_slots(array $recipes, bool $withAlternatives = true): array
{
    if (!$recipes) {
        return [];
    }

    [$in, $params] = recipes_in($recipes, "ri.recipe_source", "ri.recipe_id");
    $rows = query("
        SELECT ri.recipe_source, ri.recipe_id, ri.slot, ri.item_id, ri.qty_min, ri.qty_max,
               ri.is_key, ri.is_alternative,
               i.name, i.icon, i.grade, i.grade_name, " . PRICE_COLUMNS . "
        FROM recipe_inputs ri
        JOIN items i             ON i.id      = ri.item_id
        LEFT JOIN item_details d ON d.item_id = ri.item_id
        LEFT JOIN item_prices  p ON p.item_id = ri.item_id
        WHERE $in" . ($withAlternatives ? "" : " AND ri.is_alternative = 0") . "
        ORDER BY ri.recipe_source, ri.recipe_id, ri.slot, ri.is_alternative, ri.id
    ", $params)->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $key  = "{$row['recipe_source']}:{$row['recipe_id']}";
        $slot = $row["slot"];
        $item = with_price($row);
        $item["icon"] = icon_url($item["icon"]);
        unset($item["recipe_source"], $item["recipe_id"], $item["slot"], $item["is_alternative"]);

        if (!$row["is_alternative"]) {
            $item["is_key"] = (bool)$item["is_key"];
            $result[$key][$slot] = ["slot" => $slot] + $item + ["alternatives" => []];
        } elseif (isset($result[$key][$slot])) {
            $result[$key][$slot]["alternatives"][] = [
                "item_id"    => $item["item_id"],
                "name"       => $item["name"],
                "icon"       => $item["icon"],
                "grade"      => $item["grade"],
                "grade_name" => $item["grade_name"],
                "qty_min"    => $item["qty_min"],  // may differ from the default's amount
                "price"      => $item["price"],
            ];
        }
    }

    return array_map("array_values", $result);
}

// Cooking and alchemy crafts have a slight chance to give a better product as
// well (Cold Draft Beer when making Beer). The game does not publish the
// chance; the item description names the level it needs:
//   "Slight chance of obtaining Cold Draft Beer when making Beer if at least Cooking Skilled 1" → "Skilled 1"
function rare_requirement(?string $description): ?string
{
    $tiers = implode("|", SKILL_TIERS);
    return preg_match("/chance[^()]*?if at least (?:Cooking|Alchemy) ($tiers) (\\d+)/i", $description ?? "", $m)
        ? "$m[1] $m[2]"
        : null;
}

// What a recipe output is: its main product, a rare product (cooking and
// alchemy) or another product of a process
function output_kind(string $source, bool $isMain): string
{
    return $isMain ? "main" : ($source === "processing" ? "byproduct" : "rare");
}

// Products per recipe: "source:id" => [output, ...], main product first.
// Each output has a "kind" (see output_kind) and, for rare products, the
// life skill level they need ("requires", null when the game text names none).
function recipe_outputs(array $recipes): array
{
    if (!$recipes) {
        return [];
    }

    [$in, $params] = recipes_in($recipes, "ro.recipe_source", "ro.recipe_id");
    $rows = query("
        SELECT ro.recipe_source, ro.recipe_id, ro.item_id, ro.qty_min, ro.qty_max, ro.is_main,
               i.name, i.icon, i.grade, i.grade_name, d.description, " . PRICE_COLUMNS . "
        FROM recipe_outputs ro
        JOIN items i             ON i.id      = ro.item_id
        LEFT JOIN item_details d ON d.item_id = ro.item_id
        LEFT JOIN item_prices  p ON p.item_id = ro.item_id
        WHERE $in
        ORDER BY ro.recipe_source, ro.recipe_id, ro.is_main DESC, ro.id
    ", $params)->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $key = "{$row['recipe_source']}:{$row['recipe_id']}";
        $out = with_price($row);
        $out["icon"] = icon_url($out["icon"]);
        $out["is_main"] = (bool)$out["is_main"];
        $out["kind"]    = output_kind($row["recipe_source"], $out["is_main"]);
        $out["requires"] = $out["kind"] === "rare" ? rare_requirement($row["description"]) : null;
        unset($out["recipe_source"], $out["recipe_id"], $out["description"]);
        $result[$key][] = $out;
    }

    return $result;
}

// Rare products per recipe: "source:id" => [output, ...] (see recipe_outputs)
function recipe_rare_products(array $recipes): array
{
    return array_map(
        fn($outputs) => array_values(array_filter($outputs, fn($out) => $out["kind"] === "rare")),
        recipe_outputs($recipes)
    );
}

// Typed recipe row for JSON output
function format_recipe(array $recipe): array
{
    $recipe = ["key" => recipe_key($recipe)] + $recipe;
    if (array_key_exists("icon", $recipe)) {
        $recipe["icon"] = icon_url($recipe["icon"]);
    }
    if (array_key_exists("ingredients_weight", $recipe) && $recipe["ingredients_weight"] !== null) {
        $recipe["ingredients_weight"] = (float)$recipe["ingredients_weight"];
    }
    return $recipe;
}
