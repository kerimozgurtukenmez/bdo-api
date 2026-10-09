// Every request to the BDO Craft API goes through here.

const BASE = import.meta.env.VITE_API_BASE

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

  /** Full crafting plan, see api/public/craft.php for the parameters */
  craft: (params, signal) => get('craft.php', params, signal),

  /** Every recipe that makes an item, grouped by life skill and category */
  recipesFor: (itemId, signal) => get('recipes.php', { item_id: itemId, grouped: 1 }, signal),

  /** Paged recipe list with filters */
  recipes: (params, signal) => get('recipes.php', params, signal),

  /** Recipe categories per life skill */
  categories: (signal) => get('recipes.php', { categories: 1 }, signal),
}
