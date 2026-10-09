import { createRouter, createWebHistory } from 'vue-router'
import CalculatorView from './views/CalculatorView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'calculator', component: CalculatorView, meta: { title: 'Crafting calculator' } },
    {
      path: '/recipes/:source(cooking|alchemy|processing)?',
      name: 'recipes',
      component: () => import('./views/RecipesView.vue'),
      meta: { title: 'Recipes' },
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./views/NotFoundView.vue'), meta: { title: 'Page not found' } },
  ],
  scrollBehavior(to, from) {
    // Changing calculator options must not jump to the top
    return to.path === from.path ? false : { top: 0 }
  },
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · BDO Craft` : 'BDO Craft'
})

export default router
