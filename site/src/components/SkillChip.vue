<script setup>
import { computed } from 'vue'
import { SKILLS } from '../format.js'

const props = defineProps({
  source: { type: String, required: true },  // cooking | alchemy | processing
  category: { type: String, default: null },  // Heating, Grinding, ...
})

const label = computed(() => {
  const skill = SKILLS[props.source] ?? props.source
  return props.category && props.category !== skill ? `${skill} · ${props.category}` : skill
})
</script>

<template>
  <span class="chip" :style="{ '--skill': `var(--${source})` }">
    <span class="dot" aria-hidden="true"></span>{{ label }}
  </span>
</template>

<style scoped>
.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--text-muted);
  font-size: var(--text-xs);
  font-weight: 500;
  white-space: nowrap;
}

.dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--skill);
}
</style>
