# BDO Craft API

PHP + MariaDB API behind the BDO crafting calculator: items, cooking / alchemy /
processing recipes, Central Market prices, and a calculator that expands an
item into every material and crafting step needed to make it.

## Layout

| Folder | What |
| --- | --- |
| `public/` | HTTP endpoints and downloaded icons — the only folder that is served |
| `src/` | Library code shared by endpoints, scripts and tests |
| `bin/` | Command-line data scripts |
| `data/` | Downloaded data and download cache (not in git) |
| `database/` | `schema.sql` |
| `config/` | `config.php` defaults, optional `config.local.php` |
| `tests/` | PHPUnit tests |

## Setup (XAMPP)

```bash
sudo /opt/lampp/lampp start
/opt/lampp/bin/mysql -uroot -e "CREATE DATABASE bdo_craft CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
php bin/scrape.php           # bdocodex data → data/*.json (4 requests)
php bin/import.php --fresh   # schema + data (~10s)
php bin/update_prices.php    # market prices (minutes; rerun if blocked)
php bin/download_icons.php   # item icons (hours: the source throttles; rerun to resume)
```

Downloaded data (`data/`), icons (`public/icons/`) and database dumps are not
in git. `data/item_descriptions.json` (item details) cannot be re-scraped yet;
the importer skips it when it is missing. Nothing is scheduled on a development
machine: run the scripts yourself when you want fresh data.

Settings live in `config/config.php`; put machine-specific overrides in
`config/config.local.php` (git-ignored). The environment variables
`BDO_DB_HOST`, `BDO_DB_NAME`, `BDO_DB_USER` and `BDO_DB_PASS` override both.

## Development

The API itself has no dependencies; Composer only brings the dev tools.

```bash
composer install
composer test      # PHPUnit: unit tests + calculator tests against a throwaway bdo_craft_test database
composer analyse   # PHPStan (level 6)
composer check     # both
```

The calculator tests (`tests/Integration`) build `bdo_craft_test` from
`database/schema.sql` with a small hand-made data set; the expected numbers in
the tests are worked out by hand from it.

## Scripts

| Command | What it does |
| --- | --- |
| `bin/scrape.php` | Downloads items, recipes, the cooking/alchemy mastery tables and the Imperial boxes' item pages (their base price) from bdocodex — about 20 requests, cached for a day in `data/cache/`; `--refresh` to force — and rewrites the JSON files in `data/`, printing what changed. Run after a game patch, then `import.php`. |
| `bin/import.php` | Imports the JSON files in `data/`: recipes and mastery tables are replaced, items updated, items no longer listed removed, market prices and price history kept. |
| `bin/import.php --fresh` | Drops and recreates all tables from `database/schema.sql` first. Needed after schema changes. |
| `bin/update_prices.php` | Fetches prices from arsha.io for every recipe item not updated in the last hour and records the day's price in the price history. The market API blocks fast clients now and then; failed batches are retried on the next run. `--force` refreshes everything, or pass item ids. |
| `bin/download_icons.php` | Downloads the icons of all recipes and recipe items into `public/icons/`. Skips icons already on disk, stops after 10 failures in a row. Until an icon is downloaded the API links the source. |

## Endpoints

`public/index.php` lists them with examples.

- `items.php` — `?id=` one item (details, price, recipes that make it) or `?search=` (`craftable=1`, `source=`)
- `recipes.php` — `?source=&id=` one recipe, `?item_id=&grouped=1` recipes for an item, `?categories=1`, or a filtered list
- `craft.php?item_id=&qty=` — crafting plan: materials to buy, steps in crafting order, cost and the recipe tree
  - `mode=cheapest` buys intermediates when the market price is lower than crafting them
  - `recipe[item]=source:id`, `buy=id,id`, `substitute[item]=item`, `yield=min|avg|max`
  - `mastery[cooking]=1500`, `mastery[alchemy]=…` adds the mastery's extra products to cooking / alchemy crafts
- `prices.php?item_id=&days=30` — price history, one point per day (`t` = the day at 00:00 UTC)
- `mastery.php` — cooking and alchemy mastery tables (product, rare, Imperial bonus per 50 mastery)
- `imperial.php?skill=cooking|alchemy` — Imperial delivery boxes with base price and recipes; the NPC pays base price × (2.5 + mastery Imperial bonus)

Every response is JSON; errors are `{"error": "..."}` with a 4xx/5xx status.

## Data notes

- Source data is scraped from bdocodex (`data/*.json`).
- Each recipe slot has a default ingredient and optional substitutes (`recipe_inputs.slot`).
- `recipe_outputs.is_main` marks the product a recipe is for. The others are byproducts and rare procs.
- Recipes without ingredients or products in the scrape (about 230 processing recipes) are skipped.
- Prices: `item_price()` uses the market base price, or the NPC price for items whose description says a vendor sells them. `item_details.buy_price` alone is not a real price.
- Yield per craft is the average of the recipe's output range, plus the mastery's extra products for cooking and alchemy. Rare procs and processing mastery are not modelled.
- Price history starts the day prices were first updated; it is not backfilled.
