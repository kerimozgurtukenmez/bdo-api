<script setup>
import { computed, inject, ref } from 'vue'
import { api } from '../api.js'
import { BUY_REASONS, number, plural, silver } from '../format.js'
import ItemIcon from './ItemIcon.vue'
import SilverAmount from './SilverAmount.vue'
import SkillChip from './SkillChip.vue'

const props = defineProps({
  /** One node of craft.php "tree" */
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
})

// buy / craft / recipe / substitute, from useCraftPlan()
const actions = inject('planActions')
const boughtByChoice = inject('boughtByChoice')

const open = ref(props.depth < 2)
const children = computed(() => props.node.children ?? [])
const isCraft = computed(() => props.node.action === 'craft')
const substitutes = computed(() => props.node.slot?.alternatives ?? [])
const canBuyInstead = computed(() => props.depth > 0 && isCraft.value)
const canCraftInstead = computed(() => !isCraft.value && boughtByChoice.value.has(props.node.item.id))
const canPickRecipe = computed(() => isCraft.value && props.node.recipe.other_recipes > 0)
const hasActions = computed(() => substitutes.value.length > 1 || canBuyInstead.value || canCraftInstead.value || canPickRecipe.value)

// ── Recipe picker ──────────────────────────────────────────────────────
const picking = ref(false)
const recipes = ref(null)
const recipesError = ref(null)
const filter = ref('')

async function togglePicker() {
  picking.value = !picking.value
  if (picking.value && !recipes.value) {
    try {
      const data = await api.recipesFor(props.node.item.id)
      recipes.value = data.groups.flatMap((group) =>
        group.recipes.map((recipe) => ({ ...recipe, source: group.source, category: group.category })),
      )
    } catch (e) {
      recipesError.value = e.message
    }
  }
}

const shownRecipes = computed(() => {
  const term = filter.value.trim().toLowerCase()
  if (!recipes.value || !term) return recipes.value ?? []
  return recipes.value.filter((recipe) => recipe.ingredients.some((ing) => ing.name.toLowerCase().includes(term)))
})

function pick(key) {
  actions.recipe(props.node.item.id, key)
  picking.value = false
}
</script>

