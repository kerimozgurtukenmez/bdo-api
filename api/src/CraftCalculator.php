<?php
// Crafting calculator: expands an item into every recipe needed to make it,
// then totals the materials to buy, the crafting steps and the cost.
//
// Each item uses one recipe everywhere in the calculation (the default, or the
// one chosen with $recipeOverrides). Bought instead of crafted: items without a
// recipe, items sold by NPC vendors, items in $forceBuy, ingredients that would
// loop back into themselves and, in "cheapest" mode, items whose price is
// lower than (or known when) the cost of crafting them is not.

declare(strict_types=1);

final class CraftCalculator
{
    public const MAX_DEPTH      = 15;
    public const MAX_TREE_NODES = 2000;

    // Tie-break between sources when an item has recipes in several of them
    private const SOURCE_RANK = ["cooking" => 0, "alchemy" => 0, "processing" => 1];

    private const VISITING = 1;
    private const DONE     = 2;

    private array $items      = [];  // item id => item row incl. price columns
    private array $candidates = [];  // item id => recipes whose main product it is, best first
    private array $recipe     = [];  // item id => chosen recipe, null when bought
    private array $edges      = [];  // item id => ingredient slots of the chosen recipe
    private array $order      = [];  // crafted items, ingredients before products
    private array $state      = [];  // DFS state per item id
    private array $buyReason  = [];  // item id => why it is not crafted
    private array $cycleItems = [];  // items bought because their recipe would loop
    private array $substituted = []; // default item ids a substitute was used for
    private array $warnings   = [];
    private int   $treeNodes  = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $recipeOverrides = [],  // item id => "source:recipe id"
        private readonly array $forceBuy = [],         // item id => true
        private readonly array $substitutes = [],      // default item id => substitute item id
        private readonly string $yieldMode = "avg",    // min | avg | max product per craft
        private readonly string $mode = "craft",       // craft | cheapest
    ) {}

    public function calculate(int $itemId, int $qty): array
    {
        $this->loadItems([$itemId]);
        if (!isset($this->items[$itemId])) {
            throw new ApiError("Item not found", 404);
        }

        $this->explore($itemId, 0);
        if ($this->mode === "cheapest") {
            $this->preferCheaper($itemId);
        }
        $this->loadItems($this->referencedItemIds());

        [$materials, $steps] = $this->totals($itemId, $qty);

        $known   = array_filter($materials, fn($m) => $m["total_price"] !== null);
        $missing = array_values(array_filter($materials, fn($m) => $m["total_price"] === null));
        $cost    = array_sum(array_column($known, "total_price"));

        $rootPrice = item_price($this->items[$itemId]);
        $value     = $rootPrice ? $rootPrice["unit"] * $qty : null;

        foreach (array_keys($this->overridesUnused()) as $id) {
            $this->warnings[] = "Recipe override for item $id ignored: no such recipe makes it";
        }
        foreach (array_diff_key($this->substitutes, $this->substituted) as $default => $wanted) {
            $this->warnings[] = "Substitute $wanted for item $default ignored: no recipe in this plan allows it";
        }
        if ($this->cycleItems) {
            $names = array_map(fn($id) => $this->items[$id]["name"], array_keys($this->cycleItems));
            $this->warnings[] = "Recipe loop cut, bought instead: " . implode(", ", $names);
        }

        $tree = $this->node($itemId, $qty, null, 0);
        if ($this->treeNodes >= self::MAX_TREE_NODES) {
            $this->warnings[] = "Tree truncated at " . self::MAX_TREE_NODES . " nodes; totals are still complete";
        }

        return [
            "item"     => $this->itemRef($itemId) + ["price" => $rootPrice],
            "qty"      => $qty,
            "settings" => [
                "mode"       => $this->mode,
                "yield"      => $this->yieldMode,
                "region"     => config("market")["region"],
                "recipe"     => (object)$this->recipeOverrides,
                "buy"        => array_keys($this->forceBuy),
                "substitute" => (object)$this->substitutes,
            ],
            "cost" => [
                "total"          => $cost,
                "per_unit"       => round($cost / $qty, 2),
                "complete"       => !$missing,
                "missing_prices" => array_map(fn($m) => $m["item"], $missing),
                // Unix time of the oldest market price used, null when none is
                "prices_updated_at" => $this->oldestMarketPrice($materials),
            ],
            "market_value" => [
                "unit"   => $rootPrice["unit"] ?? null,
                "total"  => $value,
                "profit" => $value !== null && !$missing ? $value - $cost : null,
            ],
            "by_source" => $this->bySource($steps),
            "materials" => $materials,
            "steps"     => $steps,
            "tree"      => $tree,
            "warnings"  => $this->warnings,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Graph: pick a recipe per item, depth first, cutting loops
    // ─────────────────────────────────────────────────────────────────────────

    private function explore(int $itemId, int $depth): void
    {
        $this->state[$itemId]  = self::VISITING;
        $this->recipe[$itemId] = $recipe = $this->chooseRecipe($itemId, $depth);

        if ($recipe !== null) {
            $this->edges[$itemId] = [];
            $slots = $this->slots($recipe);
            $this->loadItems(array_column($slots, "item_id"));  // chooseRecipe() needs them

            foreach ($slots as $slot) {
                $childId = $slot["item_id"];

                // An ingredient that is still being expanded above us is a loop
                $slot["cut"] = ($this->state[$childId] ?? null) === self::VISITING;
                if ($slot["cut"]) {
                    $this->cycleItems[$childId] = true;
                } elseif (!isset($this->state[$childId])) {
                    $this->explore($childId, $depth + 1);
                }

                $this->edges[$itemId][] = $slot;
            }

            $this->order[] = $itemId;
        }

        $this->state[$itemId] = self::DONE;
    }

    private function chooseRecipe(int $itemId, int $depth): ?array
    {
        if (isset($this->forceBuy[$itemId])) {
            $this->buyReason[$itemId] = "forced";
            return null;
        }

        $candidates = $this->recipesFor($itemId);
        if (!$candidates) {
            $this->buyReason[$itemId] = "no_recipe";
            return null;
        }

        if ($depth >= self::MAX_DEPTH) {
            $this->buyReason[$itemId] = "max_depth";
            return null;
        }

        $override = $this->recipeOverrides[$itemId] ?? null;

        // Vendor goods (Salt, Sugar, ...) cost next to nothing; only craft them
        // when asked for directly or when a recipe was picked for them.
        if ($depth > 0 && $override === null && !empty($this->items[$itemId]["vendor_sold"])) {
            $this->buyReason[$itemId] = "vendor";
            return null;
        }

        foreach ($candidates as $recipe) {
            if ($override === null || $override === "{$recipe['source']}:{$recipe['id']}") {
                return $recipe;
            }
        }

        return $candidates[0];  // override did not match, reported as a warning
    }

    // Recipes whose main product is the item, best default first. Recipes that
    // need the item itself (upgrades, repacking) are never used.
    public function recipesFor(int $itemId): array
    {
        if (isset($this->candidates[$itemId])) {
            return $this->candidates[$itemId];
        }

        $stmt = $this->pdo->prepare("
            SELECT r.source, r.id, r.name, r.category, r.skill_level, r.skill_sort, r.exp,
                   ro.qty_min AS output_min, ro.qty_max AS output_max,
                   r.name = i.name AS name_match
            FROM recipe_outputs ro
            JOIN recipes r ON r.source = ro.recipe_source AND r.id = ro.recipe_id
            JOIN items i   ON i.id = ro.item_id
            WHERE ro.item_id = ? AND ro.is_main = 1
              AND NOT EXISTS (
                  SELECT 1 FROM recipe_inputs ri
                  WHERE ri.recipe_source = r.source AND ri.recipe_id = r.id
                    AND ri.item_id = ro.item_id AND ri.is_alternative = 0
              )
        ");
        $stmt->execute([$itemId]);
        $recipes = $stmt->fetchAll();

        // Prefer the recipe named after the item, then cooking/alchemy over
        // processing, then the lowest id (bdocodex lists the standard recipe
        // first; equipment melting and similar variants come later).
        $rank = fn($r) => [-$r["name_match"], self::SOURCE_RANK[$r["source"]] ?? 9, $r["id"]];
        usort($recipes, fn($a, $b) => $rank($a) <=> $rank($b));

        foreach ($recipes as &$recipe) {
            $yield = match ($this->yieldMode) {
                "min"   => $recipe["output_min"],
                "max"   => $recipe["output_max"],
                default => ($recipe["output_min"] + $recipe["output_max"]) / 2,
            };
            $recipe["yield"] = max(1, $yield);
            unset($recipe["name_match"]);
        }
        unset($recipe);

        return $this->candidates[$itemId] = $recipes;
    }

    // Ingredient slots of a recipe with substitutions applied
    private function slots(array $recipe): array
    {
        $stmt = $this->pdo->prepare("
            SELECT slot, item_id, qty_min, is_key, is_alternative
            FROM recipe_inputs
            WHERE recipe_source = ? AND recipe_id = ?
            ORDER BY slot, is_alternative, id
        ");
        $stmt->execute([$recipe["source"], $recipe["id"]]);

        $slots = [];
        foreach ($stmt->fetchAll() as $row) {
            $n = $row["slot"];
            if (!$row["is_alternative"]) {
                $slots[$n] = [
                    "slot"            => $n,
                    "item_id"         => $row["item_id"],
                    "default_item_id" => $row["item_id"],
                    "per_craft"       => $row["qty_min"],
                    "is_key"          => (bool)$row["is_key"],
                    "alternatives"    => [],
                ];
            } elseif (isset($slots[$n])) {
                $slots[$n]["alternatives"][] = $row["item_id"];
            }
        }

        foreach ($slots as &$slot) {
            $wanted = $this->substitutes[$slot["default_item_id"]] ?? null;
            if ($wanted !== null && in_array($wanted, $slot["alternatives"], true)) {
                $slot["item_id"] = $wanted;
                $this->substituted[$slot["default_item_id"]] = true;
            }
        }

        return array_values($slots);
    }

    // "cheapest" mode: going from raw materials up, buy an intermediate instead
    // of crafting it when its price is lower than the crafting cost, or when
    // the crafting cost is unknown. The requested item and items with a chosen
    // recipe are always crafted.
    private function preferCheaper(int $rootId): void
    {
        $unitCost = [];  // item id => cost of one unit as planned, null = unknown

        foreach ($this->order as $itemId) {
            $craftCost = 0.0;
            foreach ($this->edges[$itemId] as $slot) {
                $childId = $slot["item_id"];
                $cost = $slot["cut"] || $this->recipe[$childId] === null
                    ? $this->unitPrice($childId)
                    : $unitCost[$childId];

                if ($cost === null) {
                    $craftCost = null;
                    break;
                }
                $craftCost += $cost * $slot["per_craft"];
            }
            if ($craftCost !== null) {
                $craftCost /= $this->recipe[$itemId]["yield"];
            }

            $price = $this->unitPrice($itemId);
            $keep  = $itemId === $rootId || isset($this->recipeOverrides[$itemId]);

            if (!$keep && $price !== null && ($craftCost === null || $price < $craftCost)) {
                $this->recipe[$itemId]    = null;
                $this->buyReason[$itemId] = "cheaper";
                $unitCost[$itemId] = $price;
            } else {
                $unitCost[$itemId] = $craftCost;
            }
        }
    }

    private function unitPrice(int $itemId): ?int
    {
        return item_price($this->items[$itemId])["unit"] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Totals: demand flows from the product down to the raw materials
    // ─────────────────────────────────────────────────────────────────────────

    private function totals(int $rootId, int $qty): array
    {
        $demand = [$rootId => $qty];  // units to craft or buy
        $bought = [];                 // units to buy
        $steps  = [];

        if ($this->recipe[$rootId] === null) {
            $bought[$rootId] = $qty;
        }

        // Products are handled before their ingredients, so every item has
        // received its full demand by the time it is crafted. Crafts are
        // rounded up once per item, not once per branch.
        foreach (array_reverse($this->order) as $itemId) {
            $need = $demand[$itemId] ?? 0;
            if ($need <= 0) {
                continue;
            }

            $recipe = $this->recipe[$itemId];
            $crafts = (int)ceil($need / $recipe["yield"]);

            foreach ($this->edges[$itemId] as $slot) {
                $childId = $slot["item_id"];
                $units   = $crafts * $slot["per_craft"];

                if ($slot["cut"] || $this->recipe[$childId] === null) {
                    $bought[$childId] = ($bought[$childId] ?? 0) + $units;
                } else {
                    $demand[$childId] = ($demand[$childId] ?? 0) + $units;
                }
            }

            $steps[] = [
                "item"     => $this->itemRef($itemId),
                "recipe"   => $this->recipeRef($recipe),
                "needed"   => $need,
                "crafts"   => $crafts,
                "produced" => round($crafts * $recipe["yield"], 2),
                "exp"      => $recipe["exp"] !== null ? $crafts * $recipe["exp"] : null,
            ];
        }

        $materials = [];
        foreach ($bought as $itemId => $units) {
            $price = item_price($this->items[$itemId]);
            $materials[] = [
                "item"        => $this->itemRef($itemId),
                "qty"         => $units,
                "price"       => $price,
                "total_price" => $price ? $price["unit"] * $units : null,
                "reason"      => $this->buyReason[$itemId] ?? "loop",
            ];
        }

        // Most expensive first, unknown prices last
        usort($materials, fn($a, $b) => [$a["total_price"] === null, -($a["total_price"] ?? 0)]
                                    <=> [$b["total_price"] === null, -($b["total_price"] ?? 0)]);

        return [$materials, array_reverse($steps)];
    }

    private function oldestMarketPrice(array $materials): ?int
    {
        $times = [];
        foreach ($materials as $m) {
            if (($m["price"]["source"] ?? null) === "market") {
                $times[] = (int)$this->items[$m["item"]["id"]]["price_updated_at"];
            }
        }
        return $times ? min($times) : null;
    }

    private function bySource(array $steps): array
    {
        $result = [];
        foreach ($steps as $step) {
            $source = $step["recipe"]["source"];
            $result[$source] ??= ["steps" => 0, "crafts" => 0, "exp" => 0];
            $result[$source]["steps"]++;
            $result[$source]["crafts"] += $step["crafts"];
            $result[$source]["exp"]    += $step["exp"] ?? 0;
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tree: the same recipes, unfolded per branch for display
    // ─────────────────────────────────────────────────────────────────────────

    private function node(int $itemId, int $qty, ?array $slot, int $depth): array
    {
        $this->treeNodes++;

        $price = item_price($this->items[$itemId]);
        $node  = [
            "item"  => $this->itemRef($itemId),
            "qty"   => $qty,
            "price" => $price,
        ];

        if ($slot !== null) {
            $node["slot"] = [
                "per_craft"       => $slot["per_craft"],
                "is_key"          => $slot["is_key"],
                "default_item_id" => $slot["default_item_id"],
                "alternatives"    => array_map(
                    fn($id) => $this->itemRef($id) + ["price" => item_price($this->items[$id])],
                    array_values(array_unique(array_merge([$slot["default_item_id"]], $slot["alternatives"])))
                ),
            ];
        }

        $recipe = $this->recipe[$itemId];
        if ($recipe === null || !empty($slot["cut"])) {
            $node["action"] = "buy";
            $node["reason"] = !empty($slot["cut"]) ? "loop" : $this->buyReason[$itemId];
            $node["cost"]   = $price ? $price["unit"] * $qty : null;
            $node["cost_complete"] = $price !== null;
            return $node;
        }

        $crafts = (int)ceil($qty / $recipe["yield"]);
        $node["action"] = "craft";
        $node["recipe"] = $this->recipeRef($recipe) + [
            "crafts"        => $crafts,
            "other_recipes" => count($this->candidates[$itemId]) - 1,
        ];

        if ($this->treeNodes >= self::MAX_TREE_NODES) {
            $node["truncated"] = true;
            $node["children"]  = [];
            $node["cost"] = null;
            $node["cost_complete"] = false;
            return $node;
        }

        $node["children"] = [];
        $cost = 0;
        $complete = true;
        foreach ($this->edges[$itemId] as $child) {
            $childNode = $this->node($child["item_id"], $crafts * $child["per_craft"], $child, $depth + 1);
            $node["children"][] = $childNode;
            $cost += $childNode["cost"] ?? 0;
            $complete = $complete && $childNode["cost_complete"];
        }
        $node["cost"] = $cost;
        $node["cost_complete"] = $complete;

        return $node;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function loadItems(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->items)));
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(",", array_fill(0, count($chunk), "?"));
            $stmt = $this->pdo->prepare("
                SELECT i.id, i.name, i.grade, i.grade_name, i.icon, " . PRICE_COLUMNS . "
                FROM items i
                LEFT JOIN item_details d ON d.item_id = i.id
                LEFT JOIN item_prices  p ON p.item_id = i.id
                WHERE i.id IN ($placeholders)
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $this->items[$row["id"]] = $row;
            }
        }
    }

    // Every item the result mentions: crafted items, ingredients, substitutes
    private function referencedItemIds(): array
    {
        $ids = array_keys($this->recipe);
        foreach ($this->edges as $slots) {
            foreach ($slots as $slot) {
                $ids[] = $slot["item_id"];
                $ids[] = $slot["default_item_id"];
                array_push($ids, ...$slot["alternatives"]);
            }
        }
        return $ids;
    }

    private function overridesUnused(): array
    {
        $unused = [];
        foreach ($this->recipeOverrides as $itemId => $key) {
            $match = array_filter($this->candidates[$itemId] ?? [], fn($r) => "{$r['source']}:{$r['id']}" === $key);
            if (!$match) {
                $unused[$itemId] = $key;
            }
        }
        return $unused;
    }

    private function itemRef(int $itemId): array
    {
        $item = $this->items[$itemId];
        return [
            "id"         => $item["id"],
            "name"       => $item["name"],
            "grade"      => $item["grade"],
            "grade_name" => $item["grade_name"],
            "icon"       => icon_url($item["icon"]),
        ];
    }

    private function recipeRef(array $recipe): array
    {
        return [
            "source"      => $recipe["source"],
            "id"          => $recipe["id"],
            "key"         => "{$recipe['source']}:{$recipe['id']}",
            "name"        => $recipe["name"],
            "category"    => $recipe["category"],
            "skill_level" => $recipe["skill_level"],
            "exp"         => $recipe["exp"],
            "output_min"  => $recipe["output_min"],
            "output_max"  => $recipe["output_max"],
            "yield"       => $recipe["yield"],
        ];
    }
}
