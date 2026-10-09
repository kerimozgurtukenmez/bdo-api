<script setup>
import { computed, provide, ref, watch } from 'vue'
import { useCraftPlan } from '../composables/useCraftPlan.js'
import { useMastery } from '../composables/useMastery.js'
import { FAME_TIERS, useSettings } from '../composables/useSettings.js'
import { useSettingsPanel } from '../composables/useSettingsPanel.js'
import { itemRoute } from '../links.js'
import ItemLink from '../components/ItemLink.vue'
import { BUY_REASONS, PRICE_SOURCES, SKILLS, ago, compact, number, plural, silver } from '../format.js'
import ItemIcon from '../components/ItemIcon.vue'
import RareProducts from '../components/RareProducts.vue'
import SearchBox from '../components/SearchBox.vue'
import SegmentedControl from '../components/SegmentedControl.vue'
import SilverAmount from '../components/SilverAmount.vue'
import SkillChip from '../components/SkillChip.vue'
import StatTile from '../components/StatTile.vue'
import StockInput from '../components/StockInput.vue'
import TreeNode from '../components/TreeNode.vue'

const { settings, update, plan, loading, error, actions, hasChoices, hasStock, reload } = useCraftPlan()
const { settings: playerSettings, rate, afterTax } = useSettings()
const { openSettings } = useSettingsPanel()

const fameLabel = computed(() => FAME_TIERS.find((tier) => tier.bonus === playerSettings.fame)?.label)
const rootMastery = computed(() => {
  const source = rootRecipe.value?.source
  return source && source in playerSettings.mastery ? { skill: source, value: playerSettings.mastery[source] } : null
})

// Extra rare product chance from the player's cooking / alchemy mastery
const { bonus: masteryBonus } = useMastery()
function rareBonus(source) {
  const mastery = playerSettings.mastery[source]
  return mastery ? masteryBonus(source, mastery)?.rare || null : null
}

provide('planActions', actions)
provide('rareBonus', rareBonus)
provide('boughtByChoice', computed(() => new Set(settings.value.buy)))

const EXAMPLES = [
  { id: 9213, name: 'Beer' },
  { id: 9414, name: 'Meat Stew' },
  { id: 702, name: 'Elixir of Will' },
  { id: 5302, name: 'Pure Powder Reagent' },
  { id: 4077, name: 'Steel' },
]

const MODES = [
  { value: 'craft', label: 'Craft everything' },
  { value: 'cheapest', label: 'Cheapest' },
]
const YIELDS = [
  { value: 'min', label: 'Min' },
  { value: 'avg', label: 'Average' },
  { value: 'max', label: 'Max' },
]

function selectItem(item) {
  // A different item starts from a clean plan
  update({ item: item.id, buy: [], recipe: {}, sub: {}, have: {} })
}

// ── Stock: what the player already has ─────────────────────────────────
// "I have some" shows an amount field on every material and crafting step
const stockMode = ref(hasStock.value)
const stockUsed = computed(() => (plan.value?.cost.stock_value ?? 0) > 0 || plan.value?.steps.some((step) => step.from_stock > 0))

// ── Quantity: typing updates the URL after a short pause ───────────────
const qtyInput = ref(settings.value.qty)
let qtyTimer = null
watch(() => settings.value.qty, (qty) => (qtyInput.value = qty))
watch(qtyInput, (value) => {
  clearTimeout(qtyTimer)
  const qty = Math.round(Number(value))
  if (!Number.isFinite(qty) || qty < 1 || qty === settings.value.qty) return
  qtyTimer = setTimeout(() => update({ qty: Math.min(qty, 1_000_000) }), 350)
})
const stepQty = (delta) => (qtyInput.value = Math.max(1, (Number(qtyInput.value) || 1) + delta))

const mode = computed({ get: () => settings.value.mode, set: (value) => update({ mode: value }) })
const yieldMode = computed({ get: () => settings.value.yield, set: (value) => update({ yield: value }) })

