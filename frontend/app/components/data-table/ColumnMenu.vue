<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

const props = defineProps<{
  columns: { id: string, label: string }[]
}>()

const visibility = defineModel<Record<string, boolean>>({ required: true })
const wide = useClientMediaQuery('(min-width: 768px)')

const items = computed<DropdownMenuItem[][]>(() => [props.columns.map(column => ({
  label: column.label,
  type: 'checkbox' as const,
  checked: visibility.value[column.id] !== false,
  onUpdateChecked(checked: boolean) {
    visibility.value = { ...visibility.value, [column.id]: checked }
  },
  onSelect(event: Event) {
    event.preventDefault()
  }
}))])
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton
      :label="wide ? 'Colunas' : undefined"
      color="neutral"
      variant="outline"
      trailing-icon="i-lucide-settings-2"
      class="shrink-0"
      aria-label="Ocultar colunas"
    />
  </UDropdownMenu>
</template>
