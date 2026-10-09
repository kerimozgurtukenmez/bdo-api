<script setup>
import { itemRoute } from '../links.js'

defineProps({
  /** { id (or item_id), name, grade } */
  item: { type: Object, required: true },
})
</script>

<template>
  <RouterLink :to="itemRoute(item)" class="item-link" :class="`grade-${item.grade ?? 0}`">{{ item.name }}</RouterLink>
</template>

<style scoped>
.item-link {
  font-weight: 500;
  text-decoration-color: transparent;
  text-underline-offset: 3px;
  transition: text-decoration-color 140ms var(--ease);
}

.item-link:hover {
  text-decoration: underline;
  text-decoration-color: currentColor;
}
</style>