// ── Derived views of the plan ──────────────────────────────────────────
const rootRecipe = computed(() => plan.value?.tree?.recipe ?? null)

// Selling on the Central Market: what is kept after tax, and the profit on it
const saleValue = computed(() => afterTax(plan.value?.market_value.total))
// Stock used counts at market price: owning it does not make crafting more profitable
const profit = computed(() => {
  const cost = plan.value?.cost
  if (!cost || saleValue.value == null || !cost.complete || !cost.stock_value_complete) return null
  return saleValue.value - cost.total - cost.stock_value
})
const profitHint = computed(() => {
  if (profit.value == null) {
    if (saleValue.value == null) return 'Not sold on the market'
    return plan.value.cost.stock_value_complete ? 'Needs all prices' : 'Needs prices for your stock'
  }
  return `${silver(profit.value / plan.value.qty)} per item${stockUsed.value ? ' · your stock at market price' : ''}`
})
const profitTone = computed(() => (profit.value == null ? null : profit.value >= 0 ? 'positive' : 'negative'))
const taxRate = computed(() => `${number(rate.value * 100)}%`)

// Market prices older than a day may be out of date
const pricesAge = computed(() => plan.value?.cost.prices_updated_at ?? null)
const pricesStale = computed(() => pricesAge.value != null && Date.now() / 1000 - pricesAge.value > 86400)

// Crafting steps grouped by life skill, in crafting order within each group
const stepGroups = computed(() => {
  if (!plan.value) return []
  const groups = new Map()
  for (const step of plan.value.steps) {
    const source = step.recipe.source
    // by_source has crafts/exp totals per life skill (and a "steps" count)
    if (!groups.has(source)) groups.set(source, { ...plan.value.by_source[source], source, list: [] })
    groups.get(source).list.push(step)
  }
  return [...groups.values()]
})

const copied = ref(false)
async function copyMaterials() {
  const lines = plan.value.materials.filter((m) => m.qty > 0).map((m) => `${m.item.name} x${m.qty}`)
  await navigator.clipboard.writeText(lines.join('\n'))
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}
</script>

