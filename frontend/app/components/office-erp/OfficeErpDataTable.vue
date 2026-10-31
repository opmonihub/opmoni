<script setup lang="ts" generic="T extends object">
import { computed, onMounted, ref, useSlots, watch } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import type { ExpandedState, SortingState } from '@tanstack/table-core'
import type { TableColumn, TableProps } from '@nuxt/ui'
import { companyListTableUi } from '~/components/office-erp/companyList'

const props = withDefaults(defineProps<{
  data: T[]
  columns: TableColumn<T>[]
  loading?: boolean
  label?: string
  compact?: boolean
  expandable?: boolean
  mobileColumns?: string[]
  getRowId?: TableProps<T>['getRowId']
  sorting?: SortingState
  ui?: TableProps<T>['ui']
}>(), { loading: false, label: 'Registros', compact: false, expandable: false, ui: undefined })

const slots = useSlots()
const forwardedSlots = computed(() => Object.fromEntries(Object.entries(slots).filter(([name]) => name !== 'empty')))
const emit = defineEmits<{ 'update:sorting': [value: SortingState | undefined] }>()
const visibility = ref<Record<string, boolean>>({})
const expanded = ref<ExpandedState>({})
const mounted = ref(false)
const narrow = useMediaQuery('(max-width: 767px)')
onMounted(() => {
  mounted.value = true
})
const responsiveVisibility = computed({
  get: () => {
    if (!props.compact || !props.mobileColumns || !mounted.value || !narrow.value) return visibility.value
    return Object.fromEntries(props.columns.map((column) => {
      const id = column.id ?? ('accessorKey' in column ? String(column.accessorKey) : '')
      return [id, props.mobileColumns!.includes(id) || column.enableSorting === true || column.enableHiding === false]
    }))
  },
  set: (value: Record<string, boolean>) => { visibility.value = value }
})
watch(() => props.data, (rows) => {
  const ids = new Set(rows.map((row, index) => props.getRowId?.(row, index) ?? String(index)))
  expanded.value = expanded.value === true ? {} : Object.fromEntries(Object.entries(expanded.value).filter(([id]) => ids.has(id)))
})
const tableColumns = computed(() => props.columns.map((column, index) => ({
  ...column,
  enableHiding: index !== 0 && column.id !== 'actions' && column.enableHiding !== false
})))
const columnOptions = computed(() => tableColumns.value.flatMap((column) => {
  const id = column.id ?? ('accessorKey' in column ? String(column.accessorKey) : '')
  if (!column.enableHiding || !id || typeof column.header !== 'string' || !column.header) return []
  return [{
    label: column.header,
    type: 'checkbox' as const,
    checked: visibility.value[id] !== false,
    onUpdateChecked(checked: boolean) { visibility.value = { ...visibility.value, [id]: checked } },
    onSelect(event: Event) { event.preventDefault() }
  }]
}))
</script>

<template>
  <div class="min-w-0 max-w-full" :aria-busy="loading">
    <div v-if="!compact" class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <p class="flex items-center gap-1.5 text-xs text-muted sm:hidden">
        <UIcon name="i-lucide-arrow-left-right" class="size-3.5 shrink-0" />
        Deslize para ver todas as colunas
      </p>
      <UDropdownMenu
        v-if="columnOptions.length"
        :items="columnOptions"
        :content="{ align: 'end' }"
        class="ml-auto"
      >
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-lucide-columns-3"
          label="Colunas"
          aria-label="Escolher colunas da tabela"
        />
      </UDropdownMenu>
    </div>
    <div
      :class="[
        'max-w-full overflow-x-auto focus-visible:outline-2 focus-visible:outline-primary'
      ]"
      role="region"
      :aria-label="label"
      tabindex="0"
    >
      <UTable
        v-model:column-visibility="responsiveVisibility"
        v-model:expanded="expanded"
        :data="data"
        :columns="tableColumns"
        :get-row-id="getRowId"
        :loading="loading"
        :sorting="sorting"
        :sorting-options="sorting ? { manualSorting: true } : undefined"
        :expanded-options="expandable ? { getRowCanExpand: () => true } : undefined"
        :ui="{
          ...companyListTableUi,
          root: 'overflow-visible',
          base: compact ? companyListTableUi.base : 'w-full min-w-[36rem] table-auto',
          ...ui
        }"
        @update:sorting="emit('update:sorting', $event)"
      >
        <template #empty>
          <slot v-if="!loading" name="empty">
            Nenhum registro nesta página.
          </slot>
          <span v-else role="status">Carregando registros…</span>
        </template>
        <template v-for="(_, name) in forwardedSlots" #[name]="slotData">
          <slot :name="name" v-bind="slotData" />
        </template>
      </UTable>
    </div>
  </div>
</template>
