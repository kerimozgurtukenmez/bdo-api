<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { useSettings } from '../composables/useSettings.js'
import { SKILLS, SKILL_TIERS, duration, number, silver } from '../format.js'
import { calculatorRoute } from '../links.js'
import ErrorState from '../components/ErrorState.vue'
import ItemIcon from '../components/ItemIcon.vue'
import ItemLink from '../components/ItemLink.vue'
import PaginationNav from '../components/PaginationNav.vue'
import SegmentedControl from '../components/SegmentedControl.vue'
import SilverAmount from '../components/SilverAmount.vue'
import SkillChip from '../components/SkillChip.vue'

// /profits/processing?skill=Apprentice&q=steel&sort=margin&page=2
const route = useRoute()
const router = useRouter()
const { settings, rate } = useSettings()

const PER_PAGE = 50
const SORTS = [
  { value: 'profit', label: 'Profit per item' },
  { value: 'margin', label: 'Margin' },
  { value: 'per_hour', label: 'Per hour' },
  { value: 'demand', label: 'Market demand' },
]

const source = computed(() => route.params.source || '')
const filters = computed(() => ({
  search: String(route.query.q ?? ''),
  skill: SKILL_TIERS.includes(route.query.skill) ? route.query.skill : '',
  sort: SORTS.some((s) => s.value === route.query.sort) ? route.query.sort : 'profit',
  page: Math.max(1, Number.parseInt(route.query.page, 10) || 1),
}))

function setFilter(changes) {
  const next = { ...filters.value, page: 1, ...changes }
  const query = {}
  if (next.search) query.q = next.search
  if (next.skill) query.skill = next.skill
  if (next.sort !== 'profit') query.sort = next.sort
  if (next.page > 1) query.page = String(next.page)
  router.replace({ query })
}

const sort = computed({ get: () => filters.value.sort, set: (value) => setFilter({ sort: value }) })

// Typing filters after a short pause
const searchInput = ref(filters.value.search)
let searchTimer = null
watch(searchInput, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => setFilter({ search: value.trim() }), 300)
})

// The player's tax share and mastery change every number
const common = computed(() => ({ keep: rate.value, mastery: { ...settings.mastery } }))

// ── Opportunities: pay well and sell fast, across all life skills ──────
const opportunities = ref(null)
const opportunitiesError = ref(null)

async function loadOpportunities() {
  opportunitiesError.value = null
  try {
    opportunities.value = await api.profits({ ...common.value, opportunities: 1, sort: 'demand', limit: 6 })
  } catch (e) {
    opportunitiesError.value = e
  }
}

// ── The list ───────────────────────────────────────────────────────────
const result = ref(null)
const loading = ref(false)
const error = ref(null)
let controller = null

async function load() {
  controller?.abort()
  const current = new AbortController()
  controller = current
  loading.value = true
  error.value = null
  try {
    result.value = await api.profits(
      {
        ...common.value,
        source: source.value,
        search: filters.value.search,
        skill: filters.value.skill,
        sort: filters.value.sort,
        page: filters.value.page,
        limit: PER_PAGE,
      },
      current.signal,
    )
  } catch (e) {
    if (e.name !== 'AbortError') error.value = e
  } finally {
    if (controller === current) loading.value = false
  }
}

watch(() => JSON.stringify([source.value, filters.value, common.value]), load, { immediate: true })
watch(() => JSON.stringify(common.value), loadOpportunities, { immediate: true })
onBeforeUnmount(() => controller?.abort())

const showPerHour = computed(() => result.value?.data.some((row) => row.per_hour != null))
const percent = (value) => (value == null ? '—' : `${number(value * 100)}%`)
const tabs = computed(() => [['', 'All'], ...Object.entries(SKILLS)])
</script>

