<?php
// Shared setup for API endpoints and CLI scripts: config, database, helpers.

declare(strict_types=1);

const RECIPE_SOURCES = ["cooking", "alchemy", "processing"];

// XAMPP ships serialize_precision = 100, which makes json_encode print 0.98 as
// 0.979999999999999982236431605997495353221893310546875
ini_set("serialize_precision", "-1");

function config(?string $key = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . "/../config/config.php";
        $local  = __DIR__ . "/../config/config.local.php";
        if (is_file($local)) {
            $config = array_replace_recursive($config, require $local);
        }

        // Environment variables win over both files (tests, Docker, CI)
        $env = ["host" => "BDO_DB_HOST", "name" => "BDO_DB_NAME", "user" => "BDO_DB_USER", "pass" => "BDO_DB_PASS"];
        foreach ($env as $setting => $variable) {
            $value = getenv($variable);
            if ($value !== false) {
                $config["db"][$setting] = $value;
            }
        }
    }

    return $key === null ? $config : ($config[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $c = config("db");
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}",
            $c["user"],
            $c["pass"],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    return $pdo;
}

// Prepare and run a query; ints are bound as integers (needed for LIMIT).
function query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $stmt->bindValue($i + 1, $value, match (true) {
            is_int($value)  => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        });
    }
    $stmt->execute();

    return $stmt;
}

// "?, ?, ?" for an IN (...) list
function placeholders(array $values, string $one = "?"): string
{
    return implode(", ", array_fill(0, count($values), $one));
}

// ─────────────────────────────────────────────────────────────────────────────
// HTTP helpers (API endpoints)
// ─────────────────────────────────────────────────────────────────────────────

class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

// Call at the top of every endpoint: JSON headers, CORS and error handling.
function api_init(): void
{
    header("Content-Type: application/json; charset=utf-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");

    if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "OPTIONS") {
        http_response_code(204);
        exit;
    }

    set_exception_handler(function (Throwable $e) {
        if ($e instanceof ApiError) {
            json_out(["error" => $e->getMessage()], $e->status);
        }

        error_log("[bdo-api] " . $e);
        json_out(
            ["error" => config("debug") ? $e->getMessage() : "Internal server error"],
            500
        );
    });
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Integer query parameter. Missing → $default, invalid or out of range → 400.
function param_int(string $name, ?int $default = null, int $min = 1, int $max = PHP_INT_MAX): ?int
{
    $raw = $_GET[$name] ?? null;
    if ($raw === null || $raw === "") {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
        throw new ApiError("'$name' must be an integer between $min and $max");
    }

    return $value;
}

function param_str(string $name, int $maxLength = 100): ?string
{
    $raw = $_GET[$name] ?? null;
    if (!is_string($raw)) {
        return null;
    }

    $value = trim($raw);
    if ($value === "") {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        throw new ApiError("'$name' is too long (max $maxLength characters)");
    }

    return $value;
}

function param_enum(string $name, array $allowed, ?string $default = null): ?string
{
    $value = param_str($name);
    if ($value === null) {
        return $default;
    }
    if (!in_array($value, $allowed, true)) {
        throw new ApiError("'$name' must be one of: " . implode(", ", $allowed));
    }

    return $value;
}

function param_bool(string $name): bool
{
    return isset($_GET[$name]) && !in_array($_GET[$name], ["0", "false", ""], true);
}

// Page / per-page parameters. Accepts both "limit" and "per_page".
function pagination(int $defaultLimit = 50, int $maxLimit = 100): array
{
    $page  = param_int("page", 1);
    $limit = param_int("limit", null, 1, $maxLimit) ?? param_int("per_page", $defaultLimit, 1, $maxLimit);

    return [$page, $limit, ($page - 1) * $limit];
}

function paginated(array $data, int $total, int $page, int $limit): array
{
    return [
        "data"        => $data,
        "total"       => $total,
        "page"        => $page,
        "per_page"    => $limit,
        "total_pages" => (int)ceil($total / $limit),
    ];
}

// Escape LIKE wildcards in user input
function like_contains(string $value): string
{
    return "%" . addcslashes($value, "%_\\") . "%";
}

// ─────────────────────────────────────────────────────────────────────────────
// Icons
// ─────────────────────────────────────────────────────────────────────────────

// Icons are stored as their path on the source site ("items/new_icon/....webp").
// Served from our own copy when it has been downloaded.
function icon_url(?string $path): ?string
{
    if ($path === null || $path === "") {
        return null;
    }

    $icons = config("icons");
    if (is_file($icons["dir"] . "/" . $path)) {
        return $icons["url"] . "/" . $path;
    }
    return $icons["source"] . "/" . $path;
}

// ─────────────────────────────────────────────────────────────────────────────
// Prices
// ─────────────────────────────────────────────────────────────────────────────

// Columns needed by item_price(). Expects aliases d = item_details, p = item_prices.
const PRICE_COLUMNS = "
    p.base_price, p.last_sold_price, UNIX_TIMESTAMP(p.updated_at) AS price_updated_at,
    d.buy_price, d.sell_price, d.vendor_sold
";

// What one unit of an item costs to buy: Central Market price, or NPC vendor
// price for vendor-sold items (whichever is cheaper). null = unknown.
function item_price(array $row): ?array
{
    $options = [];

    $market = (int)($row["base_price"] ?? 0) ?: (int)($row["last_sold_price"] ?? 0);
    if ($market > 0) {
        $options[] = ["unit" => $market, "source" => "market"];
    }

    if (!empty($row["vendor_sold"]) && (int)$row["buy_price"] > 0) {
        $options[] = ["unit" => (int)$row["buy_price"], "source" => "vendor"];
    }

    if (!$options) {
        return null;
    }

    usort($options, fn($a, $b) => $a["unit"] <=> $b["unit"]);
    return $options[0];
}

// Remove the raw price columns from a row and add a "price" object instead.
function with_price(array $row, bool $keepRaw = false): array
{
    $row["price"] = item_price($row);

    if (!$keepRaw) {
        unset($row["base_price"], $row["vendor_sold"], $row["price_updated_at"]);
    }
    if (isset($row["vendor_sold"])) {
        $row["vendor_sold"] = (bool)$row["vendor_sold"];
    }

    return $row;
}
