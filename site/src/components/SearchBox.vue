<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue'
import { api } from '../api.js'
import ItemIcon from './ItemIcon.vue'
import SkillChip from './SkillChip.vue'
import SilverAmount from './SilverAmount.vue'

defineProps({
  placeholder: { type: String, default: 'Search an item to craft…' },
  large: { type: Boolean, default: false },
  autofocus: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const listId = useId()
const query = ref('')
const results = ref([])
const open = ref(false)
const loading = ref(false)
const active = ref(-1)

let timer = null
let controller = null

watch(query, (value) => {
  clearTimeout(timer)
  const term = value.trim()
  if (term.length < 2) {
    results.value = []
    open.value = false
    return
  }
  timer = setTimeout(() => search(term), 250)
})

async function search(term) {
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  try {
    const data = await api.searchCraftable(term, controller.signal)
    results.value = data.data
    active.value = results.value.length ? 0 : -1
    open.value = true
  } catch (error) {
    if (error.name !== 'AbortError') {
      results.value = []
      open.value = true
    }
  } finally {
    loading.value = false
  }
}

function choose(item) {
  emit('select', item)
  query.value = ''
  results.value = []
  open.value = false
}

function onKeydown(event) {
  if (!open.value || !results.value.length) return

  if (event.key === 'ArrowDown') {
    active.value = (active.value + 1) % results.value.length
  } else if (event.key === 'ArrowUp') {
    active.value = (active.value - 1 + results.value.length) % results.value.length
  } else if (event.key === 'Enter' && active.value >= 0) {
    choose(results.value[active.value])
  } else if (event.key === 'Escape') {
    open.value = false
  } else {
    return
  }
  event.preventDefault()
}

onBeforeUnmount(() => {
  clearTimeout(timer)
  controller?.abort()
})
</script>

<template>
  <div class="search" :class="{ large }">
    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </svg>
    <input
      v-model="query"
      class="input search-input"
      type="search"
      role="combobox"
      autocomplete="off"
      spellcheck="false"
      :placeholder="placeholder"
      :autofocus="autofocus"
      aria-label="Search items to craft"
      aria-autocomplete="list"
      :aria-expanded="open"
      :aria-controls="listId"
      :aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined"
      @keydown="onKeydown"
      @focus="open = results.length > 0"
      @blur="open = false"
    />
    <span v-if="loading" class="spinner" aria-hidden="true"></span>

    <ul v-show="open" :id="listId" class="results" role="listbox">
      <li v-if="!results.length" class="empty">No craftable item matches “{{ query.trim() }}”.</li>
      <li
        v-for="(item, i) in results"
        :id="`${listId}-${i}`"
        :key="item.id"
        role="option"
        :aria-selected="i === active"
        :class="{ active: i === active }"
        @mousedown.prevent="choose(item)"
        @mousemove="active = i"
      >
        <ItemIcon :item="item" :size="32" />
        <div class="result-text">
          <span :class="`grade-${item.grade}`">{{ item.name }}</span>
          <span class="skills">
            <SkillChip v-for="source in item.sources" :key="source" :source="source" />
          </span>
        </div>
        <SilverAmount v-if="item.price" class="price small muted" :value="item.price.unit" compact />
      </li>
    </ul>
  </div>
</template>

<style scoped>
.search {
  position: relative;
  width: 100%;
}

.search-icon {
  position: absolute;
  top: 50%;
  left: 12px;
  color: var(--text-faint);
  transform: translateY(-50%);
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding-left: 38px;
}

.large .search-input {
  height: 52px;
  padding-left: 46px;
  border-radius: var(--radius);
  font-size: var(--text-md);
}

.large .search-icon {
  left: 16px;
}

.search-input::-webkit-search-cancel-button {
  display: none;
}

.spinner {
  position: absolute;
  top: 50%;
  right: 14px;
  width: 16px;
  height: 16px;
  margin-top: -8px;
  border: 2px solid var(--border-strong);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.results {
  position: absolute;
  z-index: 40;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  max-height: 420px;
  margin: 0;
  padding: var(--space-1);
  overflow-y: auto;
  list-style: none;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  box-shadow: var(--shadow-float);
}

.results li {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.results li.active {
  background: var(--surface-2);
}

.results .empty {
  color: var(--text-muted);
  font-size: var(--text-sm);
  cursor: default;
}

.result-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
  font-size: var(--text-sm);
  font-weight: 500;
}

.skills {
  display: flex;
  gap: var(--space-3);
}
</style>
