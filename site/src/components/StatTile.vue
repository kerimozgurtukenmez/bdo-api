<script setup>
defineProps({
  label: { type: String, required: true },
  hint: { type: String, default: null },
  /** positive | negative | warning */
  tone: { type: String, default: null },
})
</script>

<template>
  <div class="tile">
    <div class="label">{{ label }}</div>
    <div class="value num" :class="tone"><slot /></div>
    <div v-if="hint" class="hint">{{ hint }}</div>
  </div>
</template>

<style scoped>
.tile {
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
}

.value {
  font-size: var(--text-xl);
  font-weight: 600;
  letter-spacing: -0.01em;
}

.value.positive { color: var(--positive); }
.value.negative { color: var(--negative); }
.value.warning { color: var(--warning); }

.hint {
  margin-top: var(--space-1);
  color: var(--text-faint);
  font-size: var(--text-xs);
}
</style>
