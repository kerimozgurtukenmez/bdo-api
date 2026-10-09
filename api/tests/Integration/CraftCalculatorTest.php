<?php

declare(strict_types=1);

namespace Tests\Integration;

use ApiError;
use CraftCalculator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

// Expected numbers are worked out by hand from the fixture below.
final class CraftCalculatorTest extends TestCase
{
    private const WHEAT = 1, FLOUR = 2, WATER = 3, DOUGH = 4, BREAD = 5, SALT = 6, SEA_WATER = 7,
        EGG = 8, APPLE = 9, STRAWBERRY = 10, PIE = 11, CRYSTAL = 12, SHARD = 13, GEM = 14,
        FANCY_BREAD = 15, CRUMB = 16, SAUCE = 17;

    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = $pdo = TestDatabase::create();

        // id => [name, market price, NPC vendor price]
        TestDatabase::addItems($pdo, [
            self::WHEAT       => ["Wheat", 100],
            self::FLOUR       => ["Flour", 200],
            self::WATER       => ["Mineral Water", null, 30],
            self::DOUGH       => ["Dough"],
            self::BREAD       => ["Bread", 9000],
            self::SALT        => ["Salt", null, 20],
            self::SEA_WATER   => ["Sea Water", 500],
            self::EGG         => ["Egg", 200],
            self::APPLE       => ["Apple", 300],
            self::STRAWBERRY  => ["Strawberry", 100],
            self::PIE         => ["Pie", 20000],
            self::CRYSTAL     => ["Crystal", 50000],
            self::SHARD       => ["Shard"],
            self::GEM         => ["Gem"],
            self::FANCY_BREAD => ["Fancy Bread"],
            self::CRUMB       => ["Crumb"],
            self::SAUCE       => ["Sauce"],
        ]);

        // Ingredients: [item, qty, substitutes]; outputs: [item, min, max], main product first
        TestDatabase::addRecipe($pdo, "processing", 9, "Flour Reclaim", "Grinding", [[self::BREAD, 1]], [[self::FLOUR, 2, 2]]);
        TestDatabase::addRecipe($pdo, "processing", 10, "Flour", "Grinding", [[self::WHEAT, 5]], [[self::FLOUR, 1, 3]]);
        TestDatabase::addRecipe($pdo, "processing", 11, "Dough", "Shaking", [[self::FLOUR, 1], [self::WATER, 1]], [[self::DOUGH, 1, 3]]);
        TestDatabase::addRecipe($pdo, "processing", 12, "Salt", "Heating", [[self::SEA_WATER, 1]], [[self::SALT, 1, 4]]);
        TestDatabase::addRecipe($pdo, "cooking", 20, "Bread", "Cooking",
            [[self::DOUGH, 3], [self::SALT, 1], [self::EGG, 2]], [[self::BREAD, 1, 1], [self::CRUMB, 1, 1]], 400);
        TestDatabase::addRecipe($pdo, "cooking", 21, "Pie", "Cooking",
            [[self::DOUGH, 3], [self::APPLE, 1, [self::STRAWBERRY]], [self::BREAD, 1]], [[self::PIE, 1, 4]], 1000);
        TestDatabase::addRecipe($pdo, "cooking", 22, "Fancy Bread", "Cooking", [[self::BREAD, 1], [self::EGG, 1]], [[self::FANCY_BREAD, 1, 1]]);
        TestDatabase::addRecipe($pdo, "cooking", 23, "Fancy Bread", "Cooking", [[self::BREAD, 2]], [[self::FANCY_BREAD, 1, 1]]);
        TestDatabase::addRecipe($pdo, "processing", 30, "Crystal", "Heating", [[self::SHARD, 2]], [[self::CRYSTAL, 1, 1]]);
        TestDatabase::addRecipe($pdo, "processing", 31, "Shard", "Grinding", [[self::CRYSTAL, 1], [self::GEM, 3]], [[self::SHARD, 5, 5]]);
        TestDatabase::addRecipe($pdo, "processing", 15, "Sauce", "Simple Cooking", [[self::APPLE, 1]], [[self::SAUCE, 1, 1]]);
        TestDatabase::addRecipe($pdo, "cooking", 25, "Sauce", "Cooking", [[self::APPLE, 2, [[self::STRAWBERRY, 4, true]]]], [[self::SAUCE, 1, 1]]);
        TestDatabase::addRecipe($pdo, "processing", 32, "Egg", "Simple Cooking", [[self::EGG, 2]], [[self::EGG, 3, 3]]);

