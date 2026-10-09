<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Rules in src/recipes.php that need no database
final class RecipesTest extends TestCase
{
    public function testRareRequirementComesFromTheItemDescription(): void
    {
        $this->assertSame("Skilled 1", rare_requirement(
            "How to Obtain: Slight chance of obtaining Cold Draft Beer when making Beer if at least Cooking Skilled 1"
        ));
        // The level to craft the item itself comes first; the rare chance's level is in brackets
        $this->assertSame("Skilled 9", rare_requirement(
            "How to Obtain: Craft via a Cooking Utensil in your residence if at least Cooking Apprentice 1 "
            . "(slight chance of obtaining Crispy Mungbean Jeon when making Mungbean Jeon if at least Cooking Skilled 9)"
        ));
        $this->assertSame("Professional 1", rare_requirement(
            "How to Obtain: Craft Helix Elixir in your residence with the following materials for a low chance to obtain if at least Alchemy Professional 1"
        ));
        $this->assertNull(rare_requirement("How to Obtain: Heating (Zinc Ore x5)"));
        $this->assertNull(rare_requirement(null));
    }

    public function testOutputKind(): void
    {
        $this->assertSame("main", output_kind("cooking", true));
        $this->assertSame("rare", output_kind("alchemy", false));
        $this->assertSame("byproduct", output_kind("processing", false));
    }
}