<template>
  <!-- ── Start: no item chosen yet ─────────────────────────────────────── -->
  <section v-if="!settings.item" class="hero">
    <h1>What do you want to craft?</h1>
    <p class="hero-sub">
      Search any cooking, alchemy or processing product. You get every material to buy,
      every crafting step and what it costs, down to the raw materials.
    </p>
    <SearchBox large autofocus class="hero-search" @select="selectItem" />
    <div class="examples">
      <span class="faint small">Try</span>
      <RouterLink v-for="example in EXAMPLES" :key="example.id" :to="{ query: { item: example.id } }" class="example">{{ example.name }}</RouterLink>
    </div>
  </section>

  <!-- ── Plan ─────────────────────────────────────────────────────────── -->
  <div v-else class="calculator">
    <div class="toolbar">
      <SearchBox class="toolbar-search" placeholder="Calculate another item…" @select="selectItem" />
    </div>

    <div v-if="error && !plan" class="card state">
      <p>{{ error.status === 404 ? 'This item does not exist.' : error.message }}</p>
      <div class="state-actions">
        <button v-if="error.status !== 404" class="btn btn-secondary" type="button" @click="reload">Try again</button>
        <RouterLink class="btn btn-ghost" :to="{ query: {} }">Start over</RouterLink>
      </div>
    </div>

    <div v-else-if="!plan" class="loading-plan" aria-busy="true">
      <div class="skeleton" style="height: 120px"></div>
      <div class="tiles"><div v-for="n in 4" :key="n" class="skeleton" style="height: 96px"></div></div>
      <div class="skeleton" style="height: 320px"></div>
    </div>

    <template v-else>
      <!-- Item and options -->
      <section class="card item-card" :class="{ stale: loading }">
        <div class="item-head">
          <ItemIcon :item="plan.item" :size="52" />
          <div class="item-title">
            <h1><RouterLink :to="itemRoute(plan.item)" :class="`grade-${plan.item.grade}`" class="title-link">{{ plan.item.name }}</RouterLink></h1>
            <div class="item-meta">
              <SkillChip v-if="rootRecipe" :source="rootRecipe.source" :category="rootRecipe.category" />
              <span v-if="rootRecipe?.skill_level" class="faint">{{ rootRecipe.skill_level }}</span>
              <span v-if="plan.item.price" class="muted">
                Market <SilverAmount :value="plan.item.price.unit" />
              </span>
            </div>
          </div>
        </div>

        <div class="options">
          <div class="option">
            <label class="label" for="qty">Quantity</label>
            <div class="qty-field">
              <button class="btn btn-secondary btn-icon" type="button" aria-label="Decrease quantity" @click="stepQty(-1)">−</button>
              <input id="qty" v-model.number="qtyInput" class="input qty-input num" type="number" min="1" max="1000000" inputmode="numeric" />
              <button class="btn btn-secondary btn-icon" type="button" aria-label="Increase quantity" @click="stepQty(1)">+</button>
            </div>
          </div>
          <div class="option">
            <span class="label">Intermediate products</span>
            <SegmentedControl v-model="mode" :options="MODES" label="Intermediate products" />
            <p class="help">{{ mode === 'cheapest' ? 'Buys an intermediate when the market sells it for less than crafting it.' : 'Crafts every intermediate product; buys raw materials and NPC goods.' }}</p>
          </div>
          <div class="option">
            <span class="label">Products per craft</span>
            <SegmentedControl v-model="yieldMode" :options="YIELDS" label="Products per craft" />
            <p class="help">Recipes give a range (e.g. 1–4). Average is a fair estimate.</p>
          </div>
          <div class="option">
            <span class="label">Your settings</span>
            <ul class="player">
              <li>{{ playerSettings.valuePack ? 'Value Pack' : 'No Value Pack' }} · fame {{ fameLabel }} · keep {{ taxRate }}</li>
              <li v-if="rootMastery">{{ SKILLS[rootMastery.skill] }} mastery {{ number(rootMastery.value) }}</li>
            </ul>
            <button class="btn btn-ghost btn-sm change" type="button" @click="openSettings">Change</button>
          </div>
        </div>
      </section>

      <!-- Summary -->
      <section class="tiles" aria-live="polite" :class="{ stale: loading }">
        <StatTile
          :label="stockUsed ? 'Still to buy' : 'Total cost'"
          :hint="stockUsed && plan.cost.stock_value ? `Your stock covers ${compact(plan.cost.stock_value)}` : `${silver(plan.cost.per_unit)} per item`"
          :tone="plan.cost.complete ? null : 'warning'"
        >
          <SilverAmount :value="plan.cost.total" compact />
        </StatTile>
        <StatTile label="Sale value" :hint="plan.market_value.unit ? `After tax · ${silver(plan.market_value.unit)} each on the market` : 'Not sold on the market'">
          <SilverAmount :value="saleValue" compact />
        </StatTile>
        <StatTile label="Profit" :tone="profitTone" :hint="profitHint">
          <SilverAmount :value="profit" compact />
        </StatTile>
        <StatTile label="Crafts" :hint="Object.entries(plan.by_source).map(([s, v]) => `${SKILLS[s]} ${number(v.crafts)}`).join(' · ') || 'Nothing to craft'">
          {{ number(plan.steps.reduce((sum, step) => sum + step.crafts, 0)) }}
        </StatTile>
      </section>

      <div v-if="plan.warnings.length || !plan.cost.complete || hasChoices" class="notices">
        <p v-if="!plan.cost.complete" class="notice">
          No price for {{ plan.cost.missing_prices.map((item) => item.name).join(', ') }}. The total leaves {{ plan.cost.missing_prices.length === 1 ? 'it' : 'them' }} out.
        </p>
        <p v-for="warning in plan.warnings" :key="warning" class="notice">{{ warning }}</p>
        <p v-if="hasChoices" class="notice notice-info">
          This plan uses your own choices.
          <button class="btn btn-ghost btn-sm" type="button" @click="actions.resetChoices">Reset to defaults</button>
        </p>
      </div>

      <div class="columns" :class="{ stale: loading }">
        <div class="col-main">
          <!-- Steps -->
          <section class="card">
            <div class="card-header">
              <h2>Crafting steps</h2>
              <span class="faint small">In order</span>
            </div>
            <div class="card-body">
              <p v-if="!stepGroups.length" class="muted small">Nothing to craft: everything is bought.</p>
              <div v-for="group in stepGroups" :key="group.source" class="step-group">
                <div class="step-group-head">
                  <SkillChip :source="group.source" />
                  <span class="faint small">{{ plural(group.crafts, 'craft') }}<template v-if="group.exp"> · {{ number(group.exp) }} EXP</template></span>
                </div>
                <ol class="steps">
                  <li v-for="step in group.list" :key="step.item.id" class="step">
                    <ItemIcon :item="step.item" :size="28" />
                    <div class="step-text">
                      <ItemLink :item="step.item" />
                      <span class="faint small">{{ step.recipe.category }}</span>
                      <RareProducts :products="step.recipe.rare ?? []" :bonus="rareBonus(step.recipe.source)" class="step-rare" />
                    </div>
                    <div class="step-numbers num">
                      <template v-if="step.crafts">
                        <span>{{ plural(step.crafts, 'craft') }}</span>
                        <span class="faint small">≈ {{ number(step.produced) }} made, {{ number(step.needed) }} needed</span>
                        <span v-if="step.from_stock" class="stock-text small">{{ number(step.from_stock) }} from your stock</span>
                      </template>
                      <!-- Stock covers it: nothing to craft -->
                      <span v-else class="stock-text">{{ number(step.from_stock) }} from your stock</span>
                    </div>
                    <StockInput v-if="stockMode" :item="step.item" :value="settings.have[step.item.id] ?? 0" @change="actions.have(step.item.id, $event)" />
                  </li>
                </ol>
              </div>
            </div>
          </section>

          <!-- Tree -->
          <section class="card">
            <div class="card-header">
              <h2>Recipe tree</h2>
              <span class="faint small">Change recipes and ingredients here</span>
            </div>
            <div class="card-body">
              <ul class="tree">
                <TreeNode :node="plan.tree" />
              </ul>
            </div>
          </section>
        </div>

        <!-- Shopping list -->
        <aside class="col-side">
          <section class="card materials">
            <div class="card-header">
              <h2>Materials to buy</h2>
              <div class="header-actions">
                <button class="btn btn-sm" :class="stockMode ? 'btn-secondary' : 'btn-ghost'" type="button" :aria-pressed="stockMode" @click="stockMode = !stockMode">I have some</button>
                <button class="btn btn-ghost btn-sm" type="button" @click="copyMaterials">{{ copied ? 'Copied' : 'Copy list' }}</button>
              </div>
            </div>
            <div v-if="stockMode" class="stock-help">
              <p>Enter what you already have, here and in the crafting steps. It is used before anything is bought or crafted.</p>
              <button v-if="hasStock" class="btn btn-ghost btn-sm" type="button" @click="actions.clearStock">Clear</button>
            </div>
            <table class="material-table" :class="{ stocking: stockMode }">
              <thead>
                <tr>
                  <th scope="col">Item</th>
                  <th v-if="stockMode" scope="col" class="right">Have</th>
                  <th scope="col" class="right">{{ stockUsed ? 'Buy' : 'Qty' }}</th>
                  <th scope="col" class="right">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in plan.materials" :key="m.item.id" :class="{ covered: m.qty === 0 }">
                  <td>
                    <div class="material-item">
                      <ItemIcon :item="m.item" :size="28" />
                      <div>
                        <ItemLink :item="m.item" class="material-name" />
                        <div class="faint small">
                          <template v-if="m.price">{{ silver(m.price.unit) }} · {{ PRICE_SOURCES[m.price.source] }}</template>
                          <template v-else>No price</template>
                          <template v-if="!['no_recipe', 'vendor'].includes(m.reason)"> · {{ BUY_REASONS[m.reason] }}</template>
                        </div>
                        <div v-if="m.from_stock" class="stock-text small num">{{ number(m.from_stock) }} of {{ number(m.needed) }} in stock</div>
                      </div>
                    </div>
                  </td>
                  <td v-if="stockMode" class="right">
                    <StockInput :item="m.item" :value="settings.have[m.item.id] ?? 0" @change="actions.have(m.item.id, $event)" />
                  </td>
                  <td class="right num">{{ number(m.qty) }}</td>
                  <td class="right"><SilverAmount :value="m.total_price" /></td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <th scope="row" :colspan="stockMode ? 3 : 2">Total</th>
                  <td class="right"><SilverAmount :value="plan.cost.total" /></td>
                </tr>
              </tfoot>
            </table>
            <p v-if="pricesAge" class="prices-note" :class="{ outdated: pricesStale }" :title="new Date(pricesAge * 1000).toLocaleString()">
              Market prices (EU) from {{ ago(pricesAge) }}<template v-if="pricesStale"> — may be out of date</template>
            </p>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Hero ─────────────────────────────────────────────────────────────── */
