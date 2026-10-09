import { createRouter, createWebHistory } from 'vue-router'
import CalculatorView from './views/CalculatorView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'calculator', component: CalculatorView, meta: { title: 'Crafting calculator' } },
    {
      // /recipes/processing/heating — category is a slug of a processing category
      path: '/recipes/:source(cooking|alchemy|processing)?/:category?',
      name: 'recipes',
      component: () => import('./views/RecipesView.vue'),
      meta: { title: 'Recipes' },
    },
    {
      path: '/item/:id(\\d+)/:slug?',
      name: 'item',
      component: () => import('./views/ItemView.vue'),
      meta: { title: 'Item' },
    },
    {
      path: '/imperial/:skill(cooking|alchemy)?',
      name: 'imperial',
      component: () => import('./views/ImperialView.vue'),
      meta: { title: 'Imperial delivery' },
    },
    {
      path: '/profits/:source(cooking|alchemy|processing)?',
      name: 'profits',
      component: () => import('./views/ProfitsView.vue'),
      meta: { title: 'What to craft' },
    },
    { path: '/about', name: 'about', component: () => import('./views/AboutView.vue'), meta: { title: 'About' } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./views/NotFoundView.vue'), meta: { title: 'Page not found' } },
  ],
  scrollBehavior(to, from, saved) {
    if (saved) return saved
    // Changing options on the same page must not jump to the top
    return to.path === from.path ? false : { top: 0 }
  },
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · BDO Craft` : 'BDO Craft'
})

export default router
