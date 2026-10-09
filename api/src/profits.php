<?php
// "What should I craft now?" — the cost of one unit of every craftable item,
// worked out in one pass over all recipes, and how fast items sell.
//
// The choices are CraftCalculator's in "cheapest" mode: the default recipe
// (config/recipe_defaults.php first), the substitute cheapest to buy, vendor
// goods and usually bought items are bought, an intermediate is bought when
// that costs less than crafting it, and an ingredient whose recipe chain needs
// it again is bought. Amounts are averages per unit; a plan rounds crafts up,
// so the calculator's total can be a little higher.

declare(strict_types=1);

final class ProfitTable
{
    private array $items      = [];  // item id => row with price columns
    private array $candidates = [];  // product id => recipes that make it, best first
    private array $slots      = [];  // "source:id" => [["item_id", "qty", "alternatives" => [id => qty]]]
    private array $memo       = [];  // item id => ["cost", "seconds"] as an ingredient
    private array $visiting   = [];
    private array $looping    = [];  // items whose recipe chain needs them again
    private array $defaults;
    private array $bonus      = [];  // life skill => extra products per craft
    private int   $massSize   = 0;   // processings per Mass Process (0: none)

    public function __construct(private readonly PDO $pdo, array $mastery = [], ?array $recipeDefaults = null)
    {
        $this->defaults = $recipeDefaults ?? require __DIR__ . "/../config/recipe_defaults.php";

        foreach (["cooking", "alchemy"] as $skill) {
            $stmt = $pdo->prepare("SELECT product FROM mastery_bonuses WHERE skill = ? AND mastery <= ? ORDER BY mastery DESC LIMIT 1");
            $stmt->execute([$skill, (int)($mastery[$skill] ?? 0)]);
            $this->bonus[$skill] = (float)$stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT mass FROM processing_mastery WHERE mastery <= ? ORDER BY mastery DESC LIMIT 1");
        $stmt->execute([(int)($mastery["processing"] ?? 0)]);
        $this->massSize = (int)$stmt->fetchColumn();

        $this->load();
    }

    // Every craftable item with a market price, with the cost of one unit:
    // item id => ["item", "recipe", "cost", "price", "stock", "seconds"]
    public function rows(): array
    {
        $rows = [];
        foreach ($this->candidates as $itemId => $recipes) {
            $price = (int)($this->items[$itemId]["base_price"] ?? 0);
            $recipe = $this->defaultRecipe($itemId);
            if ($price <= 0 || $recipe === null) {
                continue;
            }

            $this->visiting[$itemId] = true;
            [$cost, $seconds] = $this->craftCost($recipe);
            unset($this->visiting[$itemId]);

            // Crafting it needs the item itself: not something to craft for profit
            if ($cost === null || isset($this->looping[$itemId])) {
                continue;
            }

            $item = $this->items[$itemId];
            $rows[$itemId] = [
                "item"    => [
                    "id"         => $item["id"],
                    "name"       => $item["name"],
                    "grade"      => $item["grade"],
                    "grade_name" => $item["grade_name"],
                    "icon"       => icon_url($item["icon"]),
                ],
                "recipe"  => [
                    "key"         => "{$recipe['source']}:{$recipe['id']}",
                    "source"      => $recipe["source"],
                    "category"    => $recipe["category"],
                    "skill_level" => $recipe["skill_level"],
                    "skill_sort"  => $recipe["skill_sort"],
                ],
                "cost"    => $cost,
                "price"   => $price,
                "stock"   => (int)$item["current_stock"],
                "seconds" => $seconds,  // processing time per unit, null when a step is not processing
            ];
        }
        return $rows;
    }

    // Items whose default recipe chain needs them again (bought where needed)
    public function loopingItems(): array
    {
        $this->rows();
        return array_keys($this->looping);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function load(): void
    {
        $recipeItems = "(SELECT item_id FROM recipe_inputs UNION SELECT item_id FROM recipe_outputs)";
        foreach ($this->pdo->query("
            SELECT i.id, i.name, i.grade, i.grade_name, i.icon, p.current_stock, " . PRICE_COLUMNS . "
            FROM $recipeItems t
            JOIN items i             ON i.id = t.item_id
            LEFT JOIN item_details d ON d.item_id = t.item_id
            LEFT JOIN item_prices  p ON p.item_id = t.item_id
        ") as $row) {
            $this->items[$row["id"]] = $row;
        }

        foreach ($this->pdo->query("
            SELECT recipe_source, recipe_id, slot, item_id, qty_min, is_alternative
            FROM recipe_inputs ORDER BY recipe_source, recipe_id, slot, is_alternative, id
        ") as $row) {
            $key = "{$row['recipe_source']}:{$row['recipe_id']}";
            if (!$row["is_alternative"]) {
                $this->slots[$key][$row["slot"]] = ["item_id" => $row["item_id"], "qty" => $row["qty_min"], "alternatives" => []];
            } elseif (isset($this->slots[$key][$row["slot"]])) {
                $this->slots[$key][$row["slot"]]["alternatives"][$row["item_id"]] = $row["qty_min"];
            }
        }

        $recipes = $this->pdo->query("
            SELECT r.source, r.id, r.name, r.category, r.skill_level, r.skill_sort,
                   ro.item_id, ro.qty_min, ro.qty_max
            FROM recipes r
            JOIN recipe_outputs ro ON ro.recipe_source = r.source AND ro.recipe_id = r.id AND ro.is_main = 1
        ")->fetchAll();

        foreach ($recipes as $recipe) {
            $itemId = $recipe["item_id"];
            $slots  = $this->slots["{$recipe['source']}:{$recipe['id']}"] ?? [];
            // Recipes that need the product itself (upgrades, repacking) are never used
            if (!$slots || in_array($itemId, array_column($slots, "item_id"), true)) {
                continue;
            }
            $bonus = $this->bonus[$recipe["source"]] ?? 0.0;
            $recipe["yield"] = max(1, ($recipe["qty_min"] + $recipe["qty_max"]) / 2) * (1 + $bonus);
            $this->candidates[$itemId][] = $recipe;
        }

        // The calculator's order: named after the item, cooking/alchemy over processing, lowest id
        foreach ($this->candidates as $itemId => &$list) {
            $name = $this->items[$itemId]["name"] ?? "";
            $rank = fn($r) => [strcasecmp($r["name"], $name) !== 0, CraftCalculator::SOURCE_RANK[$r["source"]] ?? 9, $r["id"]];
            usort($list, fn($a, $b) => $rank($a) <=> $rank($b));
        }
        unset($list);
    }

    private function defaultRecipe(int $itemId): ?array
    {
        $wanted = $this->defaults["recipe"][$itemId] ?? null;
        foreach ($this->candidates[$itemId] ?? [] as $recipe) {
            if ($wanted === null || $wanted === "{$recipe['source']}:{$recipe['id']}") {
                return $recipe;
            }
        }
        return $this->candidates[$itemId][0] ?? null;
    }

    private function unitPrice(int $itemId): ?int
    {
        return isset($this->items[$itemId]) ? (item_price($this->items[$itemId])["unit"] ?? null) : null;
    }

    // [cost, seconds] of one unit used as an ingredient: bought or crafted,
    // whichever is cheaper (bought items take no time)
    private function ingredientCost(int $itemId): array
    {
        if (isset($this->visiting[$itemId])) {
            $this->looping[$itemId] = true;  // the chain came back to it
            return [$this->unitPrice($itemId), 0.0];
        }
        if (isset($this->memo[$itemId])) {
            return $this->memo[$itemId];
        }

        $price  = $this->unitPrice($itemId);
        $recipe = $this->defaultRecipe($itemId);
        $item   = $this->items[$itemId] ?? [];
        if ($recipe === null || !empty($item["vendor_sold"]) || in_array($itemId, $this->defaults["buy"], true)) {
            return $this->memo[$itemId] = [$price, 0.0];
        }

        $this->visiting[$itemId] = true;
        [$craft, $seconds] = $this->craftCost($recipe);
        unset($this->visiting[$itemId]);

        if ($price !== null && (isset($this->looping[$itemId]) || $craft === null || $price < $craft)) {
            return $this->memo[$itemId] = [(float)$price, 0.0];
        }
        return $this->memo[$itemId] = [$craft, $seconds];
    }

    // [cost, seconds] of one unit made with a recipe; null cost when a price is missing
    private function craftCost(array $recipe): array
    {
        $cost = 0.0;
        $seconds = $this->processSeconds($recipe);
        foreach ($this->slots["{$recipe['source']}:{$recipe['id']}"] as $slot) {
            [$itemId, $qty] = $this->cheapestChoice($slot);
            [$unitCost, $unitSeconds] = $this->ingredientCost($itemId);
            if ($unitCost === null) {
                return [null, null];
            }
            $cost += $unitCost * $qty;
            $seconds = $seconds === null || $unitSeconds === null ? null : $seconds + $unitSeconds * $qty;
        }
        return [$cost / $recipe["yield"], $seconds === null ? null : $seconds / $recipe["yield"]];
    }

    // The slot's item cheapest to buy for one craft (the calculator's rule)
    private function cheapestChoice(array $slot): array
    {
        $best = [$slot["item_id"], $slot["qty"]];
        $unit = $this->unitPrice($slot["item_id"]);
        if ($unit === null) {
            return $best;
        }
        $bestCost = $unit * $slot["qty"];
        foreach ($slot["alternatives"] as $id => $qty) {
            $alt = $this->unitPrice($id);
            if ($alt !== null && $alt * $qty < $bestCost) {
                [$best, $bestCost] = [[$id, $qty], $alt * $qty];
            }
        }
        return $best;
    }

    // Seconds of one craft of the recipe itself, in long runs of Mass Processes;
    // null for cooking, alchemy and processing without Mass Process
    private function processSeconds(array $recipe): ?float
    {
        if ($recipe["source"] !== "processing" || !in_array($recipe["category"], CraftCalculator::MASS_PROCESS_CATEGORIES, true)) {
            return null;
        }
        return $this->massSize > 0
            ? CraftCalculator::MASS_PROCESS_SECONDS / $this->massSize
            : (float)CraftCalculator::SINGLE_PROCESS_SECONDS;
    }
}

// Items sold per hour over the last day of market snapshots:
// item id => ["per_hour" => float, "hours" => int]; items with one snapshot only are left out
function market_demand(PDO $pdo, int $hours = 24): array
{
    $rows = $pdo->query("
        SELECT item_id,
               TIMESTAMPDIFF(HOUR, MIN(hour), MAX(hour)) AS hours,
               SUBSTRING_INDEX(GROUP_CONCAT(total_trades ORDER BY hour), ',', 1)      AS first_trades,
               SUBSTRING_INDEX(GROUP_CONCAT(total_trades ORDER BY hour DESC), ',', 1) AS last_trades
        FROM item_market_snapshots
        WHERE hour >= NOW() - INTERVAL $hours HOUR
        GROUP BY item_id
        HAVING hours >= 1
    ")->fetchAll();

    $demand = [];
    foreach ($rows as $row) {
        $demand[$row["item_id"]] = [
            "per_hour" => max(0, (int)$row["last_trades"] - (int)$row["first_trades"]) / (int)$row["hours"],
            "hours"    => (int)$row["hours"],
        ];
    }
    return $demand;
}
