<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PriceTest extends TestCase
{
    private static function row(int $base = 0, int $lastSold = 0, int $buy = 0, int $vendorSold = 0): array
    {
        return ["base_price" => $base, "last_sold_price" => $lastSold, "buy_price" => $buy, "vendor_sold" => $vendorSold];
    }

    public function testMarketBasePrice(): void
    {
        $this->assertSame(["unit" => 23300, "source" => "market"], item_price(self::row(base: 23300, lastSold: 23200)));
    }

    public function testLastSoldPriceWhenThereIsNoBasePrice(): void
    {
        $this->assertSame(["unit" => 830, "source" => "market"], item_price(self::row(lastSold: 830)));
    }

    public function testVendorPriceOnlyForVendorItems(): void
    {
        $this->assertSame(["unit" => 40, "source" => "vendor"], item_price(self::row(buy: 40, vendorSold: 1)));
        $this->assertNull(item_price(self::row(buy: 300)));  // nominal game price, not sold by an NPC
    }

    public function testCheaperSourceWins(): void
    {
        $this->assertSame(["unit" => 20, "source" => "vendor"], item_price(self::row(base: 500, buy: 20, vendorSold: 1)));
        $this->assertSame(["unit" => 15, "source" => "market"], item_price(self::row(base: 15, buy: 20, vendorSold: 1)));
    }

    public function testUnknownPrice(): void
    {
        $this->assertNull(item_price(self::row()));
        $this->assertNull(item_price(["base_price" => null, "last_sold_price" => null, "buy_price" => null, "vendor_sold" => null]));
    }
}
