<script setup>
import { number } from '../format.js'
import { calculatorRoute, itemRoute } from '../links.js'
import IngredientSlots from './IngredientSlots.vue'
import ItemIcon from './ItemIcon.vue'
import ItemLink from './ItemLink.vue'
import RareProducts from './RareProducts.vue'
import SkillChip from './SkillChip.vue'

defineProps({
  /** A recipe from recipes.php with with_ingredients=1 */
  recipe: { type: Object, required: true },
})
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
      <RareProducts v-if="recipe.rare?.length" :products="recipe.rare" class="recipe-rare" />
    </div>
    <IngredientSlots :slots="recipe.ingredients ?? []" :shown="1" class="ingredients" />
    <!-- Several recipes make it: pick one on the item page -->
    <RouterLink
      v-if="recipe.item_id && recipe.product_recipes > 1"
      :to="{ ...itemRoute({ id: recipe.item_id, name: recipe.name }), hash: '#how-to-make' }"
      class="btn btn-secondary btn-sm"
    >
      {{ recipe.product_recipes }} recipes
    </RouterLink>
    <RouterLink v-else-if="recipe.item_id" :to="calculatorRoute(recipe.item_id, recipe.key)" class="btn btn-secondary btn-sm">Calculate</RouterLink>
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

.recipe-rare {
  margin-top: var(--space-1);
}

.ingredients {
  justify-content: flex-end;
  max-width: 55%;
}

@media (max-width: 640px) {
  .recipe {
    flex-wrap: wrap;
  }

  .ingredients {
    justify-content: flex-start;
    width: 100%;
    max-width: none;
  }
}
</style>
