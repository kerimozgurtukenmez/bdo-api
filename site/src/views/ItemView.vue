<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { usePageMeta } from '../composables/usePageMeta.js'
import { ago, number, plural, silver } from '../format.js'
import { calculatorRoute, itemRoute, slugify } from '../links.js'
import ErrorState from '../components/ErrorState.vue'
import IngredientSlots from '../components/IngredientSlots.vue'
import ItemIcon from '../components/ItemIcon.vue'
import ItemLink from '../components/ItemLink.vue'
import PaginationNav from '../components/PaginationNav.vue'
import PriceChart from '../components/PriceChart.vue'
import RareProducts from '../components/RareProducts.vue'
import RecipeRow from '../components/RecipeRow.vue'
import SampleBadge from '../components/SampleBadge.vue'
import SegmentedControl from '../components/SegmentedControl.vue'
import SilverAmount from '../components/SilverAmount.vue'
import SkillChip from '../components/SkillChip.vue'

const route = useRoute()
const router = useRouter()
const itemId = computed(() => Number(route.params.id))

// Every request of the page is cancelled together when the item changes
let controller = new AbortController()
onBeforeUnmount(() => controller.abort())

// ── Item ───────────────────────────────────────────────────────────────
const item = ref(null)
const error = ref(null)

async function loadItem() {
  controller.abort()
  controller = new AbortController()
  item.value = null
  error.value = null
  try {
    item.value = await api.item(itemId.value, controller.signal)
    // Keep one URL per item: /item/9213/beer
    if (route.params.slug !== slugify(item.value.name)) router.replace(itemRoute(item.value))
  } catch (e) {
    if (e.name !== 'AbortError') error.value = e
  }
}

usePageMeta(
  () => item.value?.name,
  () => item.value && `${item.value.name}: Central Market price, price history, how to make it and what it is used in.`,
)

const craftable = computed(() => item.value?.made_by.length > 0)
const hasSubstitutes = computed(() => makeRecipes.value.some((recipe) => recipe.ingredients.some((slot) => slot.alternatives.length)))
const facts = computed(() => {
  const i = item.value
  if (!i) return []
  return [
    ['Category', i.category],
    ['Weight', i.weight],
    ['Warehouse', i.warehouse_capacity],
    ['Grade', i.grade_name],
    ['Bound on pickup', i.bound_on_obtain == null ? null : i.bound_on_obtain ? 'Yes' : 'No'],
  ].filter(([, value]) => value)
})

// ── Price history ──────────────────────────────────────────────────────
const RANGES = [
  { value: '7', label: '7 days' },
  { value: '30', label: '30 days' },
  { value: '90', label: '90 days' },
]
const days = ref('30')
const historyStart = computed(() =>
  history.value?.points.length
    ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(history.value.points[0].t * 1000))
    : null,
)
const history = ref(null)
const historyError = ref(null)

async function loadHistory() {
  history.value = null
  historyError.value = null
  try {
    history.value = await api.priceHistory(itemId.value, Number(days.value), controller.signal)
  } catch (e) {
    if (e.name !== 'AbortError') historyError.value = e
  }
}

// ── How to make ────────────────────────────────────────────────────────
const SHOWN_RECIPES = 5
const makeGroups = ref(null)
const showAllRecipes = ref(false)

async function loadRecipes() {
  makeGroups.value = null
  showAllRecipes.value = false
  if (!craftable.value) return
  try {
    makeGroups.value = (await api.recipesFor(itemId.value, controller.signal)).groups
  } catch {
    makeGroups.value = []
  }
}

const makeRecipes = computed(() =>
  (makeGroups.value ?? []).flatMap((group) => group.recipes.map((recipe) => ({ ...recipe, source: group.source, category: group.category }))),
)
const shownRecipes = computed(() => (showAllRecipes.value ? makeRecipes.value : makeRecipes.value.slice(0, SHOWN_RECIPES)))

// ── Used in ────────────────────────────────────────────────────────────
const usedPage = ref(1)
const usedIn = ref(null)

async function loadUsedIn() {
  if (!item.value?.used_in_recipes) {
    usedIn.value = { data: [], total: 0, total_pages: 0 }
    return
  }
  try {
    usedIn.value = await api.recipes({ ingredient_id: itemId.value, with_ingredients: 1, limit: 10, page: usedPage.value }, controller.signal)
  } catch {
    usedIn.value = { data: [], total: 0, total_pages: 0 }
  }
}

// ── Loading order ──────────────────────────────────────────────────────
watch(
  itemId,
  async () => {
    usedPage.value = 1
    usedIn.value = null
    await loadItem()
    if (item.value) {
      loadHistory()
      loadRecipes()
      loadUsedIn()
    }
  },
  { immediate: true },
)
watch(days, () => item.value && loadHistory())
watch(usedPage, () => item.value && loadUsedIn())
</script>

