<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { SKILLS, SKILL_TIERS, number, silver } from '../format.js'
import ItemIcon from '../components/ItemIcon.vue'
import SkillChip from '../components/SkillChip.vue'

const route = useRoute()
const router = useRouter()

const PER_PAGE = 30

// Filters live in the URL: /recipes/processing?category=Heating&skill=Beginner&q=iron&page=2
const source = computed(() => route.params.source || 'cooking')
const filters = computed(() => ({
  search: route.query.q ?? '',
  skill: SKILL_TIERS.includes(route.query.skill) ? route.query.skill : '',
  category: route.query.category ?? '',
  page: Math.max(1, Number.parseInt(route.query.page, 10) || 1),
}))

function setFilter(changes) {
  const next = { ...filters.value, page: 1, ...changes }
  router.replace({
    query: {
      ...(next.search && { q: next.search }),
      ...(next.skill && { skill: next.skill }),
      ...(next.category && { category: next.category }),
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

// ── Categories (processing has many) ───────────────────────────────────
const categories = ref({})
api.categories().then((data) => (categories.value = data)).catch(() => {})
const sourceCategories = computed(() => categories.value[source.value] ?? [])

// ── Recipes ────────────────────────────────────────────────────────────
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
    result.value = await api.recipes(
      {
        source: source.value,
        search: filters.value.search,
        skill: filters.value.skill,
        category: filters.value.category,
        page: filters.value.page,
        limit: PER_PAGE,
        with_ingredients: 1,
      },
      current.signal,
    )
  } catch (e) {
    if (e.name !== 'AbortError') error.value = e
  } finally {
    if (controller === current) loading.value = false
  }
}

watch(() => [source.value, JSON.stringify(filters.value)], load, { immediate: true })
onBeforeUnmount(() => {
  controller?.abort()
  clearTimeout(searchTimer)
})

// Page numbers around the current one: 1 … 4 5 [6] 7 8 … 20
const pages = computed(() => {
  const total = result.value?.total_pages ?? 0
  const current = filters.value.page
  const list = []
  for (let p = 1; p <= total; p++) {
    if (p === 1 || p === total || Math.abs(p - current) <= 2) list.push(p)
    else if (list[list.length - 1] !== '…') list.push('…')
  }
  return list
})

function calculateLink(recipe) {
  return { name: 'calculator', query: { item: recipe.item_id, recipe: `${recipe.item_id}:${recipe.key}` } }
}

function ingredientTitle(ing) {
  return `${ing.name} × ${ing.qty_min}${ing.price ? ` — ${silver(ing.price.unit)} each` : ''}`
}
</script>

<template>
  <div class="recipes">
    <header class="page-head">
      <h1>Recipes</h1>
      <p class="muted">Browse every recipe and open any of them in the calculator.</p>
    </header>

    <nav class="tabs" aria-label="Life skill">
      <RouterLink
        v-for="(label, key) in SKILLS"
        :key="key"
        :to="{ name: 'recipes', params: { source: key } }"
        class="tab"
        :class="{ active: source === key }"
        :style="{ '--skill': `var(--${key})` }"
      >
        <span class="tab-dot" aria-hidden="true"></span>{{ label }}
      </RouterLink>
    </nav>

    <div class="filters">
      <input v-model="searchInput" class="input filter-search" type="search" placeholder="Search recipes…" aria-label="Search recipes" />
      <select class="select" :value="filters.skill" aria-label="Skill level" @change="setFilter({ skill: $event.target.value })">
        <option value="">All levels</option>
        <option v-for="tier in SKILL_TIERS" :key="tier" :value="tier">{{ tier }}</option>
      </select>
      <select v-if="sourceCategories.length > 1" class="select" :value="filters.category" aria-label="Category" @change="setFilter({ category: $event.target.value })">
        <option value="">All categories</option>
        <option v-for="c in sourceCategories" :key="c.category" :value="c.category">{{ c.category }} ({{ number(c.recipes) }})</option>
      </select>
      <span v-if="result" class="faint small count">{{ number(result.total) }} recipes</span>
    </div>

    <div v-if="error" class="card state">
      <p>{{ error.message }}</p>
      <button class="btn btn-secondary" type="button" @click="load">Try again</button>
    </div>

    <div v-else-if="!result" class="list">
      <div v-for="n in 8" :key="n" class="skeleton" style="height: 64px"></div>
    </div>

    <div v-else-if="!result.data.length" class="card state">
      <p>No recipes match these filters.</p>
      <button class="btn btn-secondary" type="button" @click="setFilter({ search: '', skill: '', category: '' })">Clear filters</button>
    </div>

    <ul v-else class="list" :class="{ stale: loading }">
      <li v-for="recipe in result.data" :key="recipe.key" class="card recipe">
        <ItemIcon :item="recipe" :size="40" />
        <div class="recipe-text">
          <span class="recipe-name" :class="`grade-${recipe.grade}`">{{ recipe.name }}</span>
          <span class="recipe-meta">
            <SkillChip :source="recipe.source" :category="recipe.category" />
            <span class="faint">{{ recipe.skill_level }}</span>
            <span v-if="recipe.exp" class="faint">{{ number(recipe.exp) }} EXP</span>
          </span>
        </div>
        <div class="ingredients" aria-label="Ingredients">
          <ItemIcon v-for="ing in recipe.ingredients" :key="ing.slot" :item="ing" :size="32" :qty="ing.qty_min" :title="ingredientTitle(ing)" />
        </div>
        <RouterLink v-if="recipe.item_id" :to="calculateLink(recipe)" class="btn btn-secondary btn-sm">Calculate</RouterLink>
      </li>
    </ul>

    <nav v-if="pages.length > 1" class="pagination" aria-label="Pages">
      <button class="btn btn-ghost btn-sm" type="button" :disabled="filters.page === 1" @click="setFilter({ page: filters.page - 1 })">Previous</button>
      <template v-for="(p, i) in pages" :key="i">
        <span v-if="p === '…'" class="faint">…</span>
        <button v-else class="btn btn-sm" :class="p === filters.page ? 'btn-primary' : 'btn-ghost'" type="button" :aria-current="p === filters.page ? 'page' : undefined" @click="setFilter({ page: p })">{{ p }}</button>
      </template>
      <button class="btn btn-ghost btn-sm" type="button" :disabled="filters.page === result.total_pages" @click="setFilter({ page: filters.page + 1 })">Next</button>
    </nav>
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

.count {
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

.recipe {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  padding: var(--space-3) var(--space-4);
  transition: border-color 140ms var(--ease);
}

.recipe:hover {
  border-color: var(--border-strong);
}

.recipe-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.recipe-name {
  font-weight: 500;
}

.recipe-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1) var(--space-3);
  font-size: var(--text-xs);
}

.ingredients {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  justify-content: flex-end;
}

.state {
  padding: var(--space-6);
  text-align: center;
}

.state .btn {
  margin-top: var(--space-3);
}

.pagination {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: center;
  gap: var(--space-1);
}

@media (max-width: 640px) {
  .recipe {
    flex-wrap: wrap;
  }

  .ingredients {
    justify-content: flex-start;
    width: 100%;
  }
}
</style>
