<script setup>
import { computed } from 'vue'
import { compact as compactFormat, silver } from '../format.js'

const props = defineProps({
  value: { type: Number, default: null },
  /** 12.3M instead of 12,345,678 (full value in the tooltip) */
  compact: { type: Boolean, default: false },
})

const text = computed(() => (props.compact ? compactFormat(props.value) : silver(props.value)))
</script>

<template>
  <span class="num" :class="{ unknown: value == null }" :title="value == null ? 'No price known' : `${silver(value)} silver`">{{ text }}</span>
</template>

<style scoped>
.unknown {
  color: var(--text-faint);
}
</style>