.hero {
  max-width: 680px;
  margin: var(--space-7) auto 0;
  text-align: center;
}

.hero h1 {
  font-size: var(--text-3xl);
}

.hero-sub {
  margin-top: var(--space-3);
  color: var(--text-muted);
  font-size: var(--text-md);
}

.hero-search {
  margin-top: var(--space-6);
  text-align: left;
}

.examples {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: center;
  gap: var(--space-2);
  margin-top: var(--space-4);
}

.example {
  padding: var(--space-1) var(--space-3);
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-muted);
  font-size: var(--text-sm);
  transition: border-color 140ms var(--ease), color 140ms var(--ease);
}

.example:hover {
  border-color: var(--accent);
  color: var(--text);
}

/* ── Layout ───────────────────────────────────────────────────────────── */
.calculator > * + * {
  margin-top: var(--space-4);
}

.toolbar-search {
  max-width: 420px;
}

.stale {
  opacity: 0.6;
  transition: opacity 200ms var(--ease);
}

.state {
  padding: var(--space-6);
  text-align: center;
}

.state-actions {
  display: flex;
  justify-content: center;
  gap: var(--space-2);
  margin-top: var(--space-4);
}

.loading-plan > * + * {
  margin-top: var(--space-4);
}

/* ── Item card ────────────────────────────────────────────────────────── */
.item-card {
  padding: var(--space-5);
}

