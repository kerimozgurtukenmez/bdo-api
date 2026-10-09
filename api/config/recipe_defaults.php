<?php
// Corrections where the calculator's automatic choice is wrong for the game.
// Add an item when you find one; every request reads this file.
//
//   "buy"    => item ids bought by default instead of crafted (players buy or
//               farm them; crafting them is a detour or a loop)
//   "recipe" => item id => "source:recipe id" to use by default
//
// The player can still craft or pick another recipe in the calculator.
// Items whose own recipe chain needs them again (Black Stone ← Black Gem ←
// Black Stone) are bought automatically and need no entry here.
return [
    "buy"    => [],
    "recipe" => [],
];
