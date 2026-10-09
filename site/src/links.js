// Routes to pages, built in one place so links stay consistent

/** "Master's Cooking Box" → "masters-cooking-box" */
export function slugify(name) {
  return String(name ?? '')
    .toLowerCase()
    .replace(/['’]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

/** Item page: /item/9213/beer */
export function itemRoute(item) {
  return { name: 'item', params: { id: String(item.id ?? item.item_id), slug: slugify(item.name) } }
}

/** Calculator for an item, optionally with a given recipe ("source:id") */
export function calculatorRoute(itemId, recipeKey = null) {
  return { name: 'calculator', query: { item: String(itemId), ...(recipeKey && { recipe: `${itemId}:${recipeKey}` }) } }
}

/** Recipe list of a life skill, optionally one processing category */
export function recipesRoute(source, category = null) {
  return { name: 'recipes', params: { source, category: category ? slugify(category) : undefined } }
}
