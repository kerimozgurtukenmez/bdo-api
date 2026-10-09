<template>
  <section class="not-found">
    <h1>Page not found</h1>
    <p class="muted">This page does not exist, or it moved.</p>
    <RouterLink to="/" class="btn btn-primary">Go to the calculator</RouterLink>
  </section>
</template>

<style scoped>
.not-found {
  max-width: 480px;
  margin: var(--space-8) auto;
  text-align: center;
}

.not-found p {
  margin: var(--space-2) 0 var(--space-5);
}
</style>
