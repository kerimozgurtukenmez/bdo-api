<?php
// GET recipes.php?source=cooking&id=106      one recipe with ingredient slots and products
// GET recipes.php?item_id=9003&grouped=1     recipes that make an item, grouped by source/category
// GET recipes.php?categories=1               recipe categories per source, with counts
// GET recipes.php                            list, filters:
//       source=cooking|alchemy|processing  category=Heating  skill=Apprentice
//       search=sauce  item_id=9003 (makes it, also as byproduct)  ingredient_id=9065 (uses it)
//       with_ingredients=1 (default ingredients of each recipe)  page=1  limit=50

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/recipes.php";
require __DIR__ . "/../src/CraftCalculator.php";
api_init();

$id           = param_int("id");
$source       = param_enum("source", RECIPE_SOURCES);
$itemId       = param_int("item_id");
$ingredientId = param_int("ingredient_id");

// item_id: the main product, what the calculator should be opened with
const RECIPE_COLUMNS = "r.source, r.id, r.name, r.category, r.grade, r.grade_name, r.icon, r.link,
                        r.skill_level, r.exp, r.ingredients_weight,
                        (SELECT ro.item_id FROM recipe_outputs ro
                         WHERE ro.recipe_source = r.source AND ro.recipe_id = r.id AND ro.is_main = 1
                         LIMIT 1) AS item_id";

// ── Categories per source, for filters ───────────────────────────────────────
if (param_bool("categories")) {
    $rows = query("
        SELECT source, category, COUNT(*) AS recipes
        FROM recipes
        GROUP BY source, category
        ORDER BY source, recipes DESC
    ")->fetchAll();

    $result = array_fill_keys(RECIPE_SOURCES, []);
    foreach ($rows as $row) {
        $result[$row["source"]][] = ["category" => $row["category"], "recipes" => $row["recipes"]];
    }
    json_out($result);
}

// ── Single recipe ────────────────────────────────────────────────────────────
if ($id !== null) {
    if ($source === null) {
        throw new ApiError("'source' is required with 'id' (recipe ids are unique per source)");
    }

    $recipe = query("SELECT " . RECIPE_COLUMNS . " FROM recipes r WHERE r.source = ? AND r.id = ?", [$source, $id])->fetch();
    if (!$recipe) {
        throw new ApiError("Recipe not found", 404);
    }

    $recipe = format_recipe($recipe);
    $recipe["ingredients"] = recipe_slots([$recipe])[$recipe["key"]] ?? [];
    $recipe["outputs"]     = recipe_outputs([$recipe])[$recipe["key"]] ?? [];

    json_out($recipe);
}

// ── Recipes that make an item, grouped ───────────────────────────────────────
if ($itemId !== null && param_bool("grouped")) {
    $item = query("
        SELECT i.id, i.name, i.icon, i.grade, i.grade_name, " . PRICE_COLUMNS . "
        FROM items i
        LEFT JOIN item_details d ON d.item_id = i.id
        LEFT JOIN item_prices  p ON p.item_id = i.id
        WHERE i.id = ?
    ", [$itemId])->fetch();

    if (!$item) {
        throw new ApiError("Item not found", 404);
    }

    // Same recipes, in the same order, as the calculator considers
    $recipes = (new CraftCalculator(db()))->recipesFor($itemId);
    $slots   = recipe_slots($recipes);

    $groups = [];
    foreach ($recipes as $i => $recipe) {
        $groupKey = "{$recipe['source']}_{$recipe['category']}";
        $groups[$groupKey] ??= [
            "source"   => $recipe["source"],
            "category" => $recipe["category"],
            "recipes"  => [],
        ];

        $key = recipe_key($recipe);
        $groups[$groupKey]["recipes"][] = [
            "key"         => $key,
            "recipe_id"   => $recipe["id"],
            "name"        => $recipe["name"],
            "skill_level" => $recipe["skill_level"],
            "exp"         => $recipe["exp"],
            "output_min"  => $recipe["output_min"],
            "output_max"  => $recipe["output_max"],
            "is_default"  => $i === 0,
            "ingredients" => $slots[$key] ?? [],
        ];
    }

    json_out([
        "output_item"   => ["icon" => icon_url($item["icon"])] + with_price($item),
        "total_recipes" => count($recipes),
        "default"       => $recipes ? recipe_key($recipes[0]) : null,
        "groups"        => array_values($groups),
    ]);
}

// ── List ─────────────────────────────────────────────────────────────────────
[$page, $limit, $offset] = pagination();
$category = param_str("category");
$skill    = param_enum("skill", SKILL_TIERS);
$search   = param_str("search");

$where  = [];
$params = [];

if ($source !== null) {
    $where[]  = "r.source = ?";
    $params[] = $source;
}
if ($category !== null) {
    $where[]  = "r.category = ?";
    $params[] = $category;
}
if ($skill !== null) {
    $where[]  = "r.skill_level LIKE ?";
    $params[] = "$skill %";
}
if ($search !== null) {
    $where[]  = "r.name LIKE ?";
    $params[] = like_contains($search);
}
if ($itemId !== null) {
    $where[]  = "EXISTS (SELECT 1 FROM recipe_outputs ro WHERE ro.recipe_source = r.source AND ro.recipe_id = r.id AND ro.item_id = ?)";
    $params[] = $itemId;
}
if ($ingredientId !== null) {
    $where[]  = "EXISTS (SELECT 1 FROM recipe_inputs ri WHERE ri.recipe_source = r.source AND ri.recipe_id = r.id AND ri.item_id = ?)";
    $params[] = $ingredientId;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$recipes = query("
    SELECT " . RECIPE_COLUMNS . "
    FROM recipes r
    $whereSql
    ORDER BY r.source, r.category, r.skill_sort, r.name, r.id
    LIMIT ? OFFSET ?
", [...$params, $limit, $offset])->fetchAll();

$recipes = array_map("format_recipe", $recipes);

if (param_bool("with_ingredients")) {
    $slots = recipe_slots($recipes, withAlternatives: false);
    foreach ($recipes as &$recipe) {
        $recipe["ingredients"] = array_map(function ($slot) {
            unset($slot["alternatives"]);
            return $slot;
        }, $slots[$recipe["key"]] ?? []);
    }
    unset($recipe);
}

$total = (int)query("SELECT COUNT(*) FROM recipes r $whereSql", $params)->fetchColumn();

json_out(paginated($recipes, $total, $page, $limit));
