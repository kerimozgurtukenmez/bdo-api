# BDO Craft

Crafting calculator for Black Desert Online life skills (cooking, alchemy,
processing): search an item, enter how many to make, and get every material,
crafting step, cost and profit down the whole recipe tree.

| Folder | What it is |
| --- | --- |
| [`api/`](api/README.md) | PHP + MariaDB API: data import from bdocodex, market prices, icons, crafting calculator |
| `site/` | The website: Vue 3 single-page app built with Vite |

## Local development (XAMPP)

The repository lives in `/opt/lampp/htdocs/BDO-website`. Set up the database
and data as described in [`api/README.md`](api/README.md), then:

```bash
cd site
npm install
npm run dev      # http://localhost:5173, API requests go to Apache
npm run build    # site/dist, served at http://localhost/BDO-website/site/dist/
npm run lint
```

- API: http://localhost/BDO-website/api/public/
- `VITE_API_BASE` (`site/.env`) sets where the site finds the API; `SITE_BASE`
  at build time sets the folder the site is served from.

Data comes from [bdocodex](https://bdocodex.com), used with the site owner's
permission, and market prices from [arsha.io](https://api.arsha.io).
