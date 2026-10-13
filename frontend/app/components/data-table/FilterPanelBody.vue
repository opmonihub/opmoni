<script setup lang="ts">
import type { CommandPaletteGroup, CommandPaletteItem } from '@nuxt/ui'
import {
  operatorChoices,
  operatorDetail,
  operatorLabel,
  type DataTableFilterModel,
  type DataTableFilterOption,
  type DataTableFilterOperator
} from './filter-model'
import {
  columnTypeOf,
  formatFilterValue,
  type FilterPanelColumn,
  type FilterPanelDraft
} from '~/utils/filterPanel'

type ValueCommand = CommandPaletteItem & {
  value: string
  active?: boolean
}

type AppliedFilter = {
  filter: DataTableFilterModel
  column: FilterPanelColumn
}

const props = defineProps<{
  columns: FilterPanelColumn[]
  column: FilterPanelColumn | null
  draft: FilterPanelDraft | null
  pills: AppliedFilter[]
  selected: string[]
  error: string
  disabled?: boolean
  open: boolean
}>()

const emit = defineEmits<{
  pick: [columnId: string]
  back: []
  draft: [patch: Partial<FilterPanelDraft>]
  choice: [values: string[]]
  apply: []
  remove: [columnId: string]
  clear: []
  operator: [columnId: string, operator: DataTableFilterOperator]
}>()

const query = ref('')
const operatorId = ref<string | null>(null)
const optionItems = new Map<string, ValueCommand>()

const operatorColumn = computed(() => props.pills.find(item => item.column.id === operatorId.value) ?? null)

watch(() => props.open, (isOpen) => {
  if (isOpen) return
  query.value = ''
  operatorId.value = null
})

watch(() => props.column?.id, () => {
  query.value = ''
  operatorId.value = null
})

watch(() => props.pills.map(item => item.column.id).join('|'), () => {
  if (operatorId.value && !props.pills.some(item => item.column.id === operatorId.value)) {
    operatorId.value = null
  }
})

const columnGroups = computed<CommandPaletteGroup[]>(() => [{
  id: 'columns',
  items: props.columns.map(column => ({
    label: column.label,
    icon: column.icon,
    onSelect: (event: Event) => {
      event.preventDefault()
      emit('pick', column.id)
    }
  }))
}])

const operatorGroups = computed<CommandPaletteGroup[]>(() => {
  const current = operatorColumn.value
  if (!current) return []
  const type = columnTypeOf(current.column.control)
  const target = operatorDetail(type, current.filter.operator).target
  return [{
    id: 'operators',
    items: operatorChoices(type)
      .filter(item => item.target === target)
      .map(item => ({
        label: item.label,
        icon: item.value === current.filter.operator ? 'i-lucide-check' : undefined,
        onSelect: (event: Event) => {
          event.preventDefault()
          emit('operator', current.column.id, item.value)
          operatorId.value = null
        }
      }))
  }]
})

function toggle(value: string) {
  const column = props.column
  if (!column) return
  const current = props.selected
  if (column.control === 'multi') {
    const next = current.includes(value)
      ? current.filter(item => item !== value)
      : [...current, value]
    emit('choice', next)
    return
  }
  emit('choice', current[0] === value ? [] : [value])
}

function optionItem(columnId: string, option: DataTableFilterOption, selected: string[]) {
  const key = `${columnId}:${option.value}`
  const active = selected.includes(option.value)
  const existing = optionItems.get(key)
  if (existing) {
    existing.active = active
    existing.label = option.label
    return existing
  }
  const item: ValueCommand = {
    label: option.label,
    value: option.value,
    slot: 'value',
    active,
    onSelect: (event: Event) => {
      event.preventDefault()
      toggle(option.value)
    }
  }
  optionItems.set(key, item)
  return item
}

const optionGroups = computed<CommandPaletteGroup[]>(() => {
  const column = props.column
  if (!column) return []
  const selected = props.selected
  return [{
    id: column.id,
    items: (column.options ?? []).map(option => optionItem(column.id, option, selected))
  }]
})

