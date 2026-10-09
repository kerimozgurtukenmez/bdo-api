<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  /** { name, icon, grade } */
  item: { type: Object, required: true },
  size: { type: Number, default: 32 },
  /** Small quantity badge in the corner */
  qty: { type: [Number, String], default: null },
})

const failed = ref(false)
watch(() => props.item.icon, () => (failed.value = false))
</script>

<template>
  <span class="icon" :class="`frame-${item.grade ?? 0}`" :style="{ width: `${size}px`, height: `${size}px` }">
    <img v-if="item.icon && !failed" :src="item.icon" :alt="item.name" :width="size" :height="size" loading="lazy" @error="failed = true" />
    <span v-else class="placeholder" aria-hidden="true" :style="{ fontSize: `${Math.round(size * 0.42)}px` }">{{ item.name?.charAt(0) ?? '?' }}</span>
    <span v-if="qty != null" class="qty num">{{ qty }}</span>
  </span>
</template>

<style scoped>
.icon {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-2);
}

.icon img {
  width: 100%;
  height: 100%;
  border-radius: inherit;
  object-fit: cover;
}

.frame-1 { border-color: color-mix(in srgb, var(--grade-1) 60%, transparent); }
.frame-2 { border-color: color-mix(in srgb, var(--grade-2) 60%, transparent); }
.frame-3 { border-color: color-mix(in srgb, var(--grade-3) 60%, transparent); }
.frame-4 { border-color: color-mix(in srgb, var(--grade-4) 60%, transparent); }

.placeholder {
  color: var(--text-faint);
  font-weight: 600;
}

.qty {
  position: absolute;
  right: -4px;
  bottom: -5px;
  min-width: 16px;
  padding: 0 3px;
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--surface);
  color: var(--text);
  font-size: 10px;
  font-weight: 600;
  line-height: 14px;
  text-align: center;
}
</style>
