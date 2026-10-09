import { computed, reactive, watch } from 'vue'

// Player settings, remembered in the browser (they belong to the player, not
// to a plan, so they are not in the URL):
//   valuePack, fame — what is kept from a Central Market sale:
//                     price × 0.65 × (1 + 0.30 with a Value Pack + fame bonus)
//   mastery         — processing mastery: items per Mass Process (processing time);
//                     cooking / alchemy mastery: more products per craft and
//                     a higher Imperial delivery payout

export const FAME_TIERS = [
  { bonus: 0, label: 'Under 1,000' },
  { bonus: 0.005, label: '1,000+' },
  { bonus: 0.01, label: '4,000+' },
  { bonus: 0.015, label: '7,000+' },
]

export const MASTERY_SKILLS = ['cooking', 'alchemy', 'processing']
export const MAX_MASTERY = 3000

const STORAGE_KEY = 'settings'
const OLD_STORAGE_KEY = 'seller'  // before mastery existed

const defaults = () => ({ valuePack: true, fame: 0, mastery: { cooking: 0, alchemy: 0, processing: 0 } })

export function clampMastery(value) {
  const n = Math.round(Number(value))
  return Number.isFinite(n) ? Math.min(MAX_MASTERY, Math.max(0, n)) : 0
}

// Anything stored is checked: an old or edited value never breaks the page
function sanitize(saved) {
  const settings = defaults()
  if (!saved || typeof saved !== 'object') return settings

  if (typeof saved.valuePack === 'boolean') settings.valuePack = saved.valuePack
  if (FAME_TIERS.some((tier) => tier.bonus === saved.fame)) settings.fame = saved.fame
  for (const skill of MASTERY_SKILLS) {
    if (saved.mastery?.[skill] != null) settings.mastery[skill] = clampMastery(saved.mastery[skill])
  }
  return settings
}

function load() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY) ?? localStorage.getItem(OLD_STORAGE_KEY)
    return sanitize(JSON.parse(saved ?? 'null'))
  } catch {
    return defaults()  // storage blocked or corrupt
  }
}

const settings = reactive(load())

watch(settings, (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
    localStorage.removeItem(OLD_STORAGE_KEY)
  } catch {
    // Not remembered, still used for this visit
  }
})

const rate = computed(() => 0.65 * (1 + (settings.valuePack ? 0.3 : 0) + settings.fame))

export function useSettings() {
  return {
    settings,
    /** Share of a market sale the seller keeps */
    rate,
    /** Silver kept after the market tax, or null when the value is unknown */
    afterTax: (value) => (value == null ? null : Math.floor(value * rate.value)),
  }
}
