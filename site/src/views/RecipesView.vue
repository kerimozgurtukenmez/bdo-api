<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { SKILLS, SKILL_TIERS, number } from '../format.js'
import { recipesRoute, slugify } from '../links.js'
import ErrorState from '../components/ErrorState.vue'
import PaginationNav from '../components/PaginationNav.vue'
import RecipeRow from '../components/RecipeRow.vue'

const route = useRoute()
const router = useRouter()

const PER_PAGE = 30

// /recipes/processing/heating?skill=Beginner&q=iron&page=2
const source = computed(() => route.params.source || 'cooking')
const categorySlug = computed(() => route.params.category || '')
const filters = computed(() => ({
  search: route.query.q ?? '',
  skill: SKILL_TIERS.includes(route.query.skill) ? route.query.skill : '',
  page: Math.max(1, Number.parseInt(route.query.page, 10) || 1),
}))

function setFilter(changes) {
  const next = { ...filters.value, page: 1, ...changes }
  router.replace({
    query: {
      ...(next.search && { q: next.search }),
      ...(next.skill && { skill: next.skill }),
      ...(next.page > 1 && { page: String(next.page) }),
    },
  })
}

// Search box: update the URL after a short pause
const searchInput = ref(filters.value.search)
let searchTimer = null
watch(() => filters.value.search, (value) => (searchInput.value = value))
watch(searchInput, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    if (value.trim() !== filters.value.search) setFilter({ search: value.trim() })
  }, 300)
})

// ── Categories: processing has sub-tabs (Heating, Grinding, ...) ───────
const categories = ref({})
api.categories().then((data) => (categories.value = data)).catch(() => {})

const subTabs = computed(() =>
  source.value === 'processing' ? (categories.value.processing ?? []).map((c) => ({ ...c, slug: slugify(c.category) })) : [],
)
// The real category name for the slug in the URL
const category = computed(() => subTabs.value.find((tab) => tab.slug === categorySlug.value)?.category ?? '')
const sourceTotal = computed(() => (categories.value[source.value] ?? []).reduce((sum, c) => sum + c.products, 0))

// ── Recipes ────────────────────────────────────────────────────────────
const result = ref(null)
const loading = ref(false)
const error = ref(null)
let controller = null

async function load() {
  // A category page needs the category list first; an unknown one goes to the life skill
  if (categorySlug.value && !category.value) {
    if (categories.value.processing) router.replace(recipesRoute(source.value))
    return
  }

  controller?.abort()
  const current = new AbortController()
  controller = current
  loading.value = true
  error.value = null
  try {
    result.value = await api.recipes(
      {
        source: source.value,
        category: category.value,
        search: filters.value.search,
        skill: filters.value.skill,
        page: filters.value.page,
        limit: PER_PAGE,
        with_ingredients: 1,
        // One row per product; its other recipes are on the item page
        per_product: 1,
      },
      current.signal,
    )
  } catch (e) {
    if (e.name !== 'AbortError') error.value = e
  } finally {
    if (controller === current) loading.value = false
  }
}

// The category list only matters on a category page (to resolve or reject its slug)
watch(
  () => [source.value, category.value, JSON.stringify(filters.value), categorySlug.value && Boolean(categories.value.processing)],
  load,
  { immediate: true },
)
onBeforeUnmount(() => {
  controller?.abort()
  clearTimeout(searchTimer)
})
</script>

<template>
  <div class="recipes">
    <header class="page-head">
      <h1>Recipes</h1>
      <p class="muted">Every cooking, alchemy and processing recipe. Open any of them in the calculator.</p>
    </header>

    <nav class="tabs" aria-label="Life skill">
      <RouterLink
        v-for="(label, key) in SKILLS"
        :key="key"
        :to="recipesRoute(key)"
        class="tab"
        :class="{ active: source === key }"
        :aria-current="source === key ? 'page' : undefined"
        :style="{ '--skill': `var(--${key})` }"
      >
        <span class="tab-dot" aria-hidden="true"></span>{{ label }}
      </RouterLink>
    </nav>

    <nav v-if="subTabs.length" class="sub-tabs" aria-label="Processing category">
      <RouterLink :to="recipesRoute('processing')" class="sub-tab" :class="{ active: !categorySlug }">
        All <span class="count num">{{ number(sourceTotal) }}</span>
      </RouterLink>
      <RouterLink
        v-for="tab in subTabs"
        :key="tab.slug"
        :to="recipesRoute('processing', tab.category)"
        class="sub-tab"
        :class="{ active: tab.slug === categorySlug }"
      >
        {{ tab.category }} <span class="count num">{{ number(tab.products) }}</span>
      </RouterLink>
    </nav>

    <div class="filters">
      <input v-model="searchInput" class="input filter-search" type="search" placeholder="Search recipes…" aria-label="Search recipes" />
      <select class="select" :value="filters.skill" aria-label="Skill level" @change="setFilter({ skill: $event.target.value })">
        <option value="">All levels</option>
        <option v-for="tier in SKILL_TIERS" :key="tier" :value="tier">{{ tier }}</option>
      </select>
      <span v-if="result" class="faint small total">{{ number(result.total) }} products</span>
    </div>

    <ErrorState v-if="error" :error="error" @retry="load" />

    <div v-else-if="!result" class="list">
      <div v-for="n in 8" :key="n" class="skeleton" style="height: 64px"></div>
    </div>

    <div v-else-if="!result.data.length" class="card empty">
      <p>No recipes match these filters.</p>
      <button class="btn btn-secondary" type="button" @click="setFilter({ search: '', skill: '' })">Clear filters</button>
    </div>

    <ul v-else class="list" :class="{ stale: loading }">
      <RecipeRow v-for="recipe in result.data" :key="recipe.key" :recipe="recipe" />
    </ul>

    <PaginationNav v-if="result" :page="filters.page" :total-pages="result.total_pages" @go="setFilter({ page: $event })" />
  </div>
</template>

<style scoped>
.recipes > * + * {
  margin-top: var(--space-4);
}

.page-head p {
  margin-top: var(--space-1);
}

.tabs {
  display: flex;
  gap: var(--space-1);
  border-bottom: 1px solid var(--border);
}

.tab {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: -1px;
  padding: var(--space-2) var(--space-4);
  border-bottom: 2px solid transparent;
  color: var(--text-muted);
  font-size: var(--text-sm);
  font-weight: 500;
}

.tab:hover {
  color: var(--text);
}

.tab.active {
  border-bottom-color: var(--skill);
  color: var(--text);
}

.tab-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--skill);
}

.sub-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.sub-tab {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 30px;
  padding: 0 var(--space-3);
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-muted);
  font-size: var(--text-sm);
  font-weight: 500;
  transition: border-color 140ms var(--ease), color 140ms var(--ease);
}

.sub-tab:hover {
  border-color: var(--border-strong);
  color: var(--text);
}

.sub-tab.active {
  border-color: var(--processing);
  background: color-mix(in srgb, var(--processing) 12%, transparent);
  color: var(--text);
}

.count {
  color: var(--text-faint);
  font-size: var(--text-xs);
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}

.filter-search {
  flex: 1;
  min-width: 200px;
  max-width: 360px;
}

.total {
  margin-left: auto;
}

.list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.list > * + * {
  margin-top: var(--space-2);
}

.stale {
  opacity: 0.6;
}

.empty {
  padding: var(--space-6);
  text-align: center;
}

.empty .btn {
  margin-top: var(--space-3);
}
</style>
