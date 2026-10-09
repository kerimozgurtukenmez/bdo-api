// GET imperial.php?skill=cooking|alchemy
//
// Imperial delivery boxes of a life skill and the recipes that pack them:
// {
//   skill: "cooking",
//   boxes: [{
//     item: { id, name, grade, grade_name, icon },
//     tier: "Master",            // Apprentice, Skilled, Professional, Artisan, Master, Guru
//     base_price: 550000,        // the delivery NPC pays base_price × (2.5 + mastery bonus)
//     recipes: [{
//       key: "processing:2230",
//       ingredients: [{ item: { id, name, grade, grade_name, icon }, qty: 18, price: { unit, source } | null }]
//     }]
//   }]
// }
// Boxes are ordered by tier; recipes by the cost of buying their ingredients.
//
// This mock builds the list from recipes.php; only base_price is made up
// (community figures). The API should read the boxes' real values.

const CATEGORY = { cooking: 'Imperial Cuisine', alchemy: 'Imperial Alchemy' }
const TIERS = ['Apprentice', 'Skilled', 'Professional', 'Artisan', 'Master', 'Guru']
const BASE_PRICE = { Apprentice: 130000, Skilled: 200000, Professional: 300000, Artisan: 400000, Master: 550000, Guru: 800000 }

const cache = {}

export async function imperialBoxes({ skill = 'cooking' }, request) {
  // A failed load is not cached, so the next visit tries again
  cache[skill] ??= load(skill, request).catch((error) => {
    delete cache[skill]
    throw error
  })
  return cache[skill]
}

async function load(skill, request) {
  const recipes = []
  for (let page = 1; ; page++) {
    const result = await request('recipes.php', {
      source: 'processing',
      category: CATEGORY[skill],
      with_ingredients: 1,
      limit: 100,
      page,
    })
    recipes.push(...result.data)
    if (page >= result.total_pages) break
  }

  const boxes = new Map()
  for (const recipe of recipes) {
    const tier = TIERS.find((t) => recipe.name.startsWith(t))
    if (!tier || !recipe.name.includes('Box')) continue  // a few odd non-box recipes share the category

    if (!boxes.has(recipe.item_id)) {
      boxes.set(recipe.item_id, {
        item: { id: recipe.item_id, name: recipe.name, grade: recipe.grade, grade_name: recipe.grade_name, icon: recipe.icon },
        tier,
        base_price: BASE_PRICE[tier],
        recipes: [],
      })
    }

    boxes.get(recipe.item_id).recipes.push({
      key: recipe.key,
      ingredients: recipe.ingredients.map((ing) => ({
        item: { id: ing.item_id, name: ing.name, grade: ing.grade, grade_name: ing.grade_name, icon: ing.icon },
        qty: ing.qty_min,
        price: ing.price,
      })),
    })
  }

  const cost = (recipe) =>
    recipe.ingredients.every((ing) => ing.price) ? recipe.ingredients.reduce((sum, ing) => sum + ing.qty * ing.price.unit, 0) : Infinity

  const list = [...boxes.values()].sort((a, b) => TIERS.indexOf(a.tier) - TIERS.indexOf(b.tier))
  for (const box of list) box.recipes.sort((a, b) => cost(a) - cost(b))

  return { mock: true, skill, boxes: list }
}
