<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

const props = defineProps<{
  count: number
  disabled?: boolean
  memberItems?: { label: string, value: number | null }[]
}>()

const emit = defineEmits<{
  clear: []
  advance: []
  dismiss: []
  assign: [memberId: number | null]
}>()

const noun = computed(() => props.count === 1 ? 'tarefa selecionada' : 'tarefas selecionadas')

const assignItems = computed<DropdownMenuItem[][]>(() => {
  const items = props.memberItems ?? []
  if (!items.length) {
    return [[{
      label: 'Nenhum responsável disponível',
      disabled: true
    }]]
  }
  return [items.map(item => ({
    label: item.label,
    onSelect: () => emit('assign', item.value)
  }))]
})

const moreItems = computed<DropdownMenuItem[][]>(() => [
  [{
    label: 'Atribuir responsável',
    icon: 'i-lucide-user-round',
    children: (props.memberItems ?? []).map(item => ({
      label: item.label,
      onSelect: () => emit('assign', item.value)
    }))
  }, {
    label: 'Dispensar',
    icon: 'i-lucide-circle-minus',
    onSelect: () => emit('dismiss')
  }]
])
</script>

<template>
  <DataTableSelectionBar
    :count="count"
    :label="noun"
    :disabled="disabled"
    @clear="emit('clear')"
  >
    <UButton
      label="Avançar"
      icon="i-lucide-arrow-right"
      color="neutral"
      variant="outline"
      size="sm"
      :disabled="disabled"
      @click="emit('advance')"
    />
    <UDropdownMenu :items="assignItems" :content="{ align: 'end' }">
      <UButton
        label="Atribuir"
        icon="i-lucide-user-round"
        color="neutral"
        variant="outline"
        size="sm"
        class="hidden sm:inline-flex"
        :disabled="disabled"
      />
    </UDropdownMenu>
    <UButton
      label="Dispensar"
      icon="i-lucide-circle-minus"
      color="warning"
      variant="outline"
      size="sm"
      class="hidden sm:inline-flex"
      :disabled="disabled"
      @click="emit('dismiss')"
    />
    <UDropdownMenu :items="moreItems" :content="{ align: 'end' }">
      <UButton
        icon="i-lucide-ellipsis"
        color="neutral"
        variant="outline"
        size="sm"
        class="sm:hidden"
        :disabled="disabled"
        aria-label="Mais ações"
      />
    </UDropdownMenu>
  </DataTableSelectionBar>
</template>
