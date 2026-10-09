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
the importer skips it when it is missing. Nothing runs on its own: start the
worker (below) while you want the data kept fresh.

Settings live in `config/config.php`; put machine-specific overrides in
`config/config.local.php` (git-ignored). The environment variables
`BDO_DB_HOST`, `BDO_DB_NAME`, `BDO_DB_USER` and `BDO_DB_PASS` override both.

## Keeping the data fresh

```bash
php bin/worker.php          # runs until Ctrl+C
```

The worker runs the data scripts on a schedule while it is running: game data
(`scrape.php` → `import.php` → `download_icons.php`) once a day, market prices
(`update_prices.php`) every hour. When it is stopped the site keeps working on
the data of its last run. The last run of each task is kept in the database
(`worker_runs`), so starting it again only runs what is due; a failed task is
retried after 15 minutes. `--now` runs everything right away first; `--once`
runs what is due and exits. Intervals: `"worker"` in `config/config.php`.

## Running on a server

The same code runs on any machine with PHP 8.2+, MariaDB and Apache — a spare
computer at home or a rented server. Composer and Node are only needed where
you build: copy the built site (`site/dist/`) along.

1. **Packages** (Arch Linux): `sudo pacman -S apache php php-apache mariadb`.
   In `/etc/httpd/conf/httpd.conf` load `mod_rewrite` and PHP (`php_module`,
   with `mpm_prefork` instead of `mpm_event`), and allow `.htaccess` files:
   `AllowOverride All` for the document root (`/srv/http`).
2. **Database**: `sudo mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql`,
   `sudo systemctl enable --now mariadb httpd`, then create the database and a
   user with a password (not root), and put them in `config/config.local.php`.
3. **Code and data**: put the project in `/srv/http/BDO-website`, so the
   URLs are the same as under XAMPP (`/BDO-website/site/`, no rebuild). Copy
   what git does not have: `site/dist/`, `api/public/icons/`, `api/data/`, and
   the database (`mysqldump bdo_craft > bdo_craft.sql` here, `mariadb bdo_craft < bdo_craft.sql` there).
4. **Worker as a service**, so it starts with the machine:

   ```ini
   # /etc/systemd/system/bdo-worker.service
   [Unit]
   Description=BDO Craft data worker
   After=network-online.target mariadb.service
   Wants=network-online.target

   [Service]
   User=http
   WorkingDirectory=/srv/http/BDO-website/api
   ExecStart=/usr/bin/php bin/worker.php
   Restart=always
   RestartSec=60

   [Install]
   WantedBy=multi-user.target
   ```

   `sudo systemctl enable --now bdo-worker`; its output: `journalctl -u bdo-worker -f`.
   The `http` user needs write access to `api/data/` and `api/public/icons/`.
   Without systemd, cron works too: `*/10 * * * * cd /srv/http/BDO-website/api && php bin/worker.php --once`.
5. **Open it** from another computer at `http://<server ip>/BDO-website/site/`.

On the home network that is all. Before opening it to the internet: a domain
with HTTPS (certbot), a firewall that only lets the web server through, and
`"debug" => false` (the default). To serve the site at another path, build it
with `SITE_BASE=/ npm run build` and set `VITE_API_BASE` in `site/.env` and
`"icons" => ["url" => ...]` in `config.local.php` to match.

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
| `bin/update_prices.php` | Fetches prices from arsha.io for every recipe item not updated in the last hour (items not on the market: once a day) and records the day's price in the price history. The market API blocks fast clients now and then; failed batches are retried on the next run. `--force` refreshes everything, or pass item ids. |
| `bin/worker.php` | Runs the scripts above on a schedule while it runs (see Keeping the data fresh). |
| `bin/download_icons.php` | Downloads the icons of all recipes and recipe items into `public/icons/`. Skips icons already on disk, stops after 10 failures in a row. Until an icon is downloaded the API links the source. |

## Endpoints

`public/index.php` lists them with examples.

- `items.php` — `?id=` one item (details, price, recipes that make it, recipes it is a rare product of) or `?search=` (`craftable=1`, `source=`)
- `recipes.php` — `?source=&id=` one recipe, `?item_id=&grouped=1` recipes for an item, `?categories=1`, or a filtered list (`with_ingredients=1`: ingredient slots with substitutes, and rare products; `per_product=1`: one row per product and category, the default recipe with `product_recipes`)
- `craft.php?item_id=&qty=` — crafting plan: materials to buy, steps in crafting order, cost and the recipe tree
  - `mode=cheapest` buys intermediates when the market price is lower than crafting them
  - `recipe[item]=source:id`, `buy=id,id`, `substitute[item]=item`, `yield=min|avg|max`
  - `mastery[cooking]=1500`, `mastery[alchemy]=…` adds the mastery's extra products to cooking / alchemy crafts
  - `have[9059]=30` — units the player already has: used before anything is crafted or bought (an intermediate from stock needs no ingredients). `cost.total` is then what is left to buy; `cost.stock_value` is the stock used at market price, and the profit counts it.
- `prices.php?item_id=&days=30` — price history, one point per day (`t` = the day at 00:00 UTC)
- `mastery.php` — cooking and alchemy mastery tables (product, rare, Imperial bonus per 50 mastery)
- `imperial.php?skill=cooking|alchemy` — Imperial delivery boxes with base price and recipes; the NPC pays base price × (2.5 + mastery Imperial bonus)

Every response is JSON; errors are `{"error": "..."}` with a 4xx/5xx status.

## Data notes

- Source data is scraped from bdocodex (`data/*.json`).
- Each recipe slot has a default ingredient and optional substitutes (`recipe_inputs.slot`); a substitute can need a different amount (Purified Water ×3 for Mineral Water ×6).
- bdocodex lists recipes again for fixed ingredient combinations (Citron Tea: 6 times). The importer folds these variants into the main recipe of the product and category (named after the product); a variant that differs in more than one ingredient stays a recipe of its own. Processing variants fold only when they give the same products; Imperial delivery recipes never fold.
- Substitute amounts come from the variants when they show them. Otherwise they are estimated from the grade: every grade step up halves the amount (rounded down, at least 1), a step down doubles it (`estimate_substitute_qty()`). Estimates that change the amount are flagged in `recipe_inputs.qty_estimated` and in the API (`estimated`).
- `recipe_outputs.is_main` marks the product a recipe is for. The other outputs of cooking and alchemy recipes are rare products (Cold Draft Beer when making Beer); in processing they are byproducts. The game does not publish the rare chance; the API gives the life skill level it needs, read from the item description (`rare_requirement()`). Rare products are listed in the calculator but not counted in its totals.
- Recipes without ingredients or products in the scrape (about 230 processing recipes) are skipped.
- Prices: `item_price()` uses the market base price, or the NPC price for items whose description says a vendor sells them. `item_details.buy_price` alone is not a real price.
- Yield per craft is the average of the recipe's output range, plus the mastery's extra products for cooking and alchemy. Rare products and processing mastery are not counted.
- Price history starts the day prices were first updated; it is not backfilled.
