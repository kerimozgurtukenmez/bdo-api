<?php
// Default settings. To override them on a machine, create config.local.php
// next to this file (it is git-ignored) and return only the keys to change:
//   <?php return ["db" => ["pass" => "secret"]];

return [
    "db" => [
        "host"    => "localhost",
        "name"    => "bdo_craft",
        "user"    => "root",
        "pass"    => "",
        "charset" => "utf8mb4",
    ],

    "market" => [
        "api"    => "https://api.arsha.io/v2",
        "region" => "eu",  // na, eu, sea, mena, kr, ru, jp, th, tw, sa, console_eu, ...
    ],

    // true: API errors include the exception message (never enable in production)
    "debug" => false,
];
