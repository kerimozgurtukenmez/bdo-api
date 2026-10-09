<?php
// Life skill mastery bonuses (table mastery_bonuses, from bdocodex)

declare(strict_types=1);

const MASTERY_SKILLS = ["cooking", "alchemy"];
const MAX_MASTERY    = 3000;

// Every table, ascending: ["cooking" => [{mastery, product, rare, imperial}], "alchemy" => [...]]
function mastery_tables(): array
{
    $tables = array_fill_keys(MASTERY_SKILLS, []);
    $rows = query("SELECT skill, mastery, product, rare, imperial FROM mastery_bonuses ORDER BY skill, mastery")->fetchAll();

    foreach ($rows as $row) {
        if (isset($tables[$row["skill"]])) {
            $tables[$row["skill"]][] = [
                "mastery"  => $row["mastery"],
                "product"  => (float)$row["product"],
                "rare"     => (float)$row["rare"],
                "imperial" => (float)$row["imperial"],
            ];
        }
    }

    return $tables;
}

// Bonuses at a mastery value: the table row at or below it, null without a table
function mastery_bonus(string $skill, int $mastery): ?array
{
    $row = query("
        SELECT mastery, product, rare, imperial FROM mastery_bonuses
        WHERE skill = ? AND mastery <= ?
        ORDER BY mastery DESC LIMIT 1
    ", [$skill, $mastery])->fetch();

    return $row ? array_map("floatval", $row) : null;
}
