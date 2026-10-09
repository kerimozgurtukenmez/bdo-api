<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Parses real rows saved from bdocodex (tests/fixtures/bdocodex_rows.json)
final class BdocodexTest extends TestCase
{
    private static array $rows;

    public static function setUpBeforeClass(): void
    {
        self::$rows = json_decode(file_get_contents(__DIR__ . "/../fixtures/bdocodex_rows.json"), true);
    }

    public function testCookingRecipe(): void
    {
        [$whiteSauce] = codex_parse_recipes(self::$rows["cooking"], "cooking");

        $this->assertSame([
            "id"          => 106,
            "name"        => "White Sauce",
            "grade"       => 0,
            "grade_name"  => "White",
            "icon"        => "https://bdocodex.com/items/new_icon/03_etc/07_productmaterial/00009003.webp",
            "link"        => "https://bdocodex.com/us/recipe/106/",
            "category"    => "Cooking",
            "skill_level" => "Beginner 1",
            "skill_sort"  => 1,
            "exp"         => 400,
            "weight"      => 0.14,
        ], array_diff_key($whiteSauce, array_flip(["ingredients", "output", "ingredient_ids"])));

        $this->assertSame([
            ["item_id" => 9018, "qty_min" => 1, "qty_max" => 1, "is_key" => true],
            ["item_id" => 9065, "qty_min" => 1, "qty_max" => 1],
            ["item_id" => 7313, "qty_min" => 1, "qty_max" => 1],
            ["item_id" => 9017, "qty_min" => 2, "qty_max" => 2],
        ], $whiteSauce["ingredients"]);
        $this->assertSame([["item_id" => 9003, "qty_min" => 1, "qty_max" => 4]], $whiteSauce["output"]);
        $this->assertSame([9018, 9065, 7313, 7304, 7321, 7322, 7307, 7329, 7341, 7314, 7315, 7316, 7317, 9017], $whiteSauce["ingredient_ids"]);
    }

    public function testExpWithThousandsSeparator(): void
    {
        $recipes = codex_parse_recipes(self::$rows["cooking"], "cooking");

        $this->assertSame(113, $recipes[1]["id"]);
        $this->assertSame(1000, $recipes[1]["exp"]);  // shown as "1'000"
    }

    public function testProcessingRecipe(): void
    {
        [$shard] = codex_parse_recipes(self::$rows["processing"], "processing");

        $this->assertSame("Melted Iron Shard", $shard["name"]);  // name wrapped in an empty <span>
        $this->assertSame("Heating", $shard["category"]);
        $this->assertSame("https://bdocodex.com/us/mrecipe/1/", $shard["link"]);
        $this->assertNull($shard["exp"]);
        $this->assertSame(1.5, $shard["weight"]);
        $this->assertSame([["item_id" => 4001, "qty_min" => 5, "qty_max" => 5]], $shard["ingredients"]);
        $this->assertSame([
            ["item_id" => 4051, "qty_min" => 1, "qty_max" => 4],
            ["item_id" => 4052, "qty_min" => 1, "qty_max" => 1],
        ], $shard["output"]);
    }

    public function testMasteryTables(): void
    {
        // Rows as query.php?a=cookingmastery / alchemymastery return them, newest first
        $cooking = codex_parse_mastery([[3000, "76.45%", "76.45%", "24.20%", "100.00%", "181.25%"], [0, "0.00%", "0.00%", "0.00%", "0.00%", "0.00%"]], "cooking");
        $alchemy = codex_parse_mastery([[3000, "62.50%", "0.00%", "0.00%", "1.25%", "181.25%"]], "alchemy");

        $this->assertSame(0, $cooking[0]["mastery"]);  // sorted ascending
        $this->assertSame(["mastery" => 3000, "product" => 0.7645, "rare" => 0.242, "imperial" => 1.8125], $cooking[1]);
        $this->assertSame(0.0125, $alchemy[0]["rare"]);  // rare item chance is the 5th column for alchemy
    }

    public function testItemPagePrices(): void
    {
        $page = '<td>Knowledge: - Master&#39;s Cooking Box Buy price: 220,000<img src="/items/new_icon/00000001_special.webp" alt="coin"><br>'
              . 'Sell price: <span class="red_text">-</span><br>Repair price: <span class="red_text">-</span><br></td>';

        $this->assertSame(["buy_price" => 220000, "sell_price" => null], codex_item_page_prices($page));
        $this->assertSame(["buy_price" => null, "sell_price" => null], codex_item_page_prices("<p>No prices here</p>"));
    }

    public function testItems(): void
    {
        $items = codex_parse_items(self::$rows["items"]);

        $this->assertSame([4052, 9003], array_column($items, "id"));  // sorted by id
        $this->assertSame([
            "id"         => 9003,
            "name"       => "White Sauce",
            "grade"      => 0,
            "grade_name" => "White",
            "icon"       => "https://bdocodex.com/items/new_icon/03_etc/07_productmaterial/00009003.webp",
            "link"       => "https://bdocodex.com/us/item/9003/",
        ], $items[1]);
    }
}
