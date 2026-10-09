<?php
// ─────────────────────────────────────────────────────────────────────────────
// Checks that this machine can run BDO Craft and says what to fix.
//
//   php bin/doctor.php
//
// Exits with 1 when something required is missing.
// ─────────────────────────────────────────────────────────────────────────────

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    exit("Run this script from the command line.\n");
}

require __DIR__ . "/../src/bootstrap.php";

$root   = dirname(__DIR__, 2);
$failed = false;

$check = function (bool $ok, string $what, string $fix = "", bool $required = true) use (&$failed): void {
    echo ($ok ? "  ok    " : ($required ? "  FAIL  " : "  warn  ")) . $what . ($ok || $fix === "" ? "" : "\n          → $fix") . "\n";
    if (!$ok && $required) {
        $failed = true;
    }
};

echo "PHP\n";
$check(version_compare(PHP_VERSION, "8.2.0", ">="), "PHP " . PHP_VERSION . " (8.2 or newer)", "install a newer php");
foreach (["pdo_mysql", "curl", "mbstring", "json"] as $ext) {
    $check(extension_loaded($ext), "extension $ext", "enable extension=$ext in php.ini (php --ini shows where it is)");
}

echo "Database\n";
$pdo = null;
try {
    $pdo = db();
    $check(true, "connected to " . config("db")["name"] . " as " . config("db")["user"]);
} catch (Throwable $e) {
    $check(false, "connection: " . $e->getMessage(), "start MariaDB and set the user/password in api/config/config.local.php");
}
if ($pdo) {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(["items", "recipes", "recipe_inputs", "item_prices", "worker_runs"], $tables);
    $check(!$missing, "tables" . ($missing ? ": missing " . implode(", ", $missing) : ""), "php bin/migrate.php (or import a database dump)");
    if (in_array("recipes", $tables, true)) {
        $recipes = (int)$pdo->query("SELECT COUNT(*) FROM recipes")->fetchColumn();
        $check($recipes > 0, "$recipes recipes", "import a dump, or run php bin/worker.php --now");
    }
}

echo "Files\n";
$check(is_file("$root/site/dist/index.html"), "site built (site/dist)", "cd site && npm ci && npm run build");
$check(is_file(__DIR__ . "/../config/config.local.php"), "api/config/config.local.php", "only needed when the database user is not root without a password", required: false);
foreach (["api/data", "api/public/icons"] as $dir) {
    $path = "$root/$dir";
    $check(is_dir($path) && is_writable($path), "$dir writable", "mkdir -p $dir and make it writable for the user running the worker");
}
$check(is_file("$root/api/data/item_descriptions.json"), "api/data/item_descriptions.json", "copy it from the old machine (it cannot be downloaded again)", required: false);

echo $failed ? "\nSomething needs fixing (see above).\n" : "\nAll good.\n";
exit($failed ? 1 : 0);
