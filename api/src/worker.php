<?php
// The data tasks bin/worker.php runs, and when each one is due.

declare(strict_types=1);

// Tasks in the order they run (prices need the recipe items the game data
// brings). Each step is a script in bin/ with its arguments; a failing step
// ends the task, so a broken scrape is never imported.
function worker_tasks(array $config): array
{
    return [
        "game data" => [
            "every" => $config["game_data_hours"] * 3600,
            "steps" => [["scrape.php"], ["import.php"], ["download_icons.php"]],
        ],
        "prices" => [
            "every" => $config["prices_minutes"] * 60,
            "steps" => [["update_prices.php"]],
        ],
    ];
}

// Unix time a task is due next: now if it never ran, its interval after the
// last start, or $retry seconds after a failed (or interrupted) run
function worker_next_run(array $task, ?array $lastRun, int $retry): int
{
    if ($lastRun === null) {
        return 0;
    }
    return $lastRun["started_at"] + ($lastRun["ok"] ? $task["every"] : min($retry, $task["every"]));
}

// Names of the tasks due at $now, in run order
function worker_due(array $tasks, array $lastRuns, int $retry, int $now): array
{
    return array_keys(array_filter(
        $tasks,
        fn($task, $name) => worker_next_run($task, $lastRuns[$name] ?? null, $retry) <= $now,
        ARRAY_FILTER_USE_BOTH
    ));
}

// task => ["started_at" => unix time, "ok" => bool]
function worker_last_runs(PDO $pdo): array
{
    $runs = [];
    foreach ($pdo->query("SELECT task, UNIX_TIMESTAMP(started_at) AS started_at, ok FROM worker_runs") as $row) {
        $runs[$row["task"]] = ["started_at" => (int)$row["started_at"], "ok" => (bool)$row["ok"]];
    }
    return $runs;
}

function worker_record_start(PDO $pdo, string $task): void
{
    $pdo->prepare("
        INSERT INTO worker_runs (task, started_at, finished_at, ok, message) VALUES (?, NOW(), NULL, 0, 'running')
        ON DUPLICATE KEY UPDATE started_at = NOW(), finished_at = NULL, ok = 0, message = 'running'
    ")->execute([$task]);
}

function worker_record_end(PDO $pdo, string $task, bool $ok, string $message): void
{
    $pdo->prepare("UPDATE worker_runs SET finished_at = NOW(), ok = ?, message = ? WHERE task = ?")
        ->execute([$ok ? 1 : 0, mb_substr($message, 0, 255), $task]);
}
