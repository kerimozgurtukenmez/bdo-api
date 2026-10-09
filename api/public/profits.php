<?php
// GET profits.php — what to craft now: every craftable item with a market price,
// the cost of one unit, what it sells for after tax, and how fast it sells.
//
//   keep=0.845                       share of a sale kept after the market tax (default 0.845)
//   mastery[cooking]=1500 ...        as in craft.php (processing mastery sets the time)
//   source=cooking|alchemy|processing  category=Heating  skill=Apprentice  search=beer
//   sort=profit|margin|per_hour|demand   (default profit; all descending)
//   opportunities=1                  only items that pay and sell fast (see below)
//   page=1  limit=50
//
// Each row: item, recipe (the default one), cost (one unit), price (market),
// sale (price × keep), profit, margin (profit / cost), stock (on the market),
// sold_per_hour (from the hourly snapshots; null before two of them exist),
// seconds (processing time of one unit, null unless every step is processing),
// per_hour (profit per hour of processing), demand (profit × sold_per_hour:
// what the market takes per hour), opportunity.
//
// An opportunity pays at least 10% over its cost, sold at least once an hour
// lately, and has less on the market than two hours of sales.

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/CraftCalculator.php";
require __DIR__ . "/../src/mastery.php";
require __DIR__ . "/../src/profits.php";
api_init();

const SORTS = ["profit", "margin", "per_hour", "demand"];

$keep     = (float)($_GET["keep"] ?? 0.845);
if ($keep < 0.5 || $keep > 1) {
    throw new ApiError("'keep' must be between 0.5 and 1");
}
$source   = param_enum("source", RECIPE_SOURCES);
$category = param_str("category");
$skill    = param_str("skill");
$search   = param_str("search");
$sort     = param_enum("sort", SORTS, "profit");
$onlyOpportunities = param_bool("opportunities");
[$page, $limit, $offset] = pagination();

$pdo    = db();
$demand = market_demand($pdo);
$rows   = [];

foreach ((new ProfitTable($pdo, param_mastery()))->rows() as $itemId => $row) {
    $r = $row["recipe"];
    if (($source && $r["source"] !== $source) || ($category && $r["category"] !== $category)
        || ($skill && !str_starts_with((string)$r["skill_level"], "$skill "))
        || ($search && stripos($row["item"]["name"], $search) === false)) {
        continue;
    }

    $sale    = $row["price"] * $keep;
    $profit  = $sale - $row["cost"];
    $margin  = $row["cost"] > 0 ? $profit / $row["cost"] : null;
    $sold    = $demand[$itemId]["per_hour"] ?? null;
    $perHour = $row["seconds"] ? $profit * 3600 / $row["seconds"] : null;

    $row["opportunity"] = $profit > 0 && $margin !== null && $margin >= 0.10
        && $sold !== null && $sold >= 1 && $row["stock"] <= 2 * $sold;
    if ($onlyOpportunities && !$row["opportunity"]) {
        continue;
    }

    unset($row["recipe"]["skill_sort"]);
    $rows[] = [
        "item"          => $row["item"],
        "recipe"        => $row["recipe"],
        "cost"          => (int)round($row["cost"]),
        "price"         => $row["price"],
        "sale"          => (int)round($sale),
        "profit"        => (int)round($profit),
        "margin"        => $margin !== null ? round($margin, 4) : null,
        "stock"         => $row["stock"],
        "sold_per_hour" => $sold !== null ? round($sold, 1) : null,
        "seconds"       => $row["seconds"] !== null ? round($row["seconds"], 2) : null,
        "per_hour"      => $perHour !== null ? (int)round($perHour) : null,
        "demand"        => $sold !== null && $profit > 0 ? (int)round($profit * $sold) : null,
        "opportunity"   => $row["opportunity"],
    ];
}

// Highest first; unknown values last
usort($rows, fn($a, $b) => [$b[$sort] !== null, $b[$sort]] <=> [$a[$sort] !== null, $a[$sort]]);

json_out(paginated(array_slice($rows, $offset, $limit), count($rows), $page, $limit) + [
    "keep"         => $keep,
    // Hours of market snapshots behind sold_per_hour (0: not enough data yet)
    "demand_hours" => $demand ? max(array_column($demand, "hours")) : 0,
]);
