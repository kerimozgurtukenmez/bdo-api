// GET prices.php?item_id=9213&days=30
//
// Central Market price history of one item, oldest first, one point per day:
// {
//   item_id: 9213,
//   region: "eu",
//   days: 30,
//   points: [{ t: 1789000000, price: 3050, stock: 1200 }, ...]   // t = Unix time (s)
// }
// An item never seen on the market returns points: [].

export async function priceHistory({ item_id: itemId, days = 30 }, request) {
  // Wander around the real current price so the sample looks plausible
  const item = await request('items.php', { id: itemId })
  const current = item.base_price || item.last_sold_price || 10000

  // Deterministic random walk per item, so the chart is stable between visits
  let seed = Number(itemId) * 9301 + 49297
  const random = () => {
    seed = (seed * 9301 + 49297) % 233280
    return seed / 233280
  }

  const now = Math.floor(Date.now() / 1000 / 86400) * 86400
  let price = Math.round(current * (0.85 + random() * 0.3))
  let stock = 200 + Math.round(random() * 3000)
  const points = []

  for (let day = Number(days) - 1; day >= 0; day--) {
    price = Math.max(1, Math.round(price * (1 + (random() - 0.5) * 0.05)))
    stock = Math.max(0, Math.round(stock * (1 + (random() - 0.5) * 0.3)))
    points.push({ t: now - day * 86400, price, stock })
  }

  return { mock: true, item_id: Number(itemId), region: 'eu', days: Number(days), points }
}
