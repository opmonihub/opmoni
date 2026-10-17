<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <UDashboardPanelContent class="space-y-4 p-4 sm:p-6">
    <div>
      <h1 class="text-highlighted text-2xl font-semibold">
        {{ title }}
      </h1>
      <p v-if="description" class="text-muted mt-1 text-sm">
        {{ description }}
      </p>
    </div>
    <slot />
  </UDashboardPanelContent>
</template>
