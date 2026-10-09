import { computed, ref } from 'vue'
import { api } from '../api.js'

// Mastery bonus tables (api mastery.php), loaded once and shared by every page

const tables = ref(null)
const error = ref(null)
let loading = null

function load() {
  loading ??= api
    .mastery()
    .then((data) => (tables.value = data))
    .catch((e) => {
      error.value = e
      loading = null  // try again next time
    })
  return loading
}

export function useMastery() {
  load()

  /** Bonuses at a mastery value: the table row at or below it, or null while loading */
  function bonus(skill, mastery) {
    const rows = tables.value?.[skill]
    if (!rows?.length) return null
    let row = rows[0]
    for (const candidate of rows) {
      if (candidate.mastery <= mastery) row = candidate
    }
    return row
  }

  return {
    tables,
    error,
    bonus,
    isSample: computed(() => Boolean(tables.value?.mock)),
    reload: () => {
      error.value = null
      return load()
    },
  }
}
