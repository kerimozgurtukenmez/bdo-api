// Number formatting and labels shared by the whole site.

const fullFormat = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 })
const compactFormat = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 })
const decimalFormat = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 })

/** 12,345,678 — or a dash when unknown */
export function silver(value) {
  return value == null ? '—' : fullFormat.format(value)
}

/** 12.35M */
export function compact(value) {
  return value == null ? '—' : compactFormat.format(value)
}

/** 2.5, 1,234 */
export function number(value) {
  return value == null ? '—' : decimalFormat.format(value)
}

/** "1 craft", "1,250 crafts" */
export function plural(count, word) {
  return `${number(count)} ${count === 1 ? word : `${word}s`}`
}

export const SKILLS = {
  cooking: 'Cooking',
  alchemy: 'Alchemy',
  processing: 'Processing',
}

export const SKILL_TIERS = ['Beginner', 'Apprentice', 'Skilled', 'Professional', 'Artisan', 'Master', 'Guru']

/** Why an item is bought instead of crafted (craft.php "reason") */
export const BUY_REASONS = {
  no_recipe: 'Raw material',
  vendor: 'NPC vendor',
  forced: 'Buying',
  cheaper: 'Cheaper to buy',
  loop: 'Recipe loop',
  max_depth: 'Chain too deep',
}

export const PRICE_SOURCES = {
  market: 'Market',
  vendor: 'NPC',
}
