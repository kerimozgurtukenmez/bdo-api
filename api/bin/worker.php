<?php
// ─────────────────────────────────────────────────────────────────────────────
// Keeps the data fresh while it runs. Start it in a terminal, stop it with
// Ctrl+C; the site works without it, on the data of the last run.
//
//   php bin/worker.php          run each task when it is due, until stopped
//   php bin/worker.php --now    the same, but run every task right away first
//   php bin/worker.php --once   run the tasks that are due, then exit (cron)
//
// Tasks, in this order ("worker" in config/config.php sets how often):
//   game data   scrape.php → import.php → download_icons.php   every 24 hours
//   prices      update_prices.php                              every 60 minutes
//
// The last run of each task is kept in the database (worker_runs), so a
// restart does not repeat what is not due yet. A failed task is retried after
// 15 minutes. On a server, run it as a service or from cron with --once.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/importer.php";
require __DIR__ . "/../src/worker.php";

$once    = in_array("--once", $argv, true);
$runAll  = in_array("--now", $argv, true);
$config  = config("worker");
$tasks   = worker_tasks($config);
$retry   = $config["retry_minutes"] * 60;

function say(string $message): void
{
    echo "[" . date("Y-m-d H:i:s") . "] $message\n";
}

// Runs the steps of a task one after another; returns [ok, message]
function run_task(array $task): array
{
    foreach ($task["steps"] as $step) {
        $script = array_shift($step);
        $args   = $step;
        $command = [PHP_BINARY, __DIR__ . "/$script", ...$args];
        say("  → php bin/$script " . implode(" ", $args));
        // The script writes straight to this terminal
        $process = proc_open($command, [0 => ["file", "/dev/null", "r"], 1 => STDOUT, 2 => STDERR], $pipes);
        if ($process === false) {
            return [false, "could not start $script"];
        }
        $exit = proc_close($process);
        if ($exit !== 0) {
            return [false, "$script failed (exit $exit)"];
        }
    }
    return [true, "ok"];
}

say("Worker started: prices every {$config['prices_minutes']} min, game data every {$config['game_data_hours']} h."
    . ($once ? "" : " Ctrl+C stops it."));

while (true) {
    try {
        // A fresh connection per round: the database may have restarted meanwhile
        $pdo = db(db_connect());
        apply_schema($pdo);  // creates worker_runs on databases older than it

        $due = $runAll ? array_keys($tasks) : worker_due($tasks, worker_last_runs($pdo), $retry, time());
        $runAll = false;

        foreach ($due as $name) {
            say("Running $name...");
            $started = time();
            worker_record_start($pdo, $name);
            [$ok, $message] = run_task($tasks[$name]);
            $pdo = db(db_connect());
            worker_record_end($pdo, $name, $ok, $message);
            say(($ok ? "Done" : "Failed") . ": $name in " . (time() - $started) . "s" . ($ok ? "" : " — $message, retried in {$config['retry_minutes']} min"));
        }

        if ($once) {
            break;
        }

        $last = worker_last_runs($pdo);
        $next = [];
        foreach ($tasks as $name => $task) {
            $next[$name] = worker_next_run($task, $last[$name] ?? null, $retry);
        }
        asort($next);
        $wait = max(5, reset($next) - time());
        say("Next: " . key($next) . " at " . date("H:i", time() + $wait) . ".");
        sleep($wait);
    } catch (Throwable $e) {
        say("Error: " . $e->getMessage() . ($once ? "" : " — trying again in a minute."));
        if ($once) {
            exit(1);
        }
        sleep(60);
    }
}