        // Crumb is Bread's rare product
        $pdo->prepare("INSERT INTO item_details (item_id, description) VALUES (?, ?)")
            ->execute([self::CRUMB, "How to Obtain: Slight chance of obtaining Crumb when making Bread if at least Cooking Skilled 1"]);

        // Cooking mastery: +50% products from 1000
        $pdo->exec("INSERT INTO mastery_bonuses (skill, mastery, product, rare, imperial) VALUES ('cooking', 0, 0, 0, 0), ('cooking', 1000, 0.5, 0.1, 0.6)");
    }

    private function plan(int $itemId, int $qty, array $options = []): array
    {
        return (new CraftCalculator(self::$pdo, ...$options))->calculate($itemId, $qty);
    }

    /** @return array<string, array{qty: int, total: int|null, reason: string}> */
    private function materials(array $plan): array
    {
        $result = [];
        foreach ($plan["materials"] as $m) {
            $result[$m["item"]["name"]] = ["qty" => $m["qty"], "total" => $m["total_price"], "reason" => $m["reason"]];
        }
        return $result;
    }

    /** @return array<string, int> name => crafts, in crafting order */
    private function steps(array $plan): array
    {
        return array_column(array_map(fn($s) => [$s["item"]["name"], $s["crafts"]], $plan["steps"]), 1, 0);
    }

    public function testExpandsTheWholeRecipeChain(): void
    {
        $plan = $this->plan(self::BREAD, 10);

        // Bread 10 crafts → Dough 30 (15 crafts at 2 per craft) → Flour 15 (8 crafts) → Wheat 40
        $this->assertSame(["Flour" => 8, "Dough" => 15, "Bread" => 10], $this->steps($plan));
        $this->assertEquals([
            "Wheat"         => ["qty" => 40, "total" => 4000, "reason" => "no_recipe"],
            "Mineral Water" => ["qty" => 15, "total" => 450, "reason" => "no_recipe"],
            "Salt"          => ["qty" => 10, "total" => 200, "reason" => "vendor"],
            "Egg"           => ["qty" => 20, "total" => 4000, "reason" => "no_recipe"],
        ], $this->materials($plan));

        $this->assertSame(8650, $plan["cost"]["total"]);
        $this->assertSame(865.0, $plan["cost"]["per_unit"]);
        $this->assertTrue($plan["cost"]["complete"]);
        $this->assertSame(["unit" => 9000, "total" => 90000, "profit" => 81350], $plan["market_value"]);
        $this->assertSame(["steps" => 2, "crafts" => 23, "exp" => 0], $plan["by_source"]["processing"]);
        $this->assertSame(["steps" => 1, "crafts" => 10, "exp" => 4000], $plan["by_source"]["cooking"]);
        $this->assertSame([], $plan["warnings"]);
        $this->assertEqualsWithDelta(time(), $plan["cost"]["prices_updated_at"], 600);  // prices were just inserted
    }

    public function testStockIsUsedBeforeCraftingOrBuying(): void
    {
        $plan = $this->plan(self::BREAD, 10, ["stock" => [self::DOUGH => 10, self::EGG => 25, self::SALT => 4]]);

        // Dough 30 needed, 10 in stock → 10 crafts → Flour 10 (5 crafts) → Wheat 25
        $this->assertSame(["Flour" => 5, "Dough" => 10, "Bread" => 10], $this->steps($plan));
        $dough = $plan["steps"][1];
        $this->assertSame([20, 10], [$dough["needed"], $dough["from_stock"]]);

        $this->assertEquals([
            "Wheat"         => ["qty" => 25, "total" => 2500, "reason" => "no_recipe"],
            "Mineral Water" => ["qty" => 10, "total" => 300, "reason" => "no_recipe"],
            "Salt"          => ["qty" => 6, "total" => 120, "reason" => "vendor"],
            "Egg"           => ["qty" => 0, "total" => 0, "reason" => "no_recipe"],  // still listed
        ], $this->materials($plan));
        $egg = array_values(array_filter($plan["materials"], fn($m) => $m["item"]["name"] === "Egg"))[0];
        $this->assertSame([20, 20], [$egg["needed"], $egg["from_stock"]]);

        $this->assertSame(2920, $plan["cost"]["total"]);
        $this->assertTrue($plan["cost"]["complete"]);
        $this->assertSame(4080, $plan["cost"]["stock_value"]);  // Egg 20 × 200 + Salt 4 × 20; Dough has no price
        $this->assertFalse($plan["cost"]["stock_value_complete"]);
        $this->assertNull($plan["market_value"]["profit"]);

        // The tree takes Dough from stock too
        $doughNode = $plan["tree"]["children"][0];
        $this->assertSame([30, 10, 10], [$doughNode["qty"], $doughNode["from_stock"], $doughNode["recipe"]["crafts"]]);
    }

    public function testStockDoesNotChangeTheProfit(): void
    {
        $plan = $this->plan(self::BREAD, 10, ["stock" => [self::EGG => 20]]);

        $this->assertSame(4650, $plan["cost"]["total"]);  // 8650 without stock, less 20 Eggs
        $this->assertSame(4000, $plan["cost"]["stock_value"]);
        $this->assertSame(81350, $plan["market_value"]["profit"]);  // the same as without stock
    }

    public function testStockCanCoverTheWholePlan(): void
    {
        $plan = $this->plan(self::BREAD, 10, ["stock" => [self::BREAD => 12]]);

        $this->assertSame(["Bread" => 0], $this->steps($plan));
        $this->assertSame(10, $plan["steps"][0]["from_stock"]);
        $this->assertSame([], $plan["materials"]);
        $this->assertSame(0, $plan["cost"]["total"]);
        $this->assertSame(["stock", 10, 0], [$plan["tree"]["action"], $plan["tree"]["from_stock"], $plan["tree"]["cost"]]);
        $this->assertArrayNotHasKey("children", $plan["tree"]);
    }

    public function testTotalsRoundCraftsOncePerItemButTheTreeRoundsPerBranch(): void
    {
        $plan = $this->plan(self::PIE, 2);

        // Pie needs Dough 3 directly and Dough 3 through Bread: 6 in total → 3 crafts.
        // Each branch on its own needs ceil(3 / 2) = 2 crafts.
        $this->assertSame(["Flour" => 2, "Dough" => 3, "Bread" => 1, "Pie" => 1], $this->steps($plan));
        $this->assertSame(1810, $plan["cost"]["total"]);

        $tree = $plan["tree"];
        $this->assertSame("Dough", $tree["children"][0]["item"]["name"]);
        $this->assertSame(2, $tree["children"][0]["recipe"]["crafts"]);
        $this->assertSame("Bread", $tree["children"][2]["item"]["name"]);
        $this->assertSame(2, $tree["children"][2]["children"][0]["recipe"]["crafts"]);
    }

    public function testSubstituteReplacesTheDefaultIngredient(): void
    {
        $plan = $this->plan(self::PIE, 2, ["substitutes" => [self::APPLE => self::STRAWBERRY]]);

        $materials = $this->materials($plan);
        $this->assertArrayNotHasKey("Apple", $materials);
        $this->assertSame(1, $materials["Strawberry"]["qty"]);
        $this->assertSame(1610, $plan["cost"]["total"]);

        $slot = $plan["tree"]["children"][1];
        $this->assertSame(self::STRAWBERRY, $slot["item"]["id"]);
        $this->assertSame(self::APPLE, $slot["slot"]["default_item_id"]);
        $this->assertSame([self::APPLE, self::STRAWBERRY], array_column($slot["slot"]["alternatives"], "id"));
        $this->assertSame([], $plan["warnings"]);
    }

    public function testRareProductsAreListedButNotCounted(): void
    {
        $plan  = $this->plan(self::BREAD, 2);
        $steps = array_column(array_map(fn($s) => [$s["item"]["name"], $s["recipe"]], $plan["steps"]), 1, 0);

        $rare = $steps["Bread"]["rare"];
        $this->assertSame([[self::CRUMB, "Crumb", 1, 1, "Skilled 1"]],
            array_map(fn($r) => [$r["id"], $r["name"], $r["qty_min"], $r["qty_max"], $r["requires"]], $rare));
        $this->assertSame([], $steps["Dough"]["rare"]);  // processing: no rare products
        $this->assertSame($rare, $plan["tree"]["recipe"]["rare"]);
        $this->assertArrayNotHasKey("Crumb", $this->materials($plan));
    }

    public function testSubstituteUsesItsOwnAmount(): void
    {
        // Sauce takes 2 Apples or 4 Strawberries per craft
        $plan = $this->plan(self::SAUCE, 3, ["substitutes" => [self::APPLE => self::STRAWBERRY]]);

        $this->assertSame(["qty" => 12, "total" => 1200, "reason" => "no_recipe"], $this->materials($plan)["Strawberry"]);
        $slot = $plan["tree"]["children"][0]["slot"];
        $this->assertSame(4, $slot["per_craft"]);
        $this->assertSame([[self::APPLE, 2, false], [self::STRAWBERRY, 4, true]], array_map(fn($a) => [$a["id"], $a["qty"], $a["estimated"]], $slot["alternatives"]));
    }

    public function testSubstituteThatNoRecipeAllowsIsReported(): void
    {
        $plan = $this->plan(self::BREAD, 1, ["substitutes" => [self::WHEAT => self::EGG]]);

        $this->assertSame(["Substitute 8 for item 1 ignored: no recipe in this plan allows it"], $plan["warnings"]);
    }

    public function testVendorItemIsCraftedWhenItIsTheRequestedItem(): void
    {
        $plan = $this->plan(self::SALT, 10);

        // 2.5 Salt per craft → 4 crafts
        $this->assertSame(["Salt" => 4], $this->steps($plan));
        $this->assertSame(["Sea Water" => ["qty" => 4, "total" => 2000, "reason" => "no_recipe"]], $this->materials($plan));
    }

    public function testForcedBuyWithoutPriceMakesTheCostIncomplete(): void
    {
        $plan = $this->plan(self::BREAD, 10, ["forceBuy" => [self::DOUGH => true]]);

        $this->assertSame(["Bread" => 10], $this->steps($plan));
        $this->assertSame(["qty" => 30, "total" => null, "reason" => "forced"], $this->materials($plan)["Dough"]);
        $this->assertSame(4200, $plan["cost"]["total"]);
        $this->assertFalse($plan["cost"]["complete"]);
        $this->assertSame(["Dough"], array_column($plan["cost"]["missing_prices"], "name"));
        $this->assertIsInt($plan["cost"]["prices_updated_at"]);  // Egg comes from the market
        $this->assertNull($plan["market_value"]["profit"]);
    }

    public function testRecipeLoopIsCutAndTheLoopingItemBought(): void
    {
        $plan = $this->plan(self::CRYSTAL, 1);

        // Crystal ← 2 Shard ← (Crystal + 3 Gem) per 5 Shard: the inner Crystal is bought
        $this->assertSame(["Shard" => 1, "Crystal" => 1], $this->steps($plan));
        $this->assertEquals([
            "Crystal" => ["qty" => 1, "total" => 50000, "reason" => "loop"],
            "Gem"     => ["qty" => 3, "total" => null, "reason" => "no_recipe"],
        ], $this->materials($plan));
        $this->assertSame(["Recipe loop cut, bought instead: Crystal"], $plan["warnings"]);

        $inner = $plan["tree"]["children"][0]["children"][0];
        $this->assertSame(["Crystal", "buy", "loop"], [$inner["item"]["name"], $inner["action"], $inner["reason"]]);
    }

    public function testCheapestModeBuysIntermediatesThatCostLessThanCrafting(): void
    {
        $plan = $this->plan(self::BREAD, 10, ["mode" => "cheapest"]);

        // Crafting Flour costs 5 × 100 / 2 = 250 per unit; the market sells it for 200
        $this->assertSame(["Dough" => 15, "Bread" => 10], $this->steps($plan));
        $this->assertSame(["qty" => 15, "total" => 3000, "reason" => "cheaper"], $this->materials($plan)["Flour"]);
        $this->assertSame(7650, $plan["cost"]["total"]);
    }

    public function testRecipeOverride(): void
    {
        $default = $this->plan(self::FANCY_BREAD, 1);
        $this->assertSame("cooking:22", end($default["steps"])["recipe"]["key"]);

        $override = $this->plan(self::FANCY_BREAD, 1, ["recipeOverrides" => [self::FANCY_BREAD => "cooking:23"]]);
        $this->assertSame("cooking:23", end($override["steps"])["recipe"]["key"]);
        $this->assertSame(2, $override["steps"][array_key_last($override["steps"]) - 1]["needed"]);  // 2 Bread

        $invalid = $this->plan(self::FANCY_BREAD, 1, ["recipeOverrides" => [self::FANCY_BREAD => "cooking:20"]]);
        $this->assertSame("cooking:22", end($invalid["steps"])["recipe"]["key"]);
        $this->assertSame(["Recipe override for item 15 ignored: no such recipe makes it"], $invalid["warnings"]);
    }

    public function testYieldModes(): void
    {
        // min: 1 Dough and 1 Flour per craft; max: 3 of each
        $this->assertSame(150, $this->materials($this->plan(self::BREAD, 10, ["yieldMode" => "min"]))["Wheat"]["qty"]);
        $this->assertSame(20, $this->materials($this->plan(self::BREAD, 10, ["yieldMode" => "max"]))["Wheat"]["qty"]);
    }

    public function testMasteryAddsProductsToCookingCraftsOnly(): void
    {
        // Bread (cooking) makes 1.5 per craft at 1000 mastery: 10 Bread = 7 crafts.
        // Dough and Flour are processing and keep their yield: 21 Dough = 11 crafts, 11 Flour = 6 crafts.
        $plan = $this->plan(self::BREAD, 10, ["mastery" => ["cooking" => 1000]]);
        $this->assertSame(["Flour" => 6, "Dough" => 11, "Bread" => 7], $this->steps($plan));
        $this->assertSame(1.5, end($plan["steps"])["recipe"]["yield"]);
        $this->assertSame(30, $this->materials($plan)["Wheat"]["qty"]);

        // Below the next table row the lower row applies: 999 mastery has no bonus
        $steps = $this->plan(self::BREAD, 10, ["mastery" => ["cooking" => 999]])["steps"];
        $this->assertSame(10, end($steps)["crafts"]);
    }

    public function testDefaultRecipeOrder(): void
    {
        $keys = fn(int $item) => array_map(fn($r) => "{$r['source']}:{$r['id']}", (new CraftCalculator(self::$pdo))->recipesFor($item));

        $this->assertSame(["processing:10", "processing:9"], $keys(self::FLOUR));  // named after the item first
        $this->assertSame(["cooking:25", "processing:15"], $keys(self::SAUCE));    // then cooking/alchemy first
        $this->assertSame([], $keys(self::EGG));                                   // needs itself: never used
        $this->assertSame([], $keys(self::CRUMB));                                 // only a byproduct
    }

    public function testUnknownItem(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(0);
        try {
            $this->plan(999, 1);
        } catch (ApiError $e) {
            $this->assertSame(404, $e->status);
            throw $e;
        }
    }
}
