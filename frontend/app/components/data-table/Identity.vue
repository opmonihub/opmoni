<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  meta?: string
  truncate?: boolean
}>(), {
  truncate: true
})
</script>

<template>
  <div class="min-w-0">
    <p
      class="font-medium text-highlighted"
      :class="truncate ? 'truncate' : 'break-words'"
      :title="truncate ? title : undefined"
    >
      {{ title }}
    </p>
    <p
      v-if="meta"
      class="text-sm text-muted tabular-nums"
      :class="truncate ? 'truncate' : undefined"
    >
      {{ meta }}
    </p>
    <slot />
  </div>
</template>
