<script setup lang="ts">
import {
  operatorDetail,
  type DataTableFilterModel,
  type DataTableFilterOperator
} from './filter-model'
import {
  applyColumnDraft,
  columnTypeOf,
  draftError,
  draftsFromModel,
  emptyDraft,
  isDraftControl,
  withoutColumn,
  commitChoice,
  activeCount,
  type FilterPanelColumn,
  type FilterPanelDraft
} from '~/utils/filterPanel'

/**
 * Barra de filtro: o botão abre o painel. Os filtros aplicados ficam numa
 * lista dentro do dropdown, e o mesmo painel troca para o valor da coluna.
 * Select grava na hora; texto e intervalo só no Aplicar.
 */
const props = defineProps<{
  columns: FilterPanelColumn[]
  modelValue: DataTableFilterModel[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: DataTableFilterModel[]]
}>()

const open = ref(false)
const columnId = ref<string | null>(null)
const draft = ref<FilterPanelDraft | null>(null)
const openedFromValue = ref(false)
const isMobile = useClientMediaQuery('(max-width: 767px)')
let resetTimer = 0

const activeColumn = computed(() => props.columns.find(column => column.id === columnId.value) ?? null)

const selected = computed(() => {
  const id = columnId.value
  if (!id) return []
  return props.modelValue.find(filter => filter.columnId === id)?.values.map(String) ?? []
})

const error = computed(() => {
  const column = activeColumn.value
  if (!column || !draft.value || !isDraftControl(column.control)) return ''
  return draftError(column, draft.value) ?? ''
})

const pills = computed(() => props.modelValue.flatMap((filter) => {
  const column = props.columns.find(item => item.id === filter.columnId)
  return column ? [{ filter, column }] : []
}))

const count = computed(() => activeCount(props.modelValue))

function columnOf(id: string) {
  return props.columns.find(column => column.id === id)
}

function loadDraft(id: string) {
  const column = columnOf(id)
  if (!column) return
  draft.value = draftsFromModel(props.columns, props.modelValue)[id] ?? emptyDraft(column)
}

function showColumn(id: string) {
  window.clearTimeout(resetTimer)
  if (!columnOf(id)) return
  columnId.value = id
  loadDraft(id)
  if (open.value) return
  openedFromValue.value = true
  open.value = true
}

function backToColumns() {
  columnId.value = null
  draft.value = null
}

watch(open, (isOpen) => {
  window.clearTimeout(resetTimer)
  if (isOpen) {
    if (openedFromValue.value) {
      openedFromValue.value = false
      return
    }
    columnId.value = null
    draft.value = null
    return
  }
  resetTimer = window.setTimeout(() => {
    if (open.value) return
    columnId.value = null
    draft.value = null
  }, 150)
})

onBeforeUnmount(() => {
  window.clearTimeout(resetTimer)
})

function patchDraft(patch: Partial<FilterPanelDraft>) {
  if (!draft.value) return
  draft.value = { ...draft.value, ...patch }
}

function onChoice(chosen: string[]) {
  const column = activeColumn.value
  if (!column) return
  const current = props.modelValue.find(filter => filter.columnId === column.id)
  emit('update:modelValue', commitChoice(column, props.modelValue, chosen, current?.operator))
}

function applyCurrent() {
  const column = activeColumn.value
  if (!column || !draft.value || props.disabled) return
  const result = applyColumnDraft(column, props.modelValue, draft.value)
  if (!result.ok) return
  emit('update:modelValue', result.model)
  draft.value = draftsFromModel(props.columns, result.model)[column.id] ?? emptyDraft(column)
}

function onOperator(id: string, operator: DataTableFilterOperator) {
  const column = columnOf(id)
  const current = props.modelValue.find(filter => filter.columnId === id)
  if (!column || !current) return
  const type = columnTypeOf(column.control)
  const values = operatorDetail(type, operator).target === 'single'
    ? current.values.slice(0, 1)
    : current.values
  const next = props.modelValue.map(filter => filter.columnId === id
    ? { ...filter, operator, values }
    : filter)
  emit('update:modelValue', next)
  if (draft.value && columnId.value === id) draft.value = { ...draft.value, operator }
}

function clear() {
  emit('update:modelValue', [])
  const column = activeColumn.value
  if (column) draft.value = emptyDraft(column)
}

function remove(id: string) {
  emit('update:modelValue', withoutColumn(props.modelValue, id))
  if (columnId.value !== id) return
  const column = columnOf(id)
  if (column) draft.value = emptyDraft(column)
}
</script>

<template>
  <div class="flex w-full min-w-0 items-center gap-1.5">
    <div class="flex min-w-0 flex-1 items-center gap-1.5">
      <slot />
    </div>

    <UDrawer
      v-if="isMobile"
      v-model:open="open"
      title="Filtros"
      :handle="true"
      :ui="{ content: 'max-h-[calc(100dvh-1rem)]' }"
      class="shrink-0"
    >
      <UButton
        label="Filtros"
        icon="i-lucide-list-filter"
        color="neutral"
        :variant="count > 0 ? 'subtle' : 'outline'"
        :disabled="disabled"
        class="shrink-0"
      >
        <template #trailing>
          <UBadge
            v-if="count > 0"
            :label="String(count)"
            color="primary"
            variant="subtle"
            size="sm"
          />
        </template>
      </UButton>
      <template #body>
        <DataTableFilterPanelBody
          :columns="columns"
          :column="activeColumn"
          :draft="draft"
          :pills="pills"
          :selected="selected"
          :error="error"
          :disabled="disabled"
          :open="open"
          @pick="showColumn"
          @back="backToColumns"
          @draft="patchDraft"
          @choice="onChoice"
          @apply="applyCurrent"
          @remove="remove"
          @clear="clear"
          @operator="onOperator"
        />
      </template>
    </UDrawer>

    <UPopover
      v-else
      v-model:open="open"
      :content="{ align: 'end', side: 'bottom', sideOffset: 8, collisionPadding: 8 }"
      :ui="{ content: 'z-50 w-72 overflow-hidden p-0' }"
      class="shrink-0"
    >
      <UButton
        label="Filtros"
        icon="i-lucide-list-filter"
        color="neutral"
        :variant="open || count > 0 ? 'subtle' : 'outline'"
        trailing-icon="i-lucide-chevron-down"
        :ui="{ trailingIcon: ['transition-transform duration-200', open ? 'rotate-180' : ''].join(' ') }"
        :disabled="disabled"
        class="shrink-0"
        :aria-expanded="open"
      >
        <template v-if="count > 0" #trailing>
          <UBadge
            :label="String(count)"
            color="primary"
            variant="subtle"
            size="sm"
          />
          <UIcon
            name="i-lucide-chevron-down"
            class="size-5 shrink-0 transition-transform duration-200"
            :class="open ? 'rotate-180' : ''"
          />
        </template>
      </UButton>
      <template #content>
        <DataTableFilterPanelBody
          :columns="columns"
          :column="activeColumn"
          :draft="draft"
          :pills="pills"
          :selected="selected"
          :error="error"
          :disabled="disabled"
          :open="open"
          @pick="showColumn"
          @back="backToColumns"
          @draft="patchDraft"
          @choice="onChoice"
          @apply="applyCurrent"
          @remove="remove"
          @clear="clear"
          @operator="onOperator"
        />
      </template>
    </UPopover>

    <div class="shrink-0">
      <slot name="trailing" />
    </div>
  </div>
</template>
