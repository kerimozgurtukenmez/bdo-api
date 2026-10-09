# BDO Craft API

PHP + MariaDB API behind the BDO crafting calculator: items, cooking / alchemy /
processing recipes, Central Market prices, and a calculator that expands an
item into every material and crafting step needed to make it.

## Setup (XAMPP)

```bash
sudo /opt/lampp/lampp start
/opt/lampp/bin/mysql -uroot -e "CREATE DATABASE bdo_craft CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
/opt/lampp/bin/php import/import.php --fresh   # schema + bdocodex data (~10s)
/opt/lampp/bin/php import/update_prices.php    # market prices (~3 min)
```

Settings live in `config/config.php`; put machine-specific overrides in
`config/config.local.php` (git-ignored).

## Scripts

| Command | What it does |
| --- | --- |
| `import/scrape.php` | Downloads items and recipes from bdocodex (4 requests, cached for a day; `--refresh` to force) and rewrites the JSON files in `import/`, printing what changed. Run after a game patch, then `import.php`. |
| `import/import.php` | Re-imports the JSON files in `import/`. Recipes are replaced, items updated, existing prices kept. |
| `import/import.php --fresh` | Drops and recreates all tables from `database/schema.sql` first. Needed after schema changes. |
| `import/update_prices.php` | Fetches prices from arsha.io for every recipe item not updated in the last hour. The market API blocks fast clients now and then; failed batches are retried on the next run. `--force` refreshes everything, or pass item ids. |

## Endpoints

`api/index.php` lists them with examples.

- `items.php` — `?id=` one item (details, price, recipes that make it) or `?search=` (`craftable=1`, `source=`)
- `recipes.php` — `?source=&id=` one recipe, `?item_id=&grouped=1` recipes for an item, or a filtered list
- `craft.php?item_id=&qty=` — crafting plan: materials to buy, steps in crafting order, cost and the recipe tree
  - `mode=cheapest` buys intermediates when the market price is lower than crafting them
  - `recipe[item]=source:id`, `buy=id,id`, `substitute[item]=item`, `yield=min|avg|max`

Every response is JSON; errors are `{"error": "..."}` with a 4xx/5xx status.

## Data notes

- Source data is scraped from bdocodex (`import/*.json`).
- Each recipe slot has a default ingredient and optional substitutes (`recipe_inputs.slot`).
- `recipe_outputs.is_main` marks the product a recipe is for. The others are byproducts and rare procs.
- Recipes without ingredients or products in the scrape (210 processing recipes) are skipped.
- Prices: `item_price()` uses the market base price, or the NPC price for items whose description says a vendor sells them. `item_details.buy_price` alone is not a real price.
- Yield per craft is the average of the recipe's output range. Mastery and procs are not modelled.
