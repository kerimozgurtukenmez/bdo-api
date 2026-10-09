<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { MAX_STOCK } from '../composables/useCraftPlan.js'

// How many of an item the player already has. Changes are sent after a short
// pause, on Enter or when the field loses focus.
const props = defineProps({
  /** { name } — for the accessible label */
  item: { type: Object, required: true },
  value: { type: Number, default: 0 },
})
const emit = defineEmits(['change'])

const text = ref(props.value || '')
watch(() => props.value, (value) => {
  if (toUnits(text.value) !== (value || 0)) text.value = value || ''
})

function toUnits(value) {
  const n = Math.round(Number(value))
  return Number.isFinite(n) && n > 0 ? Math.min(n, MAX_STOCK) : 0
}

let timer = null
function commit() {
  clearTimeout(timer)
  const units = toUnits(text.value)
  if (units !== (props.value || 0)) emit('change', units)
}
function onInput() {
  clearTimeout(timer)
  timer = setTimeout(commit, 500)
}
onBeforeUnmount(commit)
</script>

<template>
  <input
    v-model="text"
    class="input stock-input num"
    type="number"
    min="0"
    inputmode="numeric"
    placeholder="Have"
    :aria-label="`${item.name} you have`"
    @input="onInput"
    @change="commit"
    @keydown.enter="commit"
  />
</template>

<style scoped>
.stock-input {
  width: 68px;
  height: 30px;
  padding: 0 var(--space-2);
  font-size: var(--text-sm);
  text-align: right;
}
</style>
