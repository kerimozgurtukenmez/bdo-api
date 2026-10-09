<script setup>
// Rare products of a recipe. The game does not publish their chance, only the
// life skill level they need (from the item description).
import ItemIcon from './ItemIcon.vue'
import ItemLink from './ItemLink.vue'

defineProps({
  /** Rare products of a recipe: item with qty_min, qty_max and requires ("Skilled 1" or null) */
  products: { type: Array, required: true },
  /** Extra rare chance from the player's mastery (0.1156 = +11.56%), if known */
  bonus: { type: Number, default: null },
})

function range(product) {
  return product.qty_min === product.qty_max ? product.qty_min : `${product.qty_min}–${product.qty_max}`
}
</script>

<template>
  <p v-if="products.length" class="rare">
    <span class="rare-label">Rare</span>
    <span v-for="product in products" :key="product.id ?? product.item_id" class="rare-item">
      <ItemIcon :item="product" :size="20" />
      <ItemLink :item="product" />
      <span class="num muted">× {{ range(product) }}</span>
      <span class="faint">slight chance{{ product.requires ? ` from ${product.requires}` : '' }}</span>
    </span>
    <span v-if="bonus" class="faint">· your mastery +{{ (bonus * 100).toFixed(1) }}%</span>
  </p>
</template>

<style scoped>
.rare {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1) var(--space-2);
  margin: 0;
  font-size: var(--text-xs);
}

.rare-label {
  padding: 1px 6px;
  border-radius: var(--radius-sm);
  background: var(--surface-3);
  color: var(--text-muted);
  font-weight: 500;
}

.rare-item {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1);
}

.rare-item > * {
  white-space: nowrap;
}
</style>
