<?php
// Data rules used while importing the bdocodex JSON files (import/import.php).

declare(strict_types=1);

// The game data gives every item a buy price, but only items whose description
// says a vendor sells them can actually be bought for it.
// ("purchased from" is left out: it also matches "made with X purchased from a Shop")
const VENDOR_PATTERN = '/\b(can|may) be (bought|purchased)\b|\bpurchas(e|able) (it )?(from|at)\b|\bsold by\b/i';

function is_vendor_sold(?string $description): bool
{
    return (bool)preg_match(VENDOR_PATTERN, $description ?? "");
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
