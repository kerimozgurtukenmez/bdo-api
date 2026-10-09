<script setup>
defineProps({
  /** [{ value, label }] */
  options: { type: Array, required: true },
  label: { type: String, required: true },
})

const model = defineModel({ type: String, required: true })
</script>

<template>
  <div class="segmented" role="radiogroup" :aria-label="label">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="model === option.value"
      :class="{ active: model === option.value }"
      @click="model = option.value"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<style scoped>
.segmented {
  display: inline-flex;
  padding: 3px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-2);
}

button {
  height: 28px;
  padding: 0 var(--space-3);
  border: 0;
  border-radius: 4px;
  background: none;
  color: var(--text-muted);
  font: inherit;
  font-size: var(--text-sm);
  font-weight: 500;
  cursor: pointer;
  transition: background 140ms var(--ease), color 140ms var(--ease);
}

button:hover {
  color: var(--text);
}

button.active {
  background: var(--surface);
  color: var(--text);
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.2);
}
</style>
