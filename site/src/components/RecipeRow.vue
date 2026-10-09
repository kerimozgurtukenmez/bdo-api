<script setup>
import { number, silver } from '../format.js'
import { calculatorRoute, itemRoute } from '../links.js'
import ItemIcon from './ItemIcon.vue'
import ItemLink from './ItemLink.vue'
import SkillChip from './SkillChip.vue'

defineProps({
  /** A recipe from recipes.php with with_ingredients=1 */
  recipe: { type: Object, required: true },
})

function ingredientTitle(ing) {
  return `${ing.name} × ${ing.qty_min}${ing.price ? ` — ${silver(ing.price.unit)} each` : ''}`
}
</script>

<template>
  <li class="card recipe">
    <ItemIcon :item="recipe" :size="40" />
    <div class="recipe-text">
      <ItemLink v-if="recipe.item_id" :item="{ id: recipe.item_id, name: recipe.name, grade: recipe.grade }" />
      <span v-else :class="`grade-${recipe.grade}`">{{ recipe.name }}</span>
      <span class="recipe-meta">
        <SkillChip :source="recipe.source" :category="recipe.category" />
        <span class="faint">{{ recipe.skill_level }}</span>
        <span v-if="recipe.exp" class="faint">{{ number(recipe.exp) }} EXP</span>
      </span>
    </div>
    <div class="ingredients" aria-label="Ingredients">
      <RouterLink v-for="ing in recipe.ingredients" :key="ing.slot" :to="itemRoute(ing)" :title="ingredientTitle(ing)">
        <ItemIcon :item="ing" :size="32" :qty="ing.qty_min" />
      </RouterLink>
    </div>
    <RouterLink v-if="recipe.item_id" :to="calculatorRoute(recipe.item_id, recipe.key)" class="btn btn-secondary btn-sm">Calculate</RouterLink>
  </li>
</template>

<style scoped>
.recipe {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  padding: var(--space-3) var(--space-4);
  list-style: none;
  transition: border-color 140ms var(--ease);
}

.recipe:hover {
  border-color: var(--border-strong);
}

.recipe-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.recipe-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1) var(--space-3);
  font-size: var(--text-xs);
}

.ingredients {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  justify-content: flex-end;
}

.ingredients a {
  display: inline-flex;
  border-radius: var(--radius-sm);
}

@media (max-width: 640px) {
  .recipe {
    flex-wrap: wrap;
  }

  .ingredients {
    justify-content: flex-start;
    width: 100%;
  }
}
</style>
