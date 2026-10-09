<?php
// Data rules used while importing the bdocodex JSON files (bin/import.php).

declare(strict_types=1);

// The game data gives every item a buy price, but only items whose description
// says a vendor sells them can actually be bought for it.
// ("purchased from" is left out: it also matches "made with X purchased from a Shop")
const VENDOR_PATTERN = '/\b(can|may) be (bought|purchased)\b|\bpurchas(e|able) (it )?(from|at)\b|\bsold by\b/i';

function is_vendor_sold(?string $description): bool
{
    return (bool)preg_match(VENDOR_PATTERN, $description ?? "");
}

// Icons are stored by their path on the source site, so they can be served
// from our own copy or the source (see icon_url()).
function icon_path(?string $url): ?string
{
    if ($url === null || $url === "") {
        return null;
    }

    $source = rtrim(config("icons")["source"], "/") . "/";
    return str_starts_with($url, $source) ? substr($url, strlen($source)) : $url;
}

// Create missing tables from database/schema.sql
function apply_schema(PDO $pdo): void
{
    $schema = preg_replace('/^\s*--.*$/m', "", file_get_contents(__DIR__ . "/../database/schema.sql"));
    foreach (array_filter(array_map("trim", explode(";", $schema))) as $statement) {
        $pdo->exec($statement);
    }
}

// ingredient_ids lists the slots in recipe order, each slot as its default
// ingredient followed by the items that may replace it:
//   [default0, alt, alt, default1, default2, alt, ...]
// Returns slot => list of alternative item ids, or null if the list does not
// follow that layout. An empty list means the recipe has no substitutes.
function group_alternatives(array $ingredients, array $ingredientIds): ?array
{
    $alternatives = array_fill(0, count($ingredients), []);
    if (!$ingredientIds) {
        return $alternatives;
    }

    $next = 0;        // next default we expect to see
    $slot = null;     // slot the current alternatives belong to

    foreach ($ingredientIds as $id) {
        if ($next < count($ingredients) && $id === $ingredients[$next]["item_id"]) {
            $slot = $next++;
            continue;
        }
        if ($slot === null) {
            return null;
        }
        if ($id !== $ingredients[$slot]["item_id"] && !in_array($id, $alternatives[$slot], true)) {
            $alternatives[$slot][] = $id;
        }
    }

    return $next === count($ingredients) ? $alternatives : null;
}

// Index of the product the recipe is named after; otherwise the first output.
function main_output_index(array $recipe, array $itemNames): int
{
    foreach ($recipe["output"] as $i => $out) {
        if (strcasecmp($itemNames[$out["item_id"]] ?? "", trim($recipe["name"])) === 0) {
            return $i;
        }
    }
    return 0;
}

// bdocodex lists a recipe once with its substitute groups, and again for some
// fixed ingredient combinations of it (Citron Tea: 2 waters × 3 citrons = 6
// recipes). Such a variant is folded into the main recipe of its product and
// category (named after the product, lowest id): if every ingredient fits the
// main recipe's slots it is dropped; if one does not, that ingredient becomes
// a substitute of the remaining slot with its own amount (e.g. Purified Water
// ×3 for Mineral Water ×6). A variant also gives the exact amount of a
// substitute the main recipe lists without one.
//
// Processing recipes fold only with the same products ($sameOutputs): melting
// different equipment into Melted Iron Shard gives different amounts, so those
// stay recipes of their own.
//
// $recipes: id => ["name", "category", "output", "main" => product item id,
//                  "slots" => [["item_id", "qty", "alternatives" => [item id => qty or null (unknown)]]]]
// Returns [the recipes to keep, ids of the folded variants].
function fold_recipe_variants(array $recipes, array $itemNames, bool $sameOutputs = false): array
{
    $byProduct = [];
    foreach ($recipes as $id => $recipe) {
        $key = $recipe["main"] . "|" . ($recipe["category"] ?? "");
        if ($sameOutputs) {
            $outputs = array_map(fn($o) => [$o["item_id"], $o["qty_min"] ?? 1, $o["qty_max"] ?? 1], $recipe["output"] ?? []);
            sort($outputs);
            $key .= "|" . json_encode($outputs);
        }
        $byProduct[$key][] = $id;
    }

    $folded = [];
    foreach ($byProduct as $ids) {
        if (count($ids) < 2) {
            continue;
        }

        $product = $recipes[$ids[0]]["main"];
        $rank = fn($id) => [strcasecmp(trim($recipes[$id]["name"]), $itemNames[$product] ?? "") !== 0, $id];
        usort($ids, fn($a, $b) => $rank($a) <=> $rank($b));
        $mainId = array_shift($ids);

        // A variant may only fit once a later one has added its substitute
        // (Dressing 474 needs both 475's and 547's), so repeat until none fits
        do {
            $changed = false;
            foreach ($ids as $i => $id) {
                $slots = fold_variant($recipes[$mainId]["slots"], $recipes[$id]["slots"]);
                if ($slots !== null) {
                    $recipes[$mainId]["slots"] = $slots;
                    $folded[] = $id;
                    unset($recipes[$id], $ids[$i]);
                    $changed = true;
                }
            }
        } while ($changed && $ids);
    }

    sort($folded);
    return [$recipes, $folded];
}