.item-head {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.item-title h1 {
  font-size: var(--text-xl);
}

.item-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1) var(--space-4);
  margin-top: var(--space-1);
  font-size: var(--text-sm);
}

.options {
  display: grid;
  grid-template-columns: auto 1fr 1fr auto;
  gap: var(--space-5);
  margin-top: var(--space-5);
  padding-top: var(--space-5);
  border-top: 1px solid var(--border);
}

.help {
  margin-top: var(--space-2);
  color: var(--text-faint);
  font-size: var(--text-xs);
}

.player {
  margin: 0;
  padding: 0;
  list-style: none;
  color: var(--text-muted);
  font-size: var(--text-sm);
}

.change {
  margin: var(--space-1) 0 0 calc(-1 * var(--space-3));
}

.title-link:hover {
  text-decoration: underline;
  text-underline-offset: 4px;
}

.qty-field {
  display: flex;
  gap: var(--space-1);
}

.qty-input {
  width: 110px;
  text-align: center;
  -moz-appearance: textfield;
}

.qty-input::-webkit-inner-spin-button,
.qty-input::-webkit-outer-spin-button {
  -webkit-appearance: none;
}

/* ── Summary ──────────────────────────────────────────────────────────── */
.tiles {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-4);
}

.notices > * + * {
  margin-top: var(--space-2);
}

.notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  background: var(--warning-soft);
  color: var(--warning);
  font-size: var(--text-sm);
}

.notice-info {
  background: var(--accent-soft);
  color: var(--text);
}

/* ── Columns ──────────────────────────────────────────────────────────── */
.columns {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 380px;
  gap: var(--space-4);
  align-items: start;
}

.col-main > * + * {
  margin-top: var(--space-4);
}

.col-side {
  position: sticky;
  top: calc(var(--header-height) + var(--space-4));
}

/* ── Steps ────────────────────────────────────────────────────────────── */
.step-group + .step-group {
  margin-top: var(--space-4);
}

.step-group-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: var(--space-2);
  border-bottom: 1px solid var(--border);
}

.steps {
  margin: 0;
  padding: 0;
  list-style: none;
}

.step {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--border);
}

.step:last-child {
  border-bottom: 0;
}

.step-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
  font-size: var(--text-sm);
  font-weight: 500;
}

.step-rare {
  margin-top: 2px;
  font-weight: 400;
}

.stock-text {
  color: var(--positive);
}

.step-numbers {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  font-size: var(--text-sm);
}

/* ── Tree ─────────────────────────────────────────────────────────────── */
.tree {
  margin: 0;
  padding: 0;
}

/* ── Materials ────────────────────────────────────────────────────────── */
.material-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-sm);
}

.material-table th {
  padding: var(--space-2) var(--space-4);
  color: var(--text-muted);
  font-size: var(--text-xs);
  font-weight: 500;
  text-align: left;
}

.material-table td {
  padding: var(--space-2) var(--space-4);
  border-top: 1px solid var(--border);
  vertical-align: middle;
}

.material-table tfoot th,
.material-table tfoot td {
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border-strong);
  color: var(--text);
  font-size: var(--text-sm);
  font-weight: 600;
}

.right {
  text-align: right !important;
}

.material-item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.material-name {
  font-weight: 500;
}

/* The amount fields need room: narrower cell padding while they show */
.material-table.stocking th,
.material-table.stocking td {
  padding-inline: var(--space-2);
}

.material-table.stocking th:first-child,
.material-table.stocking td:first-child {
  padding-left: var(--space-4);
}

.material-table.stocking .material-item {
  gap: var(--space-2);
}

.material-table tr.covered td:not(:has(input)) {
  opacity: 0.6;
}

.header-actions {
  display: flex;
  gap: var(--space-1);
}

.stock-help {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border);
  color: var(--text-muted);
  font-size: var(--text-xs);
}

.stock-help p {
  flex: 1;
}

.prices-note {
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border);
  color: var(--text-faint);
  font-size: var(--text-xs);
}

.prices-note.outdated {
  color: var(--warning);
}

@media (max-width: 960px) {
  .columns {
    grid-template-columns: minmax(0, 1fr);
  }

  .col-side {
    position: static;
  }

  .options {
    grid-template-columns: 1fr;
  }

  .tiles {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 640px) {
  .hero {
    margin-top: var(--space-5);
  }

  .hero h1 {
    font-size: var(--text-2xl);
  }

  .tiles {
    gap: var(--space-2);
  }

  /* Name on the first line; numbers and the stock field below it */
  .step {
    flex-wrap: wrap;
  }

  .step-text {
    flex: 1 1 calc(100% - 40px);
  }

  .step-numbers {
    flex: 1;
    align-items: flex-start;
    margin-left: 40px;
  }
}
</style>
