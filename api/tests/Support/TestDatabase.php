<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

// A throwaway database built from database/schema.sql and filled with a small,
// hand-made data set. Its name comes from BDO_DB_NAME (phpunit.xml).
final class TestDatabase
{
    public static function create(): PDO
    {
        $c = config("db");
        if (!str_ends_with($c["name"], "_test")) {
            throw new RuntimeException("Refusing to reset '{$c['name']}': test database names must end in _test");
        }

        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $server  = new PDO("mysql:host={$c['host']};charset=utf8mb4", $c["user"], $c["pass"], $options);
        $server->exec("DROP DATABASE IF EXISTS `{$c['name']}`");
        $server->exec("CREATE DATABASE `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c["user"], $c["pass"],
            $options + [PDO::ATTR_EMULATE_PREPARES => false]);
        apply_schema($pdo);

        return $pdo;
    }

    /**
     * @param array<int, array{0: string, 1?: int|null, 2?: int|null}> $items
     *        id => [name, market price, NPC vendor price]
     */
    public static function addItems(PDO $pdo, array $items): void
    {
        foreach ($items as $id => $item) {
            [$name, $market, $vendor] = $item + [1 => null, 2 => null];

            $pdo->prepare("INSERT INTO items (id, name) VALUES (?, ?)")->execute([$id, $name]);
            if ($market !== null) {
                $pdo->prepare("INSERT INTO item_prices (item_id, base_price) VALUES (?, ?)")->execute([$id, $market]);
            }
            if ($vendor !== null) {
                $pdo->prepare("INSERT INTO item_details (item_id, buy_price, vendor_sold) VALUES (?, ?, 1)")->execute([$id, $vendor]);
            }
        }
    }

    /**
     * @param list<array{0: int, 1: int, 2?: list<int>}> $ingredients [item id, qty, substitutes]
     * @param list<array{0: int, 1: int, 2: int}>        $outputs     [item id, min, max]; the first is the main product
     */
    public static function addRecipe(PDO $pdo, string $source, int $id, string $name, string $category,
                                     array $ingredients, array $outputs, ?int $exp = null): void
    {
        $pdo->prepare("INSERT INTO recipes (source, id, name, category, skill_level, exp) VALUES (?, ?, ?, ?, 'Beginner 1', ?)")
            ->execute([$source, $id, $name, $category, $exp]);

        foreach ($ingredients as $slot => $ing) {
            [$itemId, $qty, $alternatives] = $ing + [2 => []];
            $insert = $pdo->prepare("
                INSERT INTO recipe_inputs (recipe_source, recipe_id, slot, item_id, qty_min, qty_max, is_alternative, slot_item_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([$source, $id, $slot, $itemId, $qty, $qty, 0, null]);
            foreach ($alternatives as $alt) {
                $insert->execute([$source, $id, $slot, $alt, $qty, $qty, 1, $itemId]);
            }
        }

        foreach ($outputs as $i => [$itemId, $min, $max]) {
            $pdo->prepare("INSERT INTO recipe_outputs (recipe_source, recipe_id, item_id, qty_min, qty_max, is_main) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$source, $id, $itemId, $min, $max, $i === 0 ? 1 : 0]);
        }
    }
}
