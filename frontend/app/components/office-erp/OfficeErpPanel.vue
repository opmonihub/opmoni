<script setup lang="ts">
defineProps<{ title: string, description?: string }>()
</script>

<template>
  <section class="flex min-w-0 flex-col gap-3">
    <UPageCard
      :title="title"
      :description="description"
      variant="naked"
      orientation="horizontal"
      :ui="{ root: 'items-start', container: 'items-start', header: 'gap-1', title: 'text-base font-semibold', body: 'shrink-0' }"
    >
      <template v-if="$slots.action" #default>
        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:ms-auto">
          <slot name="action" />
        </div>
      </template>
    </UPageCard>
    <UPageCard
      variant="subtle"
      :ui="{ root: 'min-w-0', container: 'min-w-0 gap-0', body: 'min-w-0 p-0' }"
    >
      <div v-if="$slots.toolbar" class="flex flex-wrap items-center gap-3 border-b border-default px-4 py-3">
        <slot name="toolbar" />
      </div>
      <slot />
    </UPageCard>
  </section>
</template>
