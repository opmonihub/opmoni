<script setup lang="ts">
defineProps<{ title: string, description?: string }>()
</script>

<template>
  <section class="flex min-w-0 flex-col gap-5">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0 space-y-1">
        <h2 class="text-base font-semibold text-highlighted">
          {{ title }}
        </h2>
        <p v-if="description" class="max-w-3xl text-sm text-muted">
          {{ description }}
        </p>
      </div>
      <div v-if="$slots.action" class="flex shrink-0 flex-wrap items-center gap-2">
        <slot name="action" />
      </div>
    </header>
    <div class="min-w-0 overflow-hidden rounded-xl border border-default bg-default">
      <div v-if="$slots.toolbar" class="flex flex-wrap items-end gap-3 border-b border-default p-4">
        <slot name="toolbar" />
      </div>
      <slot />
    </div>
  </section>
</template>
