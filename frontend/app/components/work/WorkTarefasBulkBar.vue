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

const countLabel = computed(() => new Intl.NumberFormat('pt-BR').format(props.count))
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
  <div class="pointer-events-none fixed inset-x-0 bottom-3 z-40 flex justify-center px-3 sm:bottom-5">
    <div class="pointer-events-auto flex max-w-full items-center gap-2 rounded-xl bg-default px-2.5 py-2 shadow-lg ring ring-default sm:gap-3 sm:px-3">
      <div class="flex min-w-0 items-center gap-2">
        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-elevated px-1.5 text-sm font-semibold text-highlighted tabular-nums">
          {{ countLabel }}
        </span>
        <p class="truncate text-sm text-muted">
          {{ noun }}
        </p>
      </div>

      <div class="flex shrink-0 items-center gap-1">
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

        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="disabled"
          aria-label="Cancelar seleção"
          @click="emit('clear')"
        />
      </div>
    </div>
  </div>
</template>