const isChoice = computed(() => props.column?.control === 'select' || props.column?.control === 'multi')

const appliedItems = computed(() => props.pills.map(({ filter, column: applied }) => ({
  label: applied.label,
  icon: applied.icon,
  columnId: applied.id,
  operator: applied.operators
    ? operatorLabel(columnTypeOf(applied.control), filter.operator)
    : undefined,
  valueLabel: formatFilterValue(applied, filter),
  onSelect: (event: Event) => {
    event.preventDefault()
    emit('pick', applied.id)
  }
})))

function showOperator(columnId: string) {
  operatorId.value = operatorId.value === columnId ? null : columnId
}
</script>

<template>
  <div class="flex max-h-[min(24rem,70vh)] w-full flex-col overflow-y-auto">
    <template v-if="!column">
      <div v-if="pills.length">
        <UListbox
          :items="appliedItems"
          color="neutral"
          size="sm"
          :filter="false"
          :disabled="disabled"
          :ui="{
            root: 'flex-none rounded-none bg-transparent ring-0',
            content: 'max-h-none flex-none',
            group: 'p-1 pb-0',
            item: 'items-center'
          }"
        >
          <template #item="{ item }">
            <UIcon v-if="item.icon" :name="item.icon" class="size-4 shrink-0 text-muted" />
            <span class="shrink-0 text-sm font-medium text-highlighted">{{ item.label }}</span>
            <button
              v-if="item.operator"
              type="button"
              class="shrink-0 text-sm text-muted"
              @pointerdown.stop
              @click.stop.prevent="showOperator(item.columnId)"
            >
              {{ item.operator }}
            </button>
            <span v-if="item.valueLabel" class="min-w-0 flex-1 truncate text-sm text-muted">
              {{ item.valueLabel }}
            </span>
            <UButton
              icon="i-lucide-x"
              size="xs"
              color="neutral"
              variant="ghost"
              class="ms-auto"
              :disabled="disabled"
              :aria-label="`Remover filtro ${item.label}`"
              @pointerdown.stop
              @click.stop.prevent="emit('remove', item.columnId)"
            />
          </template>
        </UListbox>
        <div class="flex justify-end border-y border-default px-2 py-1">
          <UButton
            label="Limpar"
            icon="i-lucide-filter-x"
            size="xs"
            color="error"
            variant="ghost"
            :disabled="disabled"
            @click="emit('clear')"
          />
        </div>
      </div>

      <div v-if="operatorColumn" class="flex flex-col">
        <div class="flex items-center gap-1.5 border-b border-default px-2 py-1.5">
          <UButton
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            size="sm"
            aria-label="Voltar para os campos"
            @click="operatorId = null"
          />
          <span class="truncate text-sm font-medium text-highlighted">Operador</span>
        </div>
        <DataTableFilterMenu
          :groups="operatorGroups"
          placeholder="Buscar operador..."
        />
      </div>

      <DataTableFilterMenu
        v-else
        v-model:search-term="query"
        :groups="columnGroups"
        placeholder="Buscar campo..."
      />
    </template>

    <template v-else>
      <div class="flex items-center gap-1.5 border-b border-default px-2 py-1.5">
        <UButton
          icon="i-lucide-arrow-left"
          color="neutral"
          variant="ghost"
          size="sm"
          aria-label="Voltar para os campos"
          @click="emit('back')"
        />
        <UIcon v-if="column.icon" :name="column.icon" class="size-4 text-muted" />
        <span class="truncate text-sm font-medium text-highlighted">{{ column.label }}</span>
      </div>

      <DataTableFilterMenu
        v-if="isChoice"
        :groups="optionGroups"
        placeholder="Buscar valor..."
      />

      <DataTableFilterPanelValue
        v-else-if="draft"
        :column="column"
        :draft="draft"
        :error="error"
        :disabled="disabled"
        @draft="emit('draft', $event)"
        @apply="emit('apply')"
      />
    </template>
  </div>
</template>
