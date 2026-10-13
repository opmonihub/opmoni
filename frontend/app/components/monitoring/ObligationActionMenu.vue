<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { monitoringActions } from '~/utils/monitoringPresentation'

withDefaults(defineProps<{
  selectedCount: number
  /** Phone chips row: icon-only, like `CustomersClientListMenu compact`. */
  compact?: boolean
}>(), {
  compact: false
})

const emit = defineEmits<{
  search: []
  associate: []
}>()

/**
 * The obligation sheet's compact actions — same row as the situation tabs on
 * desktop, beside the chips on phone. Matches the customers list pattern:
 * neutral outline control, menu for work that is not a primary CTA.
 */
const items = computed<DropdownMenuItem[][]>(() => [[
  {
    label: monitoringActions.searchDocuments,
    icon: 'i-lucide-file-search',
    onSelect: () => emit('search')
  },
  {
    label: monitoringActions.associate,
    icon: 'i-lucide-user-plus',
    onSelect: () => emit('associate')
  }
]])
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'end' }"
    :ui="{ content: 'min-w-56' }"
  >
    <UButton
      :label="compact ? undefined : 'Ações'"
      :icon="compact ? 'i-lucide-ellipsis' : undefined"
      :trailing-icon="compact || selectedCount ? undefined : 'i-lucide-chevron-down'"
      color="neutral"
      :variant="compact ? 'outline' : 'subtle'"
      size="sm"
      class="shrink-0"
      aria-label="Ações da obrigação"
    >
      <template v-if="selectedCount && !compact" #trailing>
        <UKbd>{{ selectedCount }}</UKbd>
      </template>
    </UButton>
  </UDropdownMenu>
</template>
