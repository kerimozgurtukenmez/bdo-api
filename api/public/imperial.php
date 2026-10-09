<?php
// GET imperial.php?skill=cooking|alchemy
//
// Imperial delivery boxes of a life skill and the recipes that pack them:
// { skill, boxes: [{
//     item: { id, name, grade, grade_name, icon },
//     tier,          // Apprentice … Guru
//     base_price,    // the NPC pays base_price × (2.5 + mastery bonus), tax free
//     recipes: [{ key, ingredients: [{ item, qty, price: { unit, source } | null }] }]
// }] }
// Boxes are ordered by tier, recipes by the cost of buying their ingredients.

declare(strict_types=1);

require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../src/recipes.php";
require __DIR__ . "/../src/imperial.php";
api_init();

json_out(imperial_boxes(param_enum("skill", array_keys(IMPERIAL_CATEGORY), "cooking")));
