<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IconTest extends TestCase
{
    public function testImportStoresThePathOnTheSourceSite(): void
    {
        $this->assertSame(
            "items/new_icon/03_etc/07_productmaterial/00009003.webp",
            icon_path("https://bdocodex.com/items/new_icon/03_etc/07_productmaterial/00009003.webp")
        );
        $this->assertSame("https://example.com/other.webp", icon_path("https://example.com/other.webp"));
        $this->assertNull(icon_path(null));
        $this->assertNull(icon_path(""));
    }

    public function testIconsNotDownloadedComeFromTheSource(): void
    {
        $this->assertSame("https://bdocodex.com/items/not/downloaded.webp", icon_url("items/not/downloaded.webp"));
        $this->assertNull(icon_url(null));
    }

    public function testDownloadedIconsAreServedLocally(): void
    {
        $dir  = config("icons")["dir"];
        $path = "test-" . uniqid() . ".webp";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("$dir/$path", "x");

        try {
            $this->assertSame(config("icons")["url"] . "/$path", icon_url($path));
        } finally {
            unlink("$dir/$path");
        }
    }
}
