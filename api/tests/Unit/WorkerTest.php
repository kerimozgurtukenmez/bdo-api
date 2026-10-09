<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// When bin/worker.php runs its tasks (src/worker.php)
final class WorkerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private static function tasks(): array
    {
        return worker_tasks(["prices_minutes" => 60, "game_data_hours" => 24]);
    }

    public function testTasksRunGameDataFirst(): void
    {
        $tasks = self::tasks();
        $this->assertSame(["game data", "prices"], array_keys($tasks));
        $this->assertSame([["scrape.php"], ["import.php"], ["download_icons.php"]], $tasks["game data"]["steps"]);
    }

    public function testEverythingIsDueBeforeTheFirstRun(): void
    {
        $this->assertSame(["game data", "prices"], worker_due(self::tasks(), [], 900, self::NOW));
    }

    public function testATaskIsDueAgainAfterItsInterval(): void
    {
        $runs = [
            "game data" => ["started_at" => self::NOW - 3600, "ok" => true],  // an hour ago: not due for 23 h
            "prices"    => ["started_at" => self::NOW - 3600, "ok" => true],  // an hour ago: due
        ];
        $this->assertSame(["prices"], worker_due(self::tasks(), $runs, 900, self::NOW));
        $this->assertSame(self::NOW + 23 * 3600, worker_next_run(self::tasks()["game data"], $runs["game data"], 900));
    }

    public function testAFailedTaskIsRetriedSooner(): void
    {
        $runs = ["prices" => ["started_at" => self::NOW - 1000, "ok" => false], "game data" => ["started_at" => self::NOW - 1000, "ok" => false]];
        $this->assertSame(["game data", "prices"], worker_due(self::tasks(), $runs, 900, self::NOW));
        $this->assertSame([], worker_due(self::tasks(), $runs, 1200, self::NOW));
    }
}
