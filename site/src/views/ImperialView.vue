<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api.js'
import { useMastery } from '../composables/useMastery.js'
import { useSettings } from '../composables/useSettings.js'
import { useSettingsPanel } from '../composables/useSettingsPanel.js'
import { SKILLS, number, silver } from '../format.js'
import { calculatorRoute, itemRoute } from '../links.js'
import ErrorState from '../components/ErrorState.vue'
import ItemIcon from '../components/ItemIcon.vue'
import ItemLink from '../components/ItemLink.vue'
import SampleBadge from '../components/SampleBadge.vue'
import SilverAmount from '../components/SilverAmount.vue'

// The Imperial delivery NPC pays base price × (2.5 + mastery bonus) per box, tax free

const route = useRoute()
const skill = computed(() => route.params.skill || 'cooking')

const { settings } = useSettings()
const { bonus, isSample: masterySample } = useMastery()
const { openSettings } = useSettingsPanel()

const mastery = computed(() => settings.mastery[skill.value])
const masteryBonus = computed(() => bonus(skill.value, mastery.value)?.imperial ?? 0)
const multiplier = computed(() => 2.5 + masteryBonus.value)

// ── Boxes ──────────────────────────────────────────────────────────────
const data = ref(null)
const error = ref(null)
let controller = null

async function load() {
  controller?.abort()
  const current = new AbortController()
  controller = current
  data.value = null
  error.value = null
  try {
    data.value = await api.imperial(skill.value, current.signal)
  } catch (e) {
    if (e.name !== 'AbortError') error.value = e
  }
}

watch(skill, load, { immediate: true })
onBeforeUnmount(() => controller?.abort())

const SHOWN = 5
const expanded = reactive({})

/** Silver to buy every ingredient of a box recipe on the market, or null if a price is missing */
function buyCost(recipe) {
  if (!recipe.ingredients.every((ing) => ing.price)) return null
  return recipe.ingredients.reduce((sum, ing) => sum + ing.qty * ing.price.unit, 0)
}

const boxes = computed(() =>
  (data.value?.boxes ?? []).map((box) => {
    const payout = Math.floor(box.base_price * multiplier.value)
    const recipes = box.recipes
      .map((recipe) => {
        const cost = buyCost(recipe)
        return { ...recipe, cost, profit: cost == null ? null : payout - cost }
      })
      .sort((a, b) => (b.profit ?? -Infinity) - (a.profit ?? -Infinity))
    return { ...box, payout, recipes }
  }),
)

const percent = (fraction) => `${number(fraction * 100)}%`
</script>

