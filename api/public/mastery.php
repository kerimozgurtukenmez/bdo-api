<?php
// GET mastery.php
//
// Mastery bonus tables, mastery 0–3000 in steps of 50, ascending; fractions:
// { cooking: [{ mastery, product, rare, imperial }], alchemy: [...] }
//   product  — extra products per craft
//   rare     — extra rare products (cooking) / rare item chance (alchemy)
//   imperial — Imperial delivery silver bonus

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/mastery.php";
api_init();

json_out(mastery_tables());
