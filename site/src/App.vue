<script setup>
import AppHeader from './components/AppHeader.vue'
import AppFooter from './components/AppFooter.vue'
</script>

<template>
  <AppHeader />
  <main class="container page">
    <RouterView />
  </main>
  <AppFooter />
</template>

<style scoped>
.page {
  min-height: calc(100vh - var(--header-height) - 120px);
  padding-top: var(--space-6);
  padding-bottom: var(--space-7);
}
</style>
