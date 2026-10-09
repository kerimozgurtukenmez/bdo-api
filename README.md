# BDO Craft

Crafting calculator for Black Desert Online life skills (cooking, alchemy,
processing): search an item, enter how many to make, and get every material,
crafting step, cost and profit down the whole recipe tree.

| Folder | What it is |
| --- | --- |
| [`api/`](api/README.md) | PHP + MariaDB API: data import from bdocodex, market prices, crafting calculator |
| `site/` | The website (PHP pages + JavaScript) |
| [`ROADMAP.md`](ROADMAP.md) | Plan and progress |

## Local development (XAMPP)

The repository lives in `/opt/lampp/htdocs/BDO-website`:

- Site: http://localhost/BDO-website/site/
- API: http://localhost/BDO-website/api/public/

Database setup and data scripts are described in [`api/README.md`](api/README.md).

Data comes from [bdocodex](https://bdocodex.com), used with the site owner's
permission, and market prices from [arsha.io](https://api.arsha.io).
