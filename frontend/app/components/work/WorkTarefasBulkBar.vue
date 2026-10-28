<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

const props = defineProps<{
  count: number
  disabled?: boolean
  assigneeItems: Array<{ label: string, value: number | null }>
  membersFailed?: boolean
}>()

const emit = defineEmits<{
  clear: []
  assign: [memberId: number | null]
  status: [status: 'todo' | 'doing' | 'done']
  dismiss: []
}>()

const noun = computed(() => props.count === 1 ? 'tarefa selecionada' : 'tarefas selecionadas')

const statusItems = computed<DropdownMenuItem[][]>(() => [[
  {
    label: 'Marcar como A fazer',
    icon: 'i-lucide-circle',
    disabled: props.disabled,
    onSelect: () => emit('status', 'todo')
  },
  {
    label: 'Marcar como Em progresso',
    icon: 'i-lucide-loader',
    disabled: props.disabled,
    onSelect: () => emit('status', 'doing')
  },
  {
    label: 'Marcar como Concluída',
    icon: 'i-lucide-circle-check',
    disabled: props.disabled,
    onSelect: () => emit('status', 'done')
  }
]])

const assignItems = computed<DropdownMenuItem[][]>(() => {
  if (props.membersFailed) {
    return [[{ label: 'Responsáveis indisponíveis', disabled: true }]]
  }
  return [props.assigneeItems.map(item => ({
    label: item.label,
    disabled: props.disabled,
    onSelect: () => emit('assign', item.value)
  }))]
})
</script>

<template>
  <DataTableSelectionBar
    floating
    :count="count"
    :label="noun"
    :disabled="disabled"
    @clear="emit('clear')"
  >
    <UDropdownMenu :items="assignItems" :content="{ align: 'end' }">
      <UButton
        label="Atribuir"
        icon="i-lucide-user-round"
        color="neutral"
        variant="outline"
        size="sm"
        class="hidden sm:inline-flex"
        :disabled="disabled || membersFailed"
      />
    </UDropdownMenu>

    <UDropdownMenu :items="statusItems" :content="{ align: 'end' }">
      <UButton
        label="Status"
        icon="i-lucide-circle-dot"
        color="neutral"
        variant="outline"
        size="sm"
        :disabled="disabled"
      />
    </UDropdownMenu>

    <UButton
      label="Dispensar"
      icon="i-lucide-circle-minus"
      color="warning"
      variant="outline"
      size="sm"
      :disabled="disabled"
      @click="emit('dismiss')"
    />
  </DataTableSelectionBar>
</template>
