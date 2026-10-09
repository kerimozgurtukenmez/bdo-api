<script setup>
import { ref } from 'vue'
import { plural, silver } from '../format.js'
import { itemRoute } from '../links.js'
import ItemIcon from './ItemIcon.vue'

const props = defineProps({
  /** Ingredient slots from recipes.php: the default item with its "alternatives" */
  slots: { type: Array, required: true },
  size: { type: Number, default: 32 },
  /** Substitutes shown per slot before "+N" (Steamed Fish takes almost any fish) */
  shown: { type: Number, default: 3 },
})

const expanded = ref(new Set())

function choices(slot) {
  const all = [slot, ...(slot.alternatives ?? [])]
  return expanded.value.has(slot.slot) ? all : all.slice(0, props.shown + 1)
}

function hidden(slot) {
  return expanded.value.has(slot.slot) ? 0 : Math.max(0, (slot.alternatives?.length ?? 0) - props.shown)
}

function expand(slot) {
  expanded.value = new Set(expanded.value).add(slot.slot)
}

// A substitute's amount may be estimated from its grade (the source gives none)
function amount(ing) {
  return ing.estimated ? `≈${ing.qty_min}` : ing.qty_min
}

function title(ing) {
  return `${ing.name} × ${amount(ing)}${ing.estimated ? ' (estimated from its grade)' : ''}${ing.price ? ` — ${silver(ing.price.unit)} each` : ''}`
}
</script>

<template>
  <ul class="slots" aria-label="Ingredients">
    <li
      v-for="slot in slots"
      :key="slot.slot"
      class="slot"
      :class="{ choice: slot.alternatives?.length }"
      :aria-label="slot.alternatives?.length ? `${slot.name} or ${plural(slot.alternatives.length, 'substitute')}` : null"
      :title="slot.alternatives?.length ? 'Any one of these' : null"
    >
      <template v-for="(ing, i) in choices(slot)" :key="i">
        <span v-if="i" class="or" aria-hidden="true">or</span>
        <RouterLink :to="itemRoute(ing)" :title="title(ing)" class="ing">
          <ItemIcon :item="ing" :size="size" :qty="amount(ing)" />
        </RouterLink>
      </template>
      <button v-if="hidden(slot)" class="more" type="button" :aria-label="`Show ${hidden(slot)} more substitutes`" @click="expand(slot)">
        +{{ hidden(slot) }}
      </button>
    </li>
  </ul>
</template>

<style scoped>
.slots {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.slot {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1);
}

/* Interchangeable ingredients share one box */
.slot.choice {
  padding: 3px;
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-2);
}

.ing {
  display: inline-flex;
  border-radius: var(--radius-sm);
}

.or {
  color: var(--text-faint);
  font-size: var(--text-xs);
}

.more {
  min-width: 28px;
  height: 24px;
  padding: 0 var(--space-1);
  border: 0;
  border-radius: var(--radius-sm);
  background: var(--surface-3);
  color: var(--text-muted);
  font: inherit;
  font-size: var(--text-xs);
  cursor: pointer;
}

.more:hover {
  color: var(--text);
}
</style>