<template>
  <li class="node" :class="{ root: depth === 0 }">
    <div class="row">
      <button
        v-if="children.length"
        class="toggle"
        type="button"
        :aria-expanded="open"
        :aria-label="`${open ? 'Collapse' : 'Expand'} ${node.item.name}`"
        @click="open = !open"
      >
        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" :class="{ open }">
          <path d="M4 2.5 7.5 6 4 9.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
      <span v-else class="toggle-space"></span>

      <ItemIcon :item="node.item" :size="28" />

      <div class="main">
        <div class="title">
          <span class="name" :class="`grade-${node.item.grade}`">{{ node.item.name }}</span>
          <span class="qty num">× {{ number(node.qty) }}</span>
          <!-- Raw materials are the normal case; only other reasons get a badge -->
          <span v-if="!isCraft && node.reason !== 'no_recipe'" class="badge" :class="{ 'badge-warning': node.reason === 'loop' }">
            {{ BUY_REASONS[node.reason] ?? 'Bought' }}
          </span>
        </div>
        <div class="meta">
          <template v-if="isCraft">
            <SkillChip :source="node.recipe.source" :category="node.recipe.category" />
            <span>{{ plural(node.recipe.crafts, 'craft') }}</span>
            <span class="faint">{{ node.recipe.output_min === node.recipe.output_max ? node.recipe.output_min : `${node.recipe.output_min}–${node.recipe.output_max}` }} per craft</span>
          </template>
          <template v-else>
            <span v-if="node.price">{{ silver(node.price.unit) }} each · {{ node.price.source === 'vendor' ? 'NPC' : 'market' }}</span>
            <span v-else class="warning-text">No price known</span>
          </template>
        </div>
      </div>

      <div class="cost">
        <SilverAmount :value="node.cost" />
        <span v-if="isCraft && !node.cost_complete && node.cost" class="partial" title="Some materials have no price">+?</span>
      </div>
    </div>

    <div v-if="hasActions" class="actions">
      <label v-if="substitutes.length > 1" class="substitute">
        <span class="visually-hidden">Ingredient for this slot</span>
        <select
          class="select select-sm"
          :value="node.item.id"
          @change="actions.substitute(node.slot.default_item_id, Number($event.target.value))"
        >
          <option v-for="alt in substitutes" :key="alt.id" :value="alt.id">
            {{ alt.name }}{{ alt.price ? ` — ${silver(alt.price.unit)}` : '' }}
          </option>
        </select>
      </label>
      <button v-if="canBuyInstead" class="btn btn-ghost btn-sm" type="button" @click="actions.buy(node.item.id)">Buy instead</button>
      <button v-if="canCraftInstead" class="btn btn-ghost btn-sm" type="button" @click="actions.craft(node.item.id)">Craft instead</button>
      <button v-if="canPickRecipe" class="btn btn-ghost btn-sm" type="button" :aria-expanded="picking" @click="togglePicker">
        {{ picking ? 'Close' : `Other recipes (${node.recipe.other_recipes})` }}
      </button>
    </div>

    <div v-if="picking" class="picker">
      <p v-if="recipesError" class="negative small">{{ recipesError }}</p>
      <div v-else-if="!recipes" class="skeleton" style="height: 40px"></div>
      <template v-else>
        <input v-if="recipes.length > 6" v-model="filter" class="input picker-filter" type="search" placeholder="Filter by ingredient…" aria-label="Filter recipes by ingredient" />
        <ul class="picker-list">
          <li v-for="recipe in shownRecipes" :key="recipe.key">
            <button class="picker-item" :class="{ current: recipe.key === node.recipe.key }" type="button" @click="pick(recipe.key)">
              <span class="picker-head">
                <SkillChip :source="recipe.source" :category="recipe.category" />
                <span class="faint small">{{ recipe.skill_level }}</span>
                <span v-if="recipe.key === node.recipe.key" class="badge badge-accent">In use</span>
              </span>
              <span class="picker-ings">
                <ItemIcon v-for="ing in recipe.ingredients" :key="ing.slot" :item="ing" :size="26" :qty="ing.qty_min" :title="ing.name" />
              </span>
            </button>
          </li>
        </ul>
      </template>
    </div>

    <p v-if="node.truncated" class="faint small truncated">Too many steps to show here; the totals above include them.</p>

    <ul v-if="children.length && open" class="children">
      <TreeNode v-for="(child, i) in children" :key="`${child.item.id}-${i}`" :node="child" :depth="depth + 1" />
    </ul>
  </li>
</template>

<style scoped>
.node {
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-2) var(--space-2) 0;
  border-radius: var(--radius-sm);
}

.toggle,
.toggle-space {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
}

.toggle {
  padding: 0;
  border: 0;
  border-radius: 4px;
  background: none;
  color: var(--text-muted);
  cursor: pointer;
}

.toggle:hover {
  background: var(--surface-2);
  color: var(--text);
}

.toggle svg {
  transition: transform 140ms var(--ease);
}

.toggle svg.open {
  transform: rotate(90deg);
}

.main {
  flex: 1;
  min-width: 0;
}

.title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--text-sm);
}

.name {
  font-weight: 500;
}

.qty {
  color: var(--text-muted);
}

.meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1) var(--space-3);
  margin-top: 2px;
  color: var(--text-muted);
  font-size: var(--text-xs);
}

.warning-text {
  color: var(--warning);
}

.cost {
  flex-shrink: 0;
  font-size: var(--text-sm);
  font-weight: 500;
  text-align: right;
}

.partial {
  margin-left: 2px;
  color: var(--warning);
  font-size: var(--text-xs);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  margin: -2px 0 var(--space-1) 60px;
}

.select-sm {
  height: 28px;
  max-width: 260px;
  font-size: var(--text-xs);
}

.picker {
  margin: var(--space-1) 0 var(--space-3) 60px;
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg);
}

.picker-filter {
  width: 100%;
  margin-bottom: var(--space-2);
}

.picker-list {
  max-height: 320px;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.picker-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  width: 100%;
  padding: var(--space-2);
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.picker-item:hover {
  background: var(--surface-2);
}

.picker-item.current {
  border-color: var(--accent);
  background: var(--accent-soft);
}

.picker-head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.picker-ings {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.truncated {
  margin-left: 60px;
}

.children {
  margin: 0 0 0 9px;
  padding: 0 0 0 var(--space-4);
  border-left: 1px solid var(--border);
}

@media (max-width: 640px) {
  .actions,
  .picker,
  .truncated {
    margin-left: 0;
  }
}
</style>
