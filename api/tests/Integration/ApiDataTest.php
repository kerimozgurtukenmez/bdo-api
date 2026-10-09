<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

// Imperial boxes, price history and mastery tables (src/imperial.php,
// src/prices.php, src/mastery.php) against a small hand-made data set.
final class ApiDataTest extends TestCase
{
    private const BOX = 100, BEER = 101, STEW = 102, EVENT_BOX = 103, POTION = 104;

    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = $pdo = TestDatabase::create();

        TestDatabase::addItems($pdo, [
            self::BOX       => ["Master's Cooking Box"],
            self::BEER      => ["Beer", 3000],
            self::STEW      => ["Meat Stew"],  // no price
            self::EVENT_BOX => ["[Event] Gift Box"],
            self::POTION    => ["Elixir"],
        ]);
        // Base prices of the boxes (from their item pages)
        $pdo->exec("INSERT INTO item_details (item_id, buy_price) VALUES (" . self::BOX . ", 220000), (" . self::EVENT_BOX . ", 1000)");

        TestDatabase::addRecipe($pdo, "processing", 500, "Master's Cooking Box", "Imperial Cuisine", [[self::STEW, 3]], [[self::BOX, 1, 1]]);
        TestDatabase::addRecipe($pdo, "processing", 501, "Master's Cooking Box", "Imperial Cuisine", [[self::BEER, 6]], [[self::BOX, 1, 1]]);
        TestDatabase::addRecipe($pdo, "processing", 502, "[Event] Gift Box", "Imperial Cuisine", [[self::BEER, 1]], [[self::EVENT_BOX, 1, 1]]);

        $pdo->exec("INSERT INTO mastery_bonuses (skill, mastery, product, rare, imperial) VALUES
            ('cooking', 0, 0, 0, 0), ('cooking', 1000, 0.17, 0.05, 0.55), ('alchemy', 0, 0, 0, 0)");
    }

    public function testImperialBoxesWithTheirRecipes(): void
    {
        $result = imperial_boxes("cooking");

        $this->assertSame("cooking", $result["skill"]);
        $this->assertCount(1, $result["boxes"]);  // the event box is not an Imperial delivery box

        $box = $result["boxes"][0];
        $this->assertSame(["id" => self::BOX, "name" => "Master's Cooking Box"], array_intersect_key($box["item"], ["id" => 0, "name" => 0]));
        $this->assertSame("Master", $box["tier"]);
        $this->assertSame(220000, $box["base_price"]);

        // Cheapest to buy first; a recipe with an unknown price last
        $this->assertSame(["processing:501", "processing:500"], array_column($box["recipes"], "key"));
        $beer = $box["recipes"][0]["ingredients"][0];
        $this->assertSame([self::BEER, "Beer", 6, 3000], [$beer["item"]["id"], $beer["item"]["name"], $beer["qty"], $beer["price"]["unit"]]);
        $this->assertNull($box["recipes"][1]["ingredients"][0]["price"]);

        $this->assertSame([], imperial_boxes("alchemy")["boxes"]);
    }

    public function testImperialTierComesFromTheBoxName(): void
    {
        $this->assertSame("Apprentice", imperial_tier("Apprentice's Cooking Box"));
        $this->assertSame("Skilled", imperial_tier("Skilled Cook's Cooking Box"));
        $this->assertSame("Skilled", imperial_tier("Skilled Alchemist's Medicine Box"));
        $this->assertSame("Guru", imperial_tier("Guru's Medicine Box"));
        $this->assertNull(imperial_tier("Artisan's Precious Alchemy Box"));
        $this->assertNull(imperial_tier("[Event] Choco Stick Box"));
    }

    public function testPriceHistoryRecordsOnePricePerDay(): void
    {
        record_price_history([self::BEER, self::STEW]);  // Meat Stew has no market price
        self::$pdo->exec("UPDATE item_prices SET base_price = 3100, current_stock = 50 WHERE item_id = " . self::BEER);
        record_price_history([self::BEER]);  // the same day again: replaces, never duplicates

        $points = price_history(self::BEER, 30);
        $this->assertCount(1, $points);
        $this->assertSame([3100, 50], [$points[0]["price"], $points[0]["stock"]]);
        $today = self::$pdo->query("SELECT CURDATE()")->fetchColumn();
        $this->assertSame(strtotime("$today 00:00:00 UTC"), $points[0]["t"]);  // the day at 00:00 UTC
        $this->assertSame([], price_history(self::STEW, 30));
    }

    public function testMarketSnapshotKeepsOneRowPerItemAndHour(): void
    {
        self::$pdo->exec("UPDATE item_prices SET total_trades = 100 WHERE item_id = " . self::BEER);
        record_market_snapshot([self::BEER, self::STEW]);
        self::$pdo->exec("UPDATE item_prices SET total_trades = 130 WHERE item_id = " . self::BEER);
        record_market_snapshot([self::BEER]);

        $rows = self::$pdo->query("SELECT item_id, total_trades FROM item_market_snapshots")->fetchAll();
        $this->assertSame([["item_id" => self::BEER, "total_trades" => 130]], $rows);  // no price: no snapshot
        $this->assertSame(0, prune_market_snapshots());
    }

    public function testMarketDemandIsSalesPerHour(): void
    {
        self::$pdo->exec("INSERT INTO item_market_snapshots (item_id, hour, price, stock, total_trades) VALUES
            (" . self::STEW . ", DATE_FORMAT(NOW() - INTERVAL 2 HOUR, '%Y-%m-%d %H:00:00'), 500, 10, 100),
            (" . self::STEW . ", DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), 500, 10, 130)");

        $this->assertSame(["per_hour" => 15, "hours" => 2], market_demand(self::$pdo)[self::STEW]);
    }

    public function testMasteryTables(): void
    {
        $tables = mastery_tables();

        $this->assertSame([0, 1000], array_column($tables["cooking"], "mastery"));
        $this->assertSame(["mastery" => 1000, "product" => 0.17, "rare" => 0.05, "imperial" => 0.55], $tables["cooking"][1]);
        $this->assertSame(0.55, mastery_bonus("cooking", 1499)["imperial"]);  // the row at or below
        $this->assertSame(0.0, mastery_bonus("cooking", 999)["imperial"]);
        $this->assertNull(mastery_bonus("fishing", 1000));
    }
}
