<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

const props = defineProps<{
  sort: string
  direction: string
  fields: { value: string, label: string }[]
}>()
const emit = defineEmits<{ change: [ordering: { sort: string, direction: string }] }>()
const selected = computed(() => props.fields.find(field => field.value === props.sort))
const items = computed<DropdownMenuItem[][]>(() => [[{
  label: 'Ordem padrão',
  icon: !selected.value ? 'i-lucide-check' : undefined,
  onSelect: () => emit('change', { sort: '', direction: 'asc' })
}], props.fields.map(field => ({
  label: field.label,
  children: ['asc', 'desc'].map(direction => ({
    label: direction === 'asc' ? 'Crescente' : 'Decrescente',
    icon: props.sort === field.value && props.direction === direction ? 'i-lucide-check' : undefined,
    onSelect: () => emit('change', { sort: field.value, direction })
  }))
}))])
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton
      color="neutral"
      variant="ghost"
      icon="i-lucide-arrow-up-down"
      :label="selected ? `${selected.label} · ${direction === 'desc' ? 'decrescente' : 'crescente'}` : 'Ordenar'"
      aria-label="Ordenar resultados"
      class="shrink-0"
    />
  </UDropdownMenu>
</template>
