<script setup lang="ts" generic="T extends object">
import { computed, ref, useSlots } from 'vue'
import type { TableColumn, TableProps } from '@nuxt/ui'
import { panelTableUi } from '~/components/data-table/panel'

const props = withDefaults(defineProps<{
  data: T[]
  columns: TableColumn<T>[]
  loading?: boolean
  label?: string
  compact?: boolean
  expandable?: boolean
  ui?: TableProps<T>['ui']
}>(), { loading: false, label: 'Registros', compact: false, expandable: false, ui: undefined })

const slots = useSlots()
const visibility = ref<Record<string, boolean>>({})
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
  <div class="min-w-0">
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
        'max-w-full rounded-lg focus-visible:outline-2 focus-visible:outline-primary',
        compact ? 'hidden md:block overflow-x-hidden' : 'overflow-x-auto'
      ]"
      role="region"
      :aria-label="label"
      tabindex="0"
    >
      <UTable
        v-model:column-visibility="visibility"
        :data="data"
        :columns="tableColumns"
        :loading="loading"
        :expanded-options="expandable ? { getRowCanExpand: () => true } : undefined"
        :ui="{
          ...panelTableUi,
          root: 'overflow-visible',
          base: compact ? 'w-full min-w-0 table-fixed' : 'w-full min-w-[36rem] table-auto',
          th: compact ? `${panelTableUi.th} px-2 whitespace-normal text-xs sm:px-3` : `${panelTableUi.th} whitespace-nowrap`,
          td: compact ? `${panelTableUi.td} px-2 py-2 align-middle sm:px-3` : 'whitespace-nowrap py-2 align-middle tabular-nums',
          ...ui
        }"
      >
        <template v-for="(_, name) in slots" #[name]="slotData">
          <slot :name="name" v-bind="slotData" />
        </template>
      </UTable>
    </div>
    <div v-if="compact" class="divide-y divide-default md:hidden" :aria-label="label">
      <slot name="mobile" :data="data" />
    </div>
  </div>
</template>
