<?php
// Corrections where the calculator's automatic choice is wrong for the game.
// Add an item when you find one; every request reads this file.
//
//   "buy"    => item ids bought by default instead of crafted (players buy or
//               farm them; crafting them is a detour or a loop)
//   "recipe" => item id => "source:recipe id" to use by default
//
// The player can still craft or pick another recipe in the calculator.
return [
    "buy" => [
        16001,  // Black Stone: dropped and sold everywhere; its recipe grinds Black Gem, which is made of Black Stones
    ],
    "recipe" => [],
];
