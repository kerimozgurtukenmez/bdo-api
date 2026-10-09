import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'

// Calculator settings live in the URL, so every plan is a shareable link:
//   ?item=9003&qty=10&mode=cheapest&yield=min
//   &buy=9017,9018              buy these instead of crafting them
//   &recipe=9003:cooking:106    item:source:recipe id, comma separated
//   &sub=7313:7304              default item:substitute, comma separated

const MAX_QTY = 1_000_000

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
  return query
}

export function useCraftPlan() {
  const route = useRoute()
  const router = useRouter()

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
  }

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

  return { settings, update, plan, loading, error, actions, hasChoices, reload: () => load(apiParams.value) }
}