// The main recipe's slots with a variant folded in, or null if the other
// recipe differs in more than one slot (then it is a recipe of its own)
function fold_variant(array $main, array $variant): ?array
{
    if (count($main) !== count($variant)) {
        return null;
    }

    $free      = array_keys($main);  // main slots no variant ingredient has used yet
    $unmatched = [];
    $learned   = [];                 // [slot, item, qty]: substitute amounts the variant shows
    foreach ($variant as $ingredient) {
        $item = $ingredient["item_id"];
        $hit  = null;
        foreach ($free as $i => $s) {
            $group = [$main[$s]["item_id"] => $main[$s]["qty"]] + $main[$s]["alternatives"];
            if (array_key_exists($item, $group) && ($group[$item] === null || $group[$item] === $ingredient["qty"])) {
                $hit = $i;
                if ($group[$item] === null) {
                    $learned[] = [$s, $item, $ingredient["qty"]];
                }
                break;
            }
        }
        if ($hit === null) {
            $unmatched[] = $ingredient;
        } else {
            unset($free[$hit]);
        }
    }

    if (count($unmatched) > 1) {
        return null;
    }
    foreach ($learned as [$s, $item, $qty]) {
        $main[$s]["alternatives"][$item] = $qty;
    }
    if ($unmatched) {
        $slot = reset($free);
        $item = $unmatched[0]["item_id"];
        // An item the slot already offers keeps the main recipe's amount
        if ($item !== $main[$slot]["item_id"] && !isset($main[$slot]["alternatives"][$item])) {
            $main[$slot]["alternatives"][$item] = $unmatched[0]["qty"];
        }
    }

    return $main;
}

// A substitute of another grade needs another amount: every grade step up
// halves it (white 4 → green 2 → blue 1, rounded down, at least 1), every step
// down doubles it. Used when the source gives no exact amount; bdocodex's own
// rule is a range (1 green = 2–3 white, 1 blue = 3–5 white).
function estimate_substitute_qty(int $qty, int $grade, int $substituteGrade): int
{
    $steps = $substituteGrade - $grade;
    return $steps >= 0 ? max(1, intdiv($qty, 2 ** $steps)) : $qty * 2 ** -$steps;
}

// Fills in the substitute amounts nothing gave exactly. Estimates that differ
// from the default's amount are listed under "estimated" (item ids); the same
// grade, or 1 of a better one, keeps the default's amount.
function fill_substitute_amounts(array $slots, array $itemGrades): array
{
    foreach ($slots as &$slot) {
        $slot["estimated"] = [];
        foreach ($slot["alternatives"] as $item => &$qty) {
            if ($qty !== null) {
                continue;
            }
            $grade    = $itemGrades[$slot["item_id"]] ?? 0;
            $altGrade = $itemGrades[$item] ?? 0;
            $qty = estimate_substitute_qty($slot["qty"], $grade, $altGrade);
            if ($qty !== $slot["qty"]) {
                $slot["estimated"][] = $item;
            }
        }
        unset($qty);
    }
    unset($slot);
    return $slots;
}