<template>
  <ErrorState v-if="error" :error="error" @retry="loadItem">
    <RouterLink v-if="error.status === 404" class="btn btn-secondary" to="/">Go to the calculator</RouterLink>
  </ErrorState>

  <div v-else-if="!item" class="loading" aria-busy="true">
    <div class="skeleton" style="height: 88px"></div>
    <div class="skeleton" style="height: 320px"></div>
  </div>

  <article v-else class="item-page">
    <!-- Header -->
    <header class="item-head">
      <ItemIcon :item="item" :size="64" />
      <div class="item-title">
        <h1 :class="`grade-${item.grade}`">{{ item.name }}</h1>
        <div class="item-meta">
          <SkillChip v-for="source in [...new Set(item.made_by.map((r) => r.source))]" :key="source" :source="source" />
          <span v-if="item.category" class="muted">{{ item.category }}</span>
        </div>
      </div>
      <div class="head-actions">
        <RouterLink v-if="craftable" :to="calculatorRoute(item.id)" class="btn btn-primary">Calculate</RouterLink>
        <a v-if="item.link" :href="item.link" class="btn btn-secondary" target="_blank" rel="noopener">bdocodex ↗</a>
      </div>
    </header>

    <div class="columns">
      <!-- Market -->
      <section class="card market">
        <div class="card-header">
          <h2>Central Market</h2>
          <span v-if="item.price_updated_at" class="faint small">Updated {{ ago(item.price_updated_at) }}</span>
        </div>
        <div class="card-body">
          <template v-if="item.base_price > 0">
            <div class="price-now">
              <span class="price-big num">{{ silver(item.base_price) }}</span>
              <span class="muted small">silver</span>
            </div>
            <dl class="stats">
              <div><dt>Last sold</dt><dd class="num">{{ silver(item.last_sold_price) }}</dd></div>
              <div><dt>Price range</dt><dd class="num">{{ silver(item.price_min) }} – {{ silver(item.price_max) }}</dd></div>
              <div><dt>In stock</dt><dd class="num">{{ number(item.current_stock) }}</dd></div>
              <div><dt>Total trades</dt><dd class="num">{{ number(item.total_trades) }}</dd></div>
            </dl>
          </template>
          <p v-else class="muted">Not sold on the Central Market.</p>
          <p v-if="item.vendor_sold" class="vendor">
            Sold by NPC vendors for <SilverAmount :value="item.buy_price" /> silver.
          </p>

          <!-- History -->
          <div v-if="item.base_price > 0" class="history">
            <div class="history-head">
              <h3>Price history <SampleBadge v-if="history?.mock" /></h3>
              <SegmentedControl v-model="days" :options="RANGES" label="Price history range" />
            </div>
            <p v-if="historyError" class="negative small">{{ historyError.message }}</p>
            <div v-else-if="!history" class="skeleton" style="height: 220px"></div>
            <!-- History is recorded once a day from the first price update on; a line needs two days -->
            <p v-else-if="history.points.length < 2" class="muted small">
              Price history is recorded once a day{{ historyStart ? ` since ${historyStart}` : '' }}. The chart appears from the second day.
            </p>
            <PriceChart v-else :points="history.points" :label="`${item.name} market price`" />
          </div>
        </div>
      </section>

      <!-- Details -->
      <section class="card details">
        <div class="card-header"><h2>About this item</h2></div>
        <div class="card-body">
          <p v-if="item.description" class="description">{{ item.description }}</p>
          <dl v-if="facts.length" class="facts">
            <div v-for="[label, value] in facts" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div>
          </dl>
          <p v-if="!item.description && !facts.length" class="muted small">No details known for this item.</p>
        </div>
      </section>
    </div>

    <!-- How to make -->
    <section v-if="craftable" class="card">
      <div class="card-header">
        <h2>How to make</h2>
        <span class="faint small">{{ plural(item.made_by.length, 'recipe') }}</span>
      </div>
      <div class="card-body">
        <div v-if="!makeGroups" class="skeleton" style="height: 120px"></div>
        <ul v-else class="make-list">
          <li v-for="recipe in shownRecipes" :key="recipe.key" class="make-recipe">
            <div class="make-info">
              <div class="make-head">
                <SkillChip :source="recipe.source" :category="recipe.category" />
                <span class="faint small">{{ recipe.skill_level }}</span>
                <span class="faint small">{{ recipe.output_min === recipe.output_max ? recipe.output_min : `${recipe.output_min}–${recipe.output_max}` }} per craft</span>
                <span v-if="recipe.is_default" class="badge badge-accent">Default</span>
              </div>
              <RareProducts :products="recipe.rare ?? []" />
            </div>
            <IngredientSlots :slots="recipe.ingredients" class="make-ingredients" />
            <RouterLink :to="calculatorRoute(item.id, recipe.key)" class="btn btn-secondary btn-sm">Calculate</RouterLink>
          </li>
        </ul>
        <p v-if="makeGroups && hasSubstitutes" class="faint small">Ingredients in a dashed box can replace each other: use any one of them.</p>
        <button v-if="makeRecipes.length > SHOWN_RECIPES" class="btn btn-ghost btn-sm show-all" type="button" @click="showAllRecipes = !showAllRecipes">
          {{ showAllRecipes ? 'Show fewer' : `Show all ${number(makeRecipes.length)} recipes` }}
        </button>
      </div>
    </section>

    <!-- Rare product of -->
    <section v-if="item.rare_from?.length" class="card">
      <div class="card-header">
        <h2>Rare product of</h2>
        <span class="faint small">{{ plural(item.rare_from.length, 'recipe') }}</span>
      </div>
      <div class="card-body">
        <p class="muted small">Crafting these gives a slight chance of this item as well. The game does not publish the chance.</p>
        <ul class="make-list">
          <li v-for="recipe in item.rare_from" :key="recipe.key" class="make-recipe">
            <ItemIcon :item="recipe.product" :size="32" />
            <div class="make-info rare-of">
              <ItemLink :item="recipe.product" />
              <div class="make-head">
                <SkillChip :source="recipe.source" :category="recipe.category" />
                <span class="faint small">{{ recipe.qty_min === recipe.qty_max ? recipe.qty_min : `${recipe.qty_min}–${recipe.qty_max}` }} per rare proc</span>
                <span v-if="recipe.requires" class="faint small">from {{ recipe.requires }}</span>
              </div>
            </div>
            <RouterLink :to="calculatorRoute(recipe.product.id, recipe.key)" class="btn btn-secondary btn-sm">Calculate</RouterLink>
          </li>
        </ul>
      </div>
    </section>

    <!-- Used in -->
    <section v-if="item.used_in_recipes" class="used-in">
      <div class="section-head">
        <h2>Used in</h2>
        <span class="faint small">{{ plural(item.used_in_recipes, 'recipe') }}</span>
      </div>
      <div v-if="!usedIn" class="skeleton" style="height: 200px"></div>
      <template v-else>
        <ul class="used-list">
          <RecipeRow v-for="recipe in usedIn.data" :key="recipe.key" :recipe="recipe" />
        </ul>
        <PaginationNav :page="usedPage" :total-pages="usedIn.total_pages" @go="usedPage = $event" />
      </template>
    </section>
  </article>
