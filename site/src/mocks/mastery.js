// GET mastery.php
//
// Life skill mastery bonus tables (bdocodex "Mastery" pages), mastery 0–3000 in
// steps of 50, ascending. Values are fractions (0.7645 = +76.45%):
// {
//   cooking: [{ mastery: 0, product: 0, rare: 0, imperial: 0 }, ...],
//   alchemy: [...]
// }
//   product  — extra products per craft
//   rare     — extra rare products (cooking) / rare item chance (alchemy)
//   imperial — Imperial delivery ("royal trade") silver bonus
// Processing mastery changes mass-processing counts, not the yield, so it has
// no table here.

// Rough curves through a few known points; the API serves the real tables
const ANCHORS = {
  cooking: { product: [0, 0.0586, 0.17, 0.31, 0.46, 0.62, 0.7645], rare: [0, 0.0196, 0.055, 0.1, 0.145, 0.195, 0.242], imperial: [0, 0.2116, 0.55, 0.9, 1.25, 1.55, 1.8125] },
  alchemy: { product: [0, 0.048, 0.14, 0.26, 0.38, 0.51, 0.625], rare: [0, 0.01, 0.03, 0.05, 0.07, 0.09, 0.11], imperial: [0, 0.2116, 0.55, 0.9, 1.25, 1.55, 1.8125] },
}

function curve(points, mastery) {
  const position = mastery / 500  // anchors every 500 mastery
  const i = Math.min(Math.floor(position), points.length - 2)
  return points[i] + (points[i + 1] - points[i]) * (position - i)
}

export function masteryTables() {
  const tables = {}
  for (const [skill, anchors] of Object.entries(ANCHORS)) {
    tables[skill] = []
    for (let mastery = 0; mastery <= 3000; mastery += 50) {
      tables[skill].push({
        mastery,
        product: +curve(anchors.product, mastery).toFixed(4),
        rare: +curve(anchors.rare, mastery).toFixed(4),
        imperial: +curve(anchors.imperial, mastery).toFixed(4),
      })
    }
  }
  return { mock: true, ...tables }
}