<template>
  <div class="imperial">
    <header class="page-head">
      <h1>Imperial delivery</h1>
      <p class="muted">
        Pack dishes or potions into boxes and deliver them for silver. Each box pays its base price × (2.5 + your mastery bonus),
        with no market tax.
      </p>
    </header>

    <nav class="tabs" aria-label="Life skill">
      <RouterLink
        v-for="key in ['cooking', 'alchemy']"
        :key="key"
        :to="{ name: 'imperial', params: { skill: key } }"
        class="tab"
        :class="{ active: skill === key }"
        :aria-current="skill === key ? 'page' : undefined"
        :style="{ '--skill': `var(--${key})` }"
      >
        <span class="tab-dot" aria-hidden="true"></span>{{ SKILLS[key] }}
      </RouterLink>
    </nav>

    <div class="mastery-bar card">
      <div>
        <span class="label">Your {{ SKILLS[skill].toLowerCase() }} mastery</span>
        <span class="mastery-value num">{{ number(mastery) }}</span>
      </div>
      <div>
        <span class="label">Delivery bonus</span>
        <span class="mastery-value num">+{{ percent(masteryBonus) }}</span>
        <SampleBadge v-if="masterySample" />
      </div>
      <div>
        <span class="label">Payout</span>
        <span class="mastery-value num">× {{ number(multiplier) }}</span>
      </div>
      <button class="btn btn-secondary btn-sm" type="button" @click="openSettings">Change mastery</button>
    </div>

    <ErrorState v-if="error" :error="error" @retry="load" />

    <div v-else-if="!data" class="box-list">
      <div v-for="n in 3" :key="n" class="skeleton" style="height: 220px"></div>
    </div>

    <template v-else>
      <p class="faint small sample-note">
        <SampleBadge v-if="data.mock" />
        Cost is what the ingredients cost on the market. <strong>Calculate</strong> shows the cost of crafting them yourself.
      </p>

      <div class="box-list">
        <section v-for="box in boxes" :key="box.item.id" class="card box">
          <header class="box-head">
            <ItemIcon :item="box.item" :size="44" />
            <div class="box-title">
              <ItemLink :item="box.item" />
              <span class="faint small">{{ box.tier }} · base price {{ silver(box.base_price) }}</span>
            </div>
            <div class="payout">
              <span class="label">Pays per box</span>
              <SilverAmount class="payout-value" :value="box.payout" />
            </div>
          </header>

          <table class="recipes">
            <thead>
              <tr>
                <th scope="col">Ingredients</th>
                <th scope="col" class="right">Cost per box</th>
                <th scope="col" class="right">Profit</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="recipe in expanded[box.item.id] ? box.recipes : box.recipes.slice(0, SHOWN)" :key="recipe.key">
                <td>
                  <!-- What to buy for one box: quantity × price each = line total -->
                  <ul class="ingredients">
                    <li v-for="ing in recipe.ingredients" :key="ing.item.id" class="ingredient">
                      <RouterLink :to="itemRoute(ing.item)" tabindex="-1" aria-hidden="true">
                        <ItemIcon :item="ing.item" :size="32" />
                      </RouterLink>
                      <div class="ingredient-text">
                        <ItemLink :item="ing.item" />
                        <span class="ingredient-math num">
                          × {{ number(ing.qty) }}
                          <template v-if="ing.price"> · {{ silver(ing.price.unit) }} each</template>
                          <template v-else> · no price</template>
                        </span>
                      </div>
                      <SilverAmount v-if="recipe.ingredients.length > 1" class="line-total" :value="ing.price ? ing.qty * ing.price.unit : null" />
                    </li>
                  </ul>
                </td>
                <td class="right cost"><SilverAmount :value="recipe.cost" /></td>
                <td class="right" :class="recipe.profit == null ? null : recipe.profit >= 0 ? 'positive' : 'negative'">
                  <SilverAmount :value="recipe.profit" />
                </td>
                <td class="right">
                  <RouterLink :to="calculatorRoute(box.item.id, recipe.key)" class="btn btn-ghost btn-sm">Calculate</RouterLink>
                </td>
              </tr>
            </tbody>
          </table>

          <button
            v-if="box.recipes.length > SHOWN"
            class="btn btn-ghost btn-sm more"
            type="button"
            @click="expanded[box.item.id] = !expanded[box.item.id]"
          >
            {{ expanded[box.item.id] ? 'Show fewer' : `Show all ${number(box.recipes.length)} recipes` }}
          </button>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.imperial > * + * {
  margin-top: var(--space-4);
}

.page-head p {
  max-width: 720px;
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

.mastery-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-4) var(--space-6);
  padding: var(--space-4);
}

.mastery-bar > div {
  display: flex;
  flex-direction: column;
}

.mastery-value {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--text-lg);
  font-weight: 600;
}

.mastery-bar .btn {
  margin-left: auto;
}

.sample-note {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}

.box-list > * + * {
  margin-top: var(--space-4);
}

.box {
  padding: var(--space-4);
}

.box-head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.box-title {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.payout {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.payout-value {
  font-size: var(--text-lg);
  font-weight: 600;
}

.recipes {
  width: 100%;
  margin-top: var(--space-3);
  border-collapse: collapse;
  font-size: var(--text-sm);
}

.recipes th {
  padding: var(--space-2) var(--space-2);
  color: var(--text-muted);
  font-size: var(--text-xs);
  font-weight: 500;
  text-align: left;
}

.recipes td {
  padding: var(--space-2);
  border-top: 1px solid var(--border);
  vertical-align: middle;
}

.right {
  text-align: right !important;
}

.ingredients {
  margin: 0;
  padding: 0;
  list-style: none;
}

.ingredient {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.ingredient + .ingredient {
  margin-top: var(--space-2);
}

.ingredient a {
  display: inline-flex;
  border-radius: var(--radius-sm);
}

.ingredient-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.ingredient-math {
  color: var(--text-muted);
  font-size: var(--text-xs);
}

.line-total {
  color: var(--text-muted);
  font-size: var(--text-xs);
}

.cost {
  font-weight: 600;
}

.more {
  margin-top: var(--space-2);
}

@media (max-width: 640px) {
  .box-head {
    flex-wrap: wrap;
  }

  .payout {
    align-items: flex-start;
    width: 100%;
  }

  .mastery-bar .btn {
    margin-left: 0;
  }
}
</style>
