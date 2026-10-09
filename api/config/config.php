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

    // Item icons are downloaded into public/icons by bin/download_icons.php;
    // icons not downloaded yet are linked from the source site.
    "icons" => [
        "dir"    => __DIR__ . "/../public/icons",
        "url"    => "/BDO-website/api/public/icons",
        "source" => "https://bdocodex.com",
    ],

    "market" => [
        "api"    => "https://api.arsha.io/v2",
        "region" => "eu",  // na, eu, sea, mena, kr, ru, jp, th, tw, sa, console_eu, ...
    ],

    // bin/worker.php: how often each task runs, and how soon a failed one is retried
    "worker" => [
        "prices_minutes"  => 60,  // market prices (bin/update_prices.php)
        "game_data_hours" => 24,  // bdocodex items, recipes, mastery; then icons
        "retry_minutes"   => 15,
    ],

    // true: API errors include the exception message (never enable in production)
    "debug" => false,
];
