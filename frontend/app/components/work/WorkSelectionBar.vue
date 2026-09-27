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

const countLabel = computed(() => new Intl.NumberFormat('pt-BR').format(props.count))
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
  <div class="flex max-w-full items-center gap-2 rounded-xl bg-default px-2.5 py-2 shadow-lg ring ring-default sm:gap-3 sm:px-3">
    <div class="flex min-w-0 items-center gap-2">
      <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-elevated px-1.5 text-sm font-semibold text-highlighted tabular-nums">
        {{ countLabel }}
      </span>
      <p class="truncate text-sm text-muted">
        {{ noun }}
      </p>
    </div>

    <div class="flex shrink-0 items-center gap-1">
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
</template>
