<?php
// ─────────────────────────────────────────────────────────────────────────────
// Refreshes Central Market prices from arsha.io for every item used in a
// recipe (ingredients, substitutes and products).
//
//   php bin/update_prices.php              recipe items not updated in the last hour
//                                          (items not on the market: in the last day)
//   php bin/update_prices.php --force      all recipe items
//   php bin/update_prices.php 9065 7313    only these item ids
//
// The market API rate-limits; failed batches are skipped and picked up by the
// next run, since only stale prices are fetched.
//
// Region and API url are set in config/config.php ("market").
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/prices.php";

set_time_limit(0);

const BATCH_SIZE    = 100;   // arsha.io silently returns zeros past 100 ids per request
const REQUEST_DELAY = 2000;  // ms between requests; faster runs get blocked upstream
const MAX_RETRIES   = 3;     // per batch, waiting 10s, 30s, 90s
const STALE_AFTER   = 60;    // minutes before a stored price is fetched again
// Over half the recipe items are never on the market (quest items, untradeable
// intermediates); asking for them every hour only gets the run throttled
const NO_MARKET_STALE_AFTER = 1440;

$pdo    = db();
$market = config("market");
$start  = microtime(true);
$force  = in_array("--force", $argv, true);

$ids = array_map("intval", array_filter(array_slice($argv, 1), "ctype_digit"));
if (!$ids) {
    $ids = $pdo->query("
        SELECT t.item_id
        FROM (SELECT item_id FROM recipe_inputs UNION SELECT item_id FROM recipe_outputs) t
        LEFT JOIN item_prices p ON p.item_id = t.item_id
        " . ($force ? "" : "WHERE p.item_id IS NULL
                               OR p.updated_at < NOW() - INTERVAL IF(p.base_price > 0, " . STALE_AFTER . ", " . NO_MARKET_STALE_AFTER . ") MINUTE") . "
        ORDER BY t.item_id
    ")->fetchAll(PDO::FETCH_COLUMN);
}
$ids = array_values(array_unique(array_map("intval", $ids)));

echo "=== Updating {$market['region']} market prices for " . count($ids) . " items ===\n\n";

// GET one batch. Returns item id => market entry (base item, enhancement level 0),
// or null when the request keeps failing.
function fetchBatch(array $ids, array $market): ?array
{
    $query = implode("&", array_map(fn($id) => "id=$id", $ids)) . "&lang=en";
    $url   = rtrim($market["api"], "/") . "/" . rawurlencode($market["region"]) . "/GetWorldMarketSubList?$query";

    for ($attempt = 1; ; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => "bdo-craft-calculator",
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        $data = $status === 200 ? json_decode((string)$body, true) : null;
        if (is_array($data)) {
            break;
        }

        $reason = "HTTP $status $error " . substr((string)$body, 0, 120);
        if ($attempt > MAX_RETRIES) {
            echo "  giving up on this batch: $reason\n";
            return null;
        }
        $wait = 10 * 3 ** ($attempt - 1);
        echo "  request failed ($reason), retrying in {$wait}s...\n";
        sleep($wait);
    }

    // A single id returns an object instead of a list
    if (!array_is_list($data)) {
        $data = [$data];
    }

    $result = [];
    foreach ($data as $entry) {
        // Items with enhancement levels return a list with one entry per level
        if (is_array($entry) && array_is_list($entry)) {
            $entry = array_values(array_filter($entry, fn($e) => ($e["sid"] ?? 0) === 0))[0] ?? null;
        }
        if (is_array($entry) && isset($entry["id"])) {
            $result[(int)$entry["id"]] = $entry;
        }
    }

    return $result;
}

$stmt = $pdo->prepare("
    INSERT INTO item_prices (item_id, base_price, current_stock, total_trades, price_min,
                             price_max, last_sold_price, last_sold_time)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        base_price      = VALUES(base_price),
        current_stock   = VALUES(current_stock),
        total_trades    = VALUES(total_trades),
        price_min       = VALUES(price_min),
        price_max       = VALUES(price_max),
        last_sold_price = VALUES(last_sold_price),
        last_sold_time  = VALUES(last_sold_time),
        updated_at      = CURRENT_TIMESTAMP
");

$batches  = array_chunk($ids, BATCH_SIZE);
$onMarket = $notOnMarket = $missing = $kept = $failed = 0;
$failedInARow = 0;

foreach ($batches as $n => $batch) {
    if ($n > 0) {
        usleep(REQUEST_DELAY * 1000);
    }

    $entries = fetchBatch($batch, $market);
    if ($entries === null) {
        $failed += count($batch);
        if (++$failedInARow >= 3) {
            echo "\nThe market API keeps refusing requests; run this script again later.\n";
            break;
        }
        continue;
    }
    $failedInARow = 0;

    // A blocked upstream request can come back as all zeros. Never let that
    // wipe out a price we already have.
    $placeholders = implode(",", array_fill(0, count($batch), "?"));
    $known = $pdo->prepare("SELECT item_id FROM item_prices WHERE base_price > 0 AND item_id IN ($placeholders)");
    $known->execute($batch);
    $hasPrice = array_flip($known->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();
    foreach ($batch as $id) {
        $e = $entries[$id] ?? null;
        if ($e === null) {
            // Not on the market at all: remember that it was checked
            $missing++;
            if (!isset($hasPrice[$id])) {
                $stmt->execute([$id, 0, 0, 0, 0, 0, 0, 0]);
            }
            continue;
        }
        if (($e["basePrice"] ?? 0) <= 0 && isset($hasPrice[$id])) {
            $kept++;
            continue;
        }

        $stmt->execute([
            $id,
            $e["basePrice"] ?? 0,
            $e["currentStock"] ?? 0,
            $e["totalTrades"] ?? 0,
            $e["priceMin"] ?? 0,
            $e["priceMax"] ?? 0,
            $e["lastSoldPrice"] ?? 0,
            $e["lastSoldTime"] ?? 0,
        ]);
        ($e["basePrice"] ?? 0) > 0 ? $onMarket++ : $notOnMarket++;
    }
    $pdo->commit();

    // One price per item and day builds the price history
    record_price_history($batch);

    printf("  batch %d/%d done\n", $n + 1, count($batches));
}

printf(
    "\n=== Done in %.1fs: %d on the market, %d not tradeable, %d kept old price, %d not on the market, %d failed ===\n",
    microtime(true) - $start, $onMarket, $notOnMarket, $kept, $missing, $failed
);

exit($failed > 0 ? 1 : 0);
