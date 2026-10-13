<script setup lang="ts">
import type { CommandPaletteGroup } from '@nuxt/ui'

defineProps<{
  groups: CommandPaletteGroup[]
  placeholder?: string
}>()

const query = defineModel<string>('searchTerm', { default: '' })
</script>

<template>
  <UCommandPalette
    v-model:search-term="query"
    :groups="groups"
    :placeholder="placeholder ?? 'Buscar...'"
    :fuse="{ resultLimit: 100, fuseOptions: { ignoreLocation: true, threshold: 0.3, keys: ['label', 'prefix'] } }"
    preserve-group-order
    class="max-h-80 w-72"
    :ui="{ input: '[&>input]:h-8', item: '!items-center' }"
  >
    <template #value-trailing="{ item }">
      <UIcon v-if="item.active" name="i-lucide-check" class="size-4 text-primary" />
    </template>
    <template #empty>
      <p class="px-3 py-2 text-sm text-muted">
        Nenhum resultado
      </p>
    </template>
  </UCommandPalette>
</template>