<template>
  <div class="profits">
    <header class="page-head">
      <h1>What to craft</h1>
      <p class="muted">
        Profit of one item at current market prices (EU), after the market tax (you keep {{ percent(rate) }}) and with your mastery.
        Materials are bought or crafted, whichever costs less.
      </p>
    </header>

    <!-- Opportunities -->
    <section class="card opportunities" aria-labelledby="opportunities-title">
      <div class="card-header">
        <h2 id="opportunities-title">Opportunities</h2>
        <span class="faint small">Pays 10%+ and sells fast: less on the market than two hours of sales</span>
      </div>
      <div class="card-body">
        <ErrorState v-if="opportunitiesError" :error="opportunitiesError" @retry="loadOpportunities" />
        <div v-else-if="!opportunities" class="skeleton" style="height: 96px"></div>
        <p v-else-if="opportunities.demand_hours < 2" class="muted small">
          Collecting market data: opportunities need a few hours of sales history from the data worker
          ({{ opportunities.demand_hours }} {{ opportunities.demand_hours === 1 ? 'hour' : 'hours' }} so far).
        </p>
        <p v-else-if="!opportunities.data.length" class="muted small">No opportunities right now. They change every hour.</p>
        <ul v-else class="opportunity-list">
          <li v-for="row in opportunities.data" :key="row.item.id" class="opportunity">
            <ItemIcon :item="row.item" :size="40" />
            <div class="opportunity-text">
              <ItemLink :item="row.item" />
              <span class="small"><SilverAmount :value="row.profit" /> profit each · {{ percent(row.margin) }}</span>
              <span class="faint small num">{{ number(row.sold_per_hour) }} sold/h · {{ number(row.stock) }} listed · up to {{ silver(row.demand) }}/h</span>
            </div>
            <RouterLink :to="calculatorRoute(row.item.id, row.recipe.key)" class="btn btn-secondary btn-sm">Calculate</RouterLink>
          </li>
        </ul>
      </div>
    </section>

    <!-- Filters -->
    <nav class="tabs" aria-label="Life skill">
      <RouterLink
        v-for="[key, label] in tabs"
        :key="key"
        :to="{ name: 'profits', params: { source: key }, query: route.query }"
        class="tab"
        :class="{ active: source === key }"
        :aria-current="source === key ? 'page' : undefined"
      >
        {{ label }}
      </RouterLink>
    </nav>

    <div class="filters">
      <input v-model="searchInput" class="input filter-search" type="search" placeholder="Search items…" aria-label="Search items" />
      <select class="select" :value="filters.skill" aria-label="Skill level" @change="setFilter({ skill: $event.target.value })">
        <option value="">All levels</option>
        <option v-for="tier in SKILL_TIERS" :key="tier" :value="tier">{{ tier }}</option>
      </select>
      <SegmentedControl v-model="sort" :options="SORTS" label="Sort by" />
      <span v-if="result" class="faint small total">{{ number(result.total) }} items</span>
    </div>
    <p v-if="sort === 'per_hour'" class="faint small help">Per hour is known for processing chains only (with your processing mastery); the rest are listed last.</p>

    <ErrorState v-if="error" :error="error" @retry="load" />
    <div v-else-if="!result" class="skeleton" style="height: 400px"></div>
    <div v-else-if="!result.data.length" class="card empty">
      <p>No items match these filters.</p>
      <button class="btn btn-secondary" type="button" @click="setFilter({ search: '', skill: '' }); searchInput = ''">Clear filters</button>
    </div>

    <div v-else class="table-wrap card" :class="{ stale: loading }" aria-live="polite">
      <table class="profit-table">
        <thead>
          <tr>
            <th scope="col">Item</th>
            <th scope="col" class="right">Cost</th>
            <th scope="col" class="right">Sells for</th>
            <th scope="col" class="right">Profit</th>
            <th scope="col" class="right">Margin</th>
            <th scope="col" class="right" title="Sold per hour lately, and how many are listed">Sold/h · listed</th>
            <th v-if="showPerHour" scope="col" class="right">Per hour</th>
            <th scope="col"><span class="visually-hidden">Calculate</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in result.data" :key="row.item.id">
            <td>
              <div class="item-cell">
                <ItemIcon :item="row.item" :size="32" />
                <div>
                  <ItemLink :item="row.item" />
                  <span v-if="row.opportunity" class="badge badge-accent">Opportunity</span>
                  <div class="item-meta">
                    <SkillChip :source="row.recipe.source" :category="row.recipe.category" />
                    <span class="faint">{{ row.recipe.skill_level }}</span>
                  </div>
                </div>
              </div>
            </td>
            <td class="right"><SilverAmount :value="row.cost" /></td>
            <td class="right"><SilverAmount :value="row.sale" /></td>
            <td class="right" :class="row.profit >= 0 ? 'positive' : 'negative'"><SilverAmount :value="row.profit" /></td>
            <td class="right num">{{ percent(row.margin) }}</td>
            <td class="right num">{{ row.sold_per_hour == null ? '—' : number(row.sold_per_hour) }} · {{ number(row.stock) }}</td>
            <td v-if="showPerHour" class="right" :title="row.seconds ? `${duration(row.seconds)} of processing per item` : null">
              <SilverAmount :value="row.per_hour" />
            </td>
            <td class="right">
              <RouterLink :to="calculatorRoute(row.item.id, row.recipe.key)" class="btn btn-secondary btn-sm">Calculate</RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationNav v-if="result" :page="filters.page" :total-pages="result.total_pages" @go="setFilter({ page: $event })" />
  </div>
</template>

<style scoped>
.profits > * + * {
  margin-top: var(--space-4);
}

.page-head h1 {
  font-size: var(--text-2xl);
}

.page-head p {
  max-width: 760px;
  margin-top: var(--space-1);
}

.opportunity-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.opportunity {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
}

.opportunity-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.tabs {
  display: flex;
  gap: var(--space-1);
  border-bottom: 1px solid var(--border);
}

.tab {
  padding: var(--space-2) var(--space-3);
  border-bottom: 2px solid transparent;
  color: var(--text-muted);
  font-size: var(--text-sm);
  font-weight: 500;
  text-decoration: none;
}

.tab:hover {
  color: var(--text);
}

.tab.active {
  border-bottom-color: var(--accent);
  color: var(--text);
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2) var(--space-3);
}

.filter-search {
  flex: 1;
  min-width: 200px;
  max-width: 320px;
}

.total {
  margin-left: auto;
}

.help {
  margin-top: var(--space-2);
}

/* relative: the visually hidden header text must not stick out of the scroll box */
.table-wrap {
  position: relative;
  overflow-x: auto;
}

.profit-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-sm);
}

.profit-table th {
  padding: var(--space-2) var(--space-3);
  color: var(--text-muted);
  font-size: var(--text-xs);
  font-weight: 500;
  text-align: left;
  white-space: nowrap;
}

.profit-table td {
  padding: var(--space-2) var(--space-3);
  border-top: 1px solid var(--border);
  white-space: nowrap;
}

.profit-table td:first-child {
  min-width: 220px;
  white-space: normal;
}

.right {
  text-align: right !important;
}

.item-cell {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.item-cell .badge {
  margin-left: var(--space-2);
}

.item-meta {
  display: flex;
  gap: var(--space-2);
  font-size: var(--text-xs);
}

.positive {
  color: var(--positive);
}

.negative {
  color: var(--negative);
}

.empty {
  padding: var(--space-6);
  text-align: center;
}

.stale {
  opacity: 0.6;
  transition: opacity 140ms var(--ease);
}
</style>
