// Every request to the BDO Craft API goes through here.
//
// Endpoints the API does not have yet are served by src/mocks/ while they are
// listed in VITE_API_MOCKS (comma separated, e.g. "prices,mastery,imperial").
// The mock files define the response shape the API has to implement.

const BASE = import.meta.env.VITE_API_BASE
const MOCKED = new Set(
  String(import.meta.env.VITE_API_MOCKS ?? '')
    .split(',')
    .map((name) => name.trim())
    .filter(Boolean),
)

export class ApiError extends Error {
  constructor(message, status) {
    super(message)
    this.status = status
  }
}

// Plain values become ?key=value, arrays ?key=1,2,3 and objects ?key[a]=b
function buildUrl(path, params) {
  const url = new URL(`${BASE}/${path}`, window.location.origin)

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue

    if (Array.isArray(value)) {
      if (value.length) url.searchParams.set(key, value.join(','))
    } else if (typeof value === 'object') {
      for (const [sub, subValue] of Object.entries(value)) url.searchParams.set(`${key}[${sub}]`, subValue)
    } else {
      url.searchParams.set(key, value)
    }
  }

  return url
}

async function get(path, params = {}, signal) {
  const endpoint = path.replace(/\.php$/, '')
  if (MOCKED.has(endpoint)) {
    const { mock } = await import('./mocks/index.js')
    // Mocks may build on endpoints that already exist
    return mock(endpoint, params, (realPath, realParams) => request(realPath, realParams, signal))
  }
  return request(path, params, signal)
}

async function request(path, params, signal) {
  let response
  try {
    response = await fetch(buildUrl(path, params), { signal })
  } catch (error) {
    if (error.name === 'AbortError') throw error
    throw new ApiError('Could not reach the server. Check your connection and try again.', 0)
  }

  const data = await response.json().catch(() => null)
  if (!response.ok) {
    throw new ApiError(data?.error ?? `Request failed (${response.status})`, response.status)
  }
  return data
}

export const api = {
  /** Items that some recipe makes, best name matches first */
  searchCraftable: (search, signal) => get('items.php', { search, craftable: 1, limit: 8 }, signal),

  /** One item: details, market data, recipes that make it, how many recipes use it */
  item: (id, signal) => get('items.php', { id }, signal),

  /** Market price history of an item. Contract: src/mocks/prices.js */
  priceHistory: (itemId, days, signal) => get('prices.php', { item_id: itemId, days }, signal),

  /** Full crafting plan, see api/public/craft.php for the parameters */
  craft: (params, signal) => get('craft.php', params, signal),

  /** Every recipe that makes an item, grouped by life skill and category */
  recipesFor: (itemId, signal) => get('recipes.php', { item_id: itemId, grouped: 1 }, signal),

  /** Paged recipe list with filters */
  recipes: (params, signal) => get('recipes.php', params, signal),

  /** Recipe categories per life skill */
  categories: (signal) => get('recipes.php', { categories: 1 }, signal),

  /** Mastery bonus tables for cooking and alchemy. Contract: src/mocks/mastery.js */
  mastery: (signal) => get('mastery.php', {}, signal),

  /** What to craft: unit cost, profit and demand of every craftable item, see api/public/profits.php */
  profits: (params, signal) => get('profits.php', params, signal),

  /** Imperial delivery boxes of a life skill. Contract: src/mocks/imperial.js */
  imperial: (skill, signal) => get('imperial.php', { skill }, signal),
}