</template>

<style scoped>
.item-page > * + *,
.loading > * + * {
  margin-top: var(--space-4);
}

.item-head {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.item-title {
  flex: 1;
  min-width: 0;
}

.item-title h1 {
  font-size: var(--text-2xl);
}

.item-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1) var(--space-4);
  margin-top: var(--space-1);
  font-size: var(--text-sm);
}

.head-actions {
  display: flex;
  gap: var(--space-2);
}

.columns {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: var(--space-4);
  align-items: start;
}

.price-now {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
}

.price-big {
  font-size: var(--text-2xl);
  font-weight: 600;
}

.stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}

dt {
  color: var(--text-muted);
  font-size: var(--text-xs);
}

dd {
  margin: 2px 0 0;
  font-size: var(--text-sm);
  font-weight: 500;
}

.vendor {
  margin-top: var(--space-3);
  color: var(--text-muted);
  font-size: var(--text-sm);
}

.history {
  margin-top: var(--space-5);
  padding-top: var(--space-4);
  border-top: 1px solid var(--border);
}

.history-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.history-head h3 {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.description {
  color: var(--text-muted);
  font-size: var(--text-sm);
  white-space: pre-line;
}

.facts {
  display: grid;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}

.facts div {
  display: flex;
  justify-content: space-between;
  gap: var(--space-3);
}

.facts dd {
  margin: 0;
  text-align: right;
}

.make-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.make-recipe {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3) var(--space-4);
  padding: var(--space-3) 0;
  border-top: 1px solid var(--border);
}

.make-recipe:first-child {
  border-top: 0;
}

.make-info {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  min-width: 220px;
}

.make-info.rare-of {
  flex: 1;
}

.make-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2) var(--space-3);
}

.make-ingredients {
  flex: 1;
}

.show-all {
  margin-top: var(--space-2);
}

.section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: var(--space-3);
}

.used-list {
  margin: 0 0 var(--space-4);
  padding: 0;
  list-style: none;
}

.used-list > * + * {
  margin-top: var(--space-2);
}

@media (max-width: 960px) {
  .columns {
    /* minmax(0, …): the chart sizes itself to the column, never the other way round */
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 640px) {
  .item-head {
    flex-wrap: wrap;
  }

  .head-actions {
    width: 100%;
  }

  .stats {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
