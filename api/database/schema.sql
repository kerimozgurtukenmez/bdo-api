-- ─────────────────────────────────────────────────────────────────────────────
-- BDO Craft database schema (MariaDB 10.4+ / MySQL 8+)
--
-- Create the database first, then load this file:
--   CREATE DATABASE bdo_craft CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
--   php import/import.php --fresh      (runs this file and imports the JSON data)
-- ─────────────────────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- Every item known to bdocodex (ingredients, products, equipment, ...)
CREATE TABLE IF NOT EXISTS items (
    id          INT UNSIGNED     NOT NULL,
    name        VARCHAR(255)     NOT NULL,
    grade       TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 White, 1 Green, 2 Blue, 3 Gold, 4 Orange
    grade_name  VARCHAR(20)      DEFAULT NULL,
    icon        VARCHAR(500)     DEFAULT NULL,
    link        VARCHAR(500)     DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_items_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Extra info scraped from item pages (only a subset of items has it)
CREATE TABLE IF NOT EXISTS item_details (
    item_id            INT UNSIGNED NOT NULL,
    name_kr            VARCHAR(255) DEFAULT NULL,
    category           VARCHAR(100) DEFAULT NULL,
    weight             VARCHAR(50)  DEFAULT NULL,
    description        TEXT         DEFAULT NULL,
    bound_on_obtain    TINYINT(1)   NOT NULL DEFAULT 0,
    personal_trade     TINYINT(1)   NOT NULL DEFAULT 0,
    -- Base prices from the game data. buy_price is only a real cost when
    -- vendor_sold = 1; for other items it is just the item's nominal value.
    buy_price          BIGINT       NOT NULL DEFAULT 0,
    sell_price         BIGINT       NOT NULL DEFAULT 0,
    vendor_sold        TINYINT(1)   NOT NULL DEFAULT 0,  -- description says an NPC sells it
    warehouse_capacity VARCHAR(50)  DEFAULT NULL,
    PRIMARY KEY (item_id),
    CONSTRAINT fk_item_details_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Central Market prices (arsha.io, region set in config). A row with
-- base_price = 0 means the item was checked and is not on the market.
CREATE TABLE IF NOT EXISTS item_prices (
    item_id         INT UNSIGNED NOT NULL,
    base_price      BIGINT       NOT NULL DEFAULT 0,
    current_stock   BIGINT       NOT NULL DEFAULT 0,
    total_trades    BIGINT       NOT NULL DEFAULT 0,
    price_min       BIGINT       NOT NULL DEFAULT 0,
    price_max       BIGINT       NOT NULL DEFAULT 0,
    last_sold_price BIGINT       NOT NULL DEFAULT 0,
    last_sold_time  BIGINT       NOT NULL DEFAULT 0,   -- unix timestamp
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id),
    CONSTRAINT fk_item_prices_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Recipe ids come from bdocodex and are only unique per source
CREATE TABLE IF NOT EXISTS recipes (
    source             VARCHAR(20)       NOT NULL,  -- cooking | alchemy | processing
    id                 INT UNSIGNED      NOT NULL,
    name               VARCHAR(255)      NOT NULL,
    category           VARCHAR(50)       NOT NULL,  -- Cooking, Alchemy, Heating, Grinding, ...
    grade              TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    grade_name         VARCHAR(20)       DEFAULT NULL,
    icon               VARCHAR(500)      DEFAULT NULL,
    link               VARCHAR(500)      DEFAULT NULL,
    skill_level        VARCHAR(30)       DEFAULT NULL,  -- e.g. "Apprentice 3"
    skill_sort         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    exp                INT UNSIGNED      DEFAULT NULL,  -- NULL = unknown
    ingredients_weight DECIMAL(8,2)      DEFAULT NULL,  -- total LT of one craft's ingredients
    PRIMARY KEY (source, id),
    KEY idx_recipes_list (source, category, skill_sort),
    KEY idx_recipes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per ingredient. Each slot has one default ingredient
-- (is_alternative = 0) and optionally substitutes that share its slot.
CREATE TABLE IF NOT EXISTS recipe_inputs (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    recipe_source  VARCHAR(20)      NOT NULL,
    recipe_id      INT UNSIGNED     NOT NULL,
    slot           TINYINT UNSIGNED NOT NULL,         -- 0-based position in the recipe
    item_id        INT UNSIGNED     NOT NULL,
    qty_min        INT UNSIGNED     NOT NULL DEFAULT 1,
    qty_max        INT UNSIGNED     NOT NULL DEFAULT 1,
    is_key         TINYINT(1)       NOT NULL DEFAULT 0,
    is_alternative TINYINT(1)       NOT NULL DEFAULT 0,
    slot_item_id   INT UNSIGNED     DEFAULT NULL,      -- default item of the slot (alternatives only)
    PRIMARY KEY (id),
    KEY idx_inputs_recipe (recipe_source, recipe_id, slot),
    KEY idx_inputs_item (item_id),
    CONSTRAINT fk_inputs_recipe FOREIGN KEY (recipe_source, recipe_id) REFERENCES recipes (source, id) ON DELETE CASCADE,
    CONSTRAINT fk_inputs_item   FOREIGN KEY (item_id) REFERENCES items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Products of a recipe. is_main marks the item the recipe is made for;
-- the others are byproducts / rare procs.
CREATE TABLE IF NOT EXISTS recipe_outputs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipe_source VARCHAR(20)  NOT NULL,
    recipe_id     INT UNSIGNED NOT NULL,
    item_id       INT UNSIGNED NOT NULL,
    qty_min       INT UNSIGNED NOT NULL DEFAULT 1,
    qty_max       INT UNSIGNED NOT NULL DEFAULT 1,
    is_main       TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_outputs_recipe (recipe_source, recipe_id),
    KEY idx_outputs_item (item_id, is_main),
    CONSTRAINT fk_outputs_recipe FOREIGN KEY (recipe_source, recipe_id) REFERENCES recipes (source, id) ON DELETE CASCADE,
    CONSTRAINT fk_outputs_item   FOREIGN KEY (item_id) REFERENCES items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
