import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { useSettings } from './useSettings.js'

// Calculator settings live in the URL, so every plan is a shareable link:
//   ?item=9003&qty=10&mode=cheapest&yield=min
//   &buy=9017,9018              buy these instead of crafting them
//   &recipe=9003:cooking:106    item:source:recipe id, comma separated
//   &sub=7313:7304              default item:substitute, comma separated
//   &have=9059:30               item:units the player already has, comma separated

const MAX_QTY = 1_000_000
export const MAX_STOCK = 999_999_999

function intOrNull(value) {
  const n = Number.parseInt(value, 10)
  return Number.isFinite(n) && n > 0 ? n : null
}

function parsePairs(value, pattern) {
  const result = {}
  for (const part of String(value ?? '').split(',')) {
    const match = part.match(pattern)
    if (match) result[match[1]] = match[2]
  }
  return result
}

function parseQuery(query) {
  return {
    item: intOrNull(query.item),
    qty: Math.min(intOrNull(query.qty) ?? 1, MAX_QTY),
    mode: query.mode === 'cheapest' ? 'cheapest' : 'craft',
    yield: ['min', 'max'].includes(query.yield) ? query.yield : 'avg',
    buy: String(query.buy ?? '').split(',').map(intOrNull).filter(Boolean),
    recipe: parsePairs(query.recipe, /^(\d+):((?:cooking|alchemy|processing):\d+)$/),
    sub: parsePairs(query.sub, /^(\d+):(\d+)$/),
    have: Object.fromEntries(
      Object.entries(parsePairs(query.have, /^(\d+):(\d{1,9})$/))
        .map(([item, units]) => [item, Number(units)])
        .filter(([, units]) => units > 0),
    ),
  }
}

// Only non-default values end up in the URL
function toQuery(s) {
  const query = {}
  if (s.item) query.item = String(s.item)
  if (s.qty !== 1) query.qty = String(s.qty)
  if (s.mode !== 'craft') query.mode = s.mode
  if (s.yield !== 'avg') query.yield = s.yield
  if (s.buy.length) query.buy = s.buy.join(',')
  const recipe = Object.entries(s.recipe).map(([item, key]) => `${item}:${key}`)
  if (recipe.length) query.recipe = recipe.join(',')
  const sub = Object.entries(s.sub).map(([item, alt]) => `${item}:${alt}`)
  if (sub.length) query.sub = sub.join(',')
  const have = Object.entries(s.have).map(([item, units]) => `${item}:${units}`)
  if (have.length) query.have = have.join(',')
  return query
}

export function useCraftPlan() {
  const route = useRoute()
  const router = useRouter()
  const { settings: player } = useSettings()

  const settings = computed(() => parseQuery(route.query))

  const plan = ref(null)
  const loading = ref(false)
  const error = ref(null)

  /** Change settings; a new item is a new history entry, tweaks replace the current one */
  function update(changes) {
    const next = { ...settings.value, ...changes }
    const method = changes.item && changes.item !== settings.value.item ? 'push' : 'replace'
    router[method]({ query: toQuery(next) })
  }

  const hasChoices = computed(() => {
    const s = settings.value
    return s.buy.length > 0 || Object.keys(s.recipe).length > 0 || Object.keys(s.sub).length > 0
  })

  const actions = {
    buy: (itemId) => update({ buy: [...new Set([...settings.value.buy, itemId])] }),
    craft: (itemId) => update({ buy: settings.value.buy.filter((id) => id !== itemId) }),
    recipe: (itemId, key) => update({ recipe: { ...settings.value.recipe, [itemId]: key } }),
    substitute: (defaultId, itemId) => {
      const sub = { ...settings.value.sub }
      if (defaultId === itemId) delete sub[defaultId]
      else sub[defaultId] = String(itemId)
      update({ sub })
    },
    resetChoices: () => update({ buy: [], recipe: {}, sub: {} }),
    /** Units of an item the player has; 0 removes it */
    have: (itemId, units) => {
      const have = { ...settings.value.have }
      if (units > 0) have[itemId] = Math.min(units, MAX_STOCK)
      else delete have[itemId]
      update({ have })
    },
    clearStock: () => update({ have: {} }),
  }

  const hasStock = computed(() => Object.keys(settings.value.have).length > 0)

  // ── Loading the plan ─────────────────────────────────────────────────
  const apiParams = computed(() => {
    const s = settings.value
    if (!s.item) return null
    return {
      item_id: s.item,
      qty: s.qty,
      mode: s.mode,
      yield: s.yield,
      buy: s.buy,
      recipe: s.recipe,
      substitute: s.sub,
      have: s.have,
      // A player setting, not part of the shared link
      mastery: { ...player.mastery },
    }
  })

  let controller = null

  async function load(params) {
    controller?.abort()
    if (!params) {
      plan.value = null
      error.value = null
      return
    }

    const current = new AbortController()
    controller = current
    loading.value = true
    error.value = null
    try {
      plan.value = await api.craft(params, current.signal)
    } catch (e) {
      if (e.name === 'AbortError') return
      error.value = e
      if (e.status === 404) plan.value = null
    } finally {
      // A newer request may have replaced this one; it owns the loading state
      if (controller === current) loading.value = false
    }
  }

  watch(() => JSON.stringify(apiParams.value), () => load(apiParams.value), { immediate: true })
  onBeforeUnmount(() => controller?.abort())

  return { settings, update, plan, loading, error, actions, hasChoices, hasStock, reload: () => load(apiParams.value) }
}
