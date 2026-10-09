<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    private static function ingredients(int ...$ids): array
    {
        return array_map(fn($id) => ["item_id" => $id, "qty_min" => 1, "qty_max" => 1], $ids);
    }

    public function testAlternativesFollowTheirDefaultIngredient(): void
    {
        // White Sauce: Base Sauce, Milk, Apple (or other fruit), Cooking Wine
        $groups = group_alternatives(self::ingredients(9018, 9065, 7313, 9017), [9018, 9065, 7313, 7304, 7307, 9017]);

        $this->assertSame([[], [], [7304, 7307], []], $groups);
    }

    public function testNoIngredientIdsMeansNoAlternatives(): void
    {
        $this->assertSame([[], []], group_alternatives(self::ingredients(1, 2), []));
    }

    public function testDefaultIngredientUsedInTwoSlots(): void
    {
        // Magic Crystal recipes list the same crystal as key ingredient and as a
        // later "any crystal" slot; each slot keeps its own substitutes.
        $groups = group_alternatives(self::ingredients(15001, 5205, 6203, 15001), [15001, 15002, 15003, 5205, 6203, 6210, 15001, 15002, 15003]);

        $this->assertSame([[15002, 15003], [], [6210], [15002, 15003]], $groups);
    }

    public function testAnotherSlotsDefaultCanBeASubstitute(): void
    {
        // Slot 0 accepts 15001, which is also the default of slot 2
        $groups = group_alternatives(self::ingredients(15002, 5205, 15001), [15002, 15001, 15003, 5205, 15001, 15002]);

        $this->assertSame([[15001, 15003], [], [15002]], $groups);
    }

    public function testRepeatedOrSelfSubstitutesAreDropped(): void
    {
        $this->assertSame([[5, 6]], group_alternatives(self::ingredients(1), [1, 5, 1, 5, 6]));
    }

    public function testListThatDoesNotMatchTheIngredientsIsRejected(): void
    {
        $this->assertNull(group_alternatives(self::ingredients(1, 2), [9, 1, 2]));  // starts with a non-default
        $this->assertNull(group_alternatives(self::ingredients(1, 2), [1, 5]));     // slot 2 never appears
    }

    public function testMainOutputIsTheProductNamedLikeTheRecipe(): void
    {
        $names = [9271 => "Crispy Fried Vegetables", 9256 => "Fried Vegetables"];
        $recipe = fn(string $name) => ["name" => $name, "output" => [["item_id" => 9271], ["item_id" => 9256]]];

        $this->assertSame(1, main_output_index($recipe("Fried Vegetables"), $names));
        $this->assertSame(1, main_output_index($recipe(" fried vegetables "), $names));
        $this->assertSame(0, main_output_index($recipe("Trace of Savagery"), $names));  // no match: first output
    }

    // Beer on bdocodex: the main recipe and two fixed combinations of it
    private const MINERAL_WATER = 9059, PURIFIED_WATER = 6656, CORN = 7006, LEAVENING = 9066, SUGAR = 9002, RAW_SUGAR = 9003, HONEY = 9004;

    private static function slot(int $itemId, int $qty, array $alternatives = []): array
    {
        return ["item_id" => $itemId, "qty" => $qty, "alternatives" => $alternatives];
    }

    private static function beerRecipes(array ...$variants): array
    {
        $recipes = [122 => ["name" => "Beer", "main" => 9213, "slots" => [
            self::slot(self::MINERAL_WATER, 6),
            self::slot(self::CORN, 5),
            self::slot(self::LEAVENING, 2),
            self::slot(self::SUGAR, 1, [self::RAW_SUGAR => 1]),
        ]]];
        foreach ($variants as $i => $slots) {
            $recipes[200 + $i] = ["name" => "Beer", "main" => 9213, "slots" => $slots];
        }
        return $recipes;
    }

    public function testVariantThatFitsTheMainRecipeIsDropped(): void
    {
        $recipes = self::beerRecipes([
            self::slot(self::CORN, 5), self::slot(self::MINERAL_WATER, 6),
            self::slot(self::RAW_SUGAR, 1), self::slot(self::LEAVENING, 2),
        ]);

        [$kept, $folded] = fold_recipe_variants($recipes, [9213 => "Beer"]);

        $this->assertSame([200], $folded);
        $this->assertSame($recipes[122], $kept[122]);
    }

    public function testVariantWithOneOtherIngredientAddsItAsSubstitute(): void
    {
        $recipes = self::beerRecipes([
            self::slot(self::PURIFIED_WATER, 3), self::slot(self::CORN, 5),
            self::slot(self::LEAVENING, 2), self::slot(self::SUGAR, 1),
        ]);

        [$kept, $folded] = fold_recipe_variants($recipes, [9213 => "Beer"]);

        $this->assertSame([200], $folded);
        $this->assertSame([122], array_keys($kept));
        $this->assertSame([self::PURIFIED_WATER => 3], $kept[122]["slots"][0]["alternatives"]);  // with its own amount
    }

    public function testVariantThatFitsOnlyAfterALaterOneIsFolded(): void
    {
        $recipes = self::beerRecipes(
            // Purified Water and Honey: two new ingredients until 201 adds Purified Water
            [self::slot(self::PURIFIED_WATER, 3), self::slot(self::CORN, 5), self::slot(self::LEAVENING, 2), self::slot(self::HONEY, 1)],
            [self::slot(self::PURIFIED_WATER, 3), self::slot(self::CORN, 5), self::slot(self::LEAVENING, 2), self::slot(self::SUGAR, 1)],
        );

        [$kept, $folded] = fold_recipe_variants($recipes, [9213 => "Beer"]);

        $this->assertSame([200, 201], $folded);
        $this->assertSame([122], array_keys($kept));
        $this->assertSame([self::RAW_SUGAR => 1, self::HONEY => 1], $kept[122]["slots"][3]["alternatives"]);
    }

    public function testRecipesThatDifferMoreAreKept(): void
    {
        $recipes = self::beerRecipes(
            [self::slot(self::PURIFIED_WATER, 3), self::slot(self::CORN, 9), self::slot(self::LEAVENING, 2), self::slot(self::SUGAR, 1)],
            [self::slot(self::MINERAL_WATER, 6), self::slot(self::CORN, 5), self::slot(self::LEAVENING, 2)],  // fewer slots
        );

        [$kept, $folded] = fold_recipe_variants($recipes, [9213 => "Beer"]);

        $this->assertSame([], $folded);
        $this->assertSame([122, 200, 201], array_keys($kept));
    }

    public function testMainRecipeIsTheOneNamedAfterTheProduct(): void
    {
        $recipes = self::beerRecipes();
        $recipes[50] = ["name" => "Beer (Purified Water)", "main" => 9213, "slots" => [
            self::slot(self::PURIFIED_WATER, 3), self::slot(self::CORN, 5),
            self::slot(self::LEAVENING, 2), self::slot(self::SUGAR, 1),
        ]];

        [$kept, $folded] = fold_recipe_variants($recipes, [9213 => "Beer"]);

        $this->assertSame([50], $folded);  // lower id, but not named "Beer"
        $this->assertSame([122], array_keys($kept));
    }

    public function testVendorItemsAreRecognisedFromTheirDescription(): void
    {
        $this->assertTrue(is_vendor_sold("An ingredient used in Cooking. It can be bought from a Food Vendor or Innkeeper."));
        $this->assertTrue(is_vendor_sold("An ordinary HP potion. - Purchase from: General Goods Vendor"));
        $this->assertFalse(is_vendor_sold("Made by Shaking Wheat Flour with Mineral Water that was purchased from a Shop."));
        $this->assertFalse(is_vendor_sold("An ingredient used in Cooking. It can be received from a farm quest."));
        $this->assertFalse(is_vendor_sold(null));
    }
}
