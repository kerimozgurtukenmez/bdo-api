<script setup>
defineProps({
  error: { type: Object, required: true },
  /** Show a "Try again" button */
  retry: { type: Boolean, default: true },
})

const emit = defineEmits(['retry'])
</script>

<template>
  <div class="card state" role="alert">
    <p>{{ error.message }}</p>
    <div class="state-actions">
      <button v-if="retry && error.status !== 404" class="btn btn-secondary" type="button" @click="emit('retry')">Try again</button>
      <slot />
    </div>
  </div>
</template>

<style scoped>
.state {
  padding: var(--space-6);
  text-align: center;
}

.state-actions {
  display: flex;
  justify-content: center;
  gap: var(--space-2);
  margin-top: var(--space-4);
}

.state-actions:empty {
  display: none;
}
</style>
