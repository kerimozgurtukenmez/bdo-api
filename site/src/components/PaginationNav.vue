<script setup>
import { computed } from 'vue'

const props = defineProps({
  page: { type: Number, required: true },
  totalPages: { type: Number, required: true },
})

const emit = defineEmits(['go'])

// Page numbers around the current one: 1 … 4 5 [6] 7 8 … 20
const pages = computed(() => {
  const list = []
  for (let p = 1; p <= props.totalPages; p++) {
    if (p === 1 || p === props.totalPages || Math.abs(p - props.page) <= 2) list.push(p)
    else if (list[list.length - 1] !== '…') list.push('…')
  }
  return list
})
</script>

<template>
  <nav v-if="totalPages > 1" class="pagination" aria-label="Pages">
    <button class="btn btn-ghost btn-sm" type="button" :disabled="page === 1" @click="emit('go', page - 1)">Previous</button>
    <template v-for="(p, i) in pages" :key="i">
      <span v-if="p === '…'" class="faint">…</span>
      <button
        v-else
        class="btn btn-sm"
        :class="p === page ? 'btn-primary' : 'btn-ghost'"
        type="button"
        :aria-current="p === page ? 'page' : undefined"
        @click="emit('go', p)"
      >
        {{ p }}
      </button>
    </template>
    <button class="btn btn-ghost btn-sm" type="button" :disabled="page === totalPages" @click="emit('go', page + 1)">Next</button>
  </nav>
</template>

<style scoped>
.pagination {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: center;
  gap: var(--space-1);
}
</style>
