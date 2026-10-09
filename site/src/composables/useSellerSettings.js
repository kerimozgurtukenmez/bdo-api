import { computed, reactive, watch } from 'vue'

// What the seller keeps from a Central Market sale:
//   price × 0.65 × (1 + 0.30 with Value Pack + family fame bonus)
// e.g. 84.5% with a Value Pack, 85.475% with a Value Pack and 7,000+ fame.
// These belong to the player, not to a plan, so they are remembered in the
// browser instead of the URL.

export const FAME_TIERS = [
  { bonus: 0, label: 'Under 1,000' },
  { bonus: 0.005, label: '1,000+' },
  { bonus: 0.01, label: '4,000+' },
  { bonus: 0.015, label: '7,000+' },
]

const STORAGE_KEY = 'seller'

function load() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null')
    if (saved && typeof saved.valuePack === 'boolean' && FAME_TIERS.some((t) => t.bonus === saved.fame)) {
      return saved
    }
  } catch {
    // Storage blocked or corrupt: use the defaults
  }
  return { valuePack: true, fame: 0 }
}

const settings = reactive(load())

watch(settings, (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
  } catch {
    // Not remembered, still used for this visit
  }
})

const rate = computed(() => 0.65 * (1 + (settings.valuePack ? 0.3 : 0) + settings.fame))

export function useSellerSettings() {
  return {
    seller: settings,
    rate,
    /** Silver kept after the market tax, or null when the value is unknown */
    afterTax: (value) => (value == null ? null : Math.floor(value * rate.value)),
  }
}
