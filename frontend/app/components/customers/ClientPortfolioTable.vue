<script setup lang="ts">
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import type { ComponentPublicInstance } from 'vue'
import { useInfiniteScroll } from '@vueuse/core'
import DataTableSortButton from '~/components/data-table/SortButton.vue'
import { sheetTableUi } from '~/components/data-table/sheet'
import type { ClientListParams, ClientSheet } from '~/types/client'
import {
  clientSheetTaxIdLabel,
  clientStatusPresentation,
  taxRegimeLabel
} from '~/utils/portfolioLabels'

type SortKey = ClientListParams['sort']

const props = defineProps<{
  rows: ClientSheet[]
  isLoading: boolean
  loadingMore: boolean
  canManageClients: boolean
  selectedCount: number
  canLoadMore: boolean
  columnVisibility: Record<string, boolean>
  rowSelection: Record<string, boolean>
  headerState: boolean | 'indeterminate'
  selectingAll: boolean
  matchingTotal: number
  sort: SortKey
  direction: 'asc' | 'desc'
  rowActions: (client: ClientSheet) => DropdownMenuItem[]
}>()

const emit = defineEmits<{
  'update:columnVisibility': [value: Record<string, boolean>]
  'update:rowSelection': [value: Record<string, boolean>]
  'load-more': []
  'header-toggle': [value: boolean | 'indeterminate']
  'toggle-sort': [key: SortKey]
  'open-certificate': [client: ClientSheet]
  'open-power-of-attorney': [client: ClientSheet]
  'remember-focus': []
}>()

const UCheckbox = resolveComponent('UCheckbox')
const table = useTemplateRef<ComponentPublicInstance>('table')

const columnVisibilityModel = computed({
  get: () => props.columnVisibility,
  set: (value: Record<string, boolean>) => emit('update:columnVisibility', value)
})

const rowSelectionModel = computed({
  get: () => props.rowSelection,
  set: (value: Record<string, boolean>) => emit('update:rowSelection', value)
})

function sortableHeader(label: string, key: SortKey) {
  return h(DataTableSortButton, {
    label,
    sorted: props.sort === key ? props.direction : false,
    onToggle: () => emit('toggle-sort', key)
  })
}

const columns = computed<TableColumn<ClientSheet>[]>(() => {
  const cols: TableColumn<ClientSheet>[] = []

  if (props.canManageClients) {
    cols.push({
      id: 'select',
      enableHiding: false,
      meta: { class: { th: 'w-10', td: 'w-10' } },
      header: () => h(UCheckbox, {
        'modelValue': props.headerState,
        'disabled': props.selectingAll || props.matchingTotal === 0,
        'onUpdate:modelValue': (value: boolean | 'indeterminate') => {
          emit('header-toggle', value)
        },
        'ariaLabel': 'Selecionar todos os clientes filtrados'
      }),
      cell: ({ row }) => h(UCheckbox, {
        'modelValue': row.getIsSelected(),
        'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
        'ariaLabel': `Selecionar ${row.original.name}`
      })
    })
  }

  cols.push(
    {
      accessorKey: 'name',
      header: () => sortableHeader('Nome/Razão social', 'name'),
      meta: { class: { th: 'min-w-52 whitespace-nowrap', td: 'max-w-0' } }
    },
    {
      id: 'tags',
      header: 'Tags',
      meta: { class: { th: 'min-w-28 whitespace-nowrap', td: 'max-w-0' } }
    },
    {
      accessorKey: 'tax_regime',
      header: () => sortableHeader('Regime', 'tax_regime'),
      meta: { class: { th: 'min-w-28 whitespace-nowrap', td: 'max-w-0' } }
    },
    {
      accessorKey: 'status',
      header: () => sortableHeader('Situação', 'status'),
      meta: { class: { th: 'min-w-28 whitespace-nowrap', td: 'max-w-0' } }
    },
    {
      id: 'certificate',
      header: () => sortableHeader('Cert. A1', 'certificate'),
      meta: { class: { th: 'min-w-28 whitespace-nowrap', td: 'max-w-0' } }
    },
    {
      id: 'ecac_power_of_attorney',
      header: () => sortableHeader('e-CAC', 'poa'),
      meta: { class: { th: 'min-w-24 whitespace-nowrap', td: 'max-w-0' } }
    },
    {
      id: 'actions',
      enableHiding: false,
      meta: { class: { th: 'w-12', td: 'w-12' } }
    }
  )

  return cols
})

onMounted(() => {
  useInfiniteScroll(
    computed(() => table.value?.$el ?? null),
    () => emit('load-more'),
    {
      distance: 200,
      canLoadMore: () => props.canLoadMore
    }
  )
})
</script>

<template>
  <div
    class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex"
    :class="canManageClients && selectedCount ? 'pb-16' : ''"
  >
    <UTable
      ref="table"
      v-model:row-selection="rowSelectionModel"
      v-model:column-visibility="columnVisibilityModel"
      sticky
      :virtualize="{ estimateSize: 53, overscan: 16 }"
      :watch-options="{ deep: false }"
      :get-row-id="(row: ClientSheet) => String(row.id)"
      :data="rows"
      :columns="columns"
      :loading="isLoading || loadingMore"
      class="h-full min-h-0 w-full flex-1"
      :ui="sheetTableUi"
    >
      <template #name-cell="{ row }">
        <DataTableIdentity :title="row.original.name" :meta="clientSheetTaxIdLabel(row.original)" />
      </template>

      <template #tags-cell="{ row }">
        <CustomersClientTags v-if="row.original.tags?.length" collapse :tags="row.original.tags" />
      </template>

      <template #tax_regime-cell="{ row }">
        <span
          class="block truncate"
          :title="row.original.tax_regime ? (taxRegimeLabel[row.original.tax_regime] ?? row.original.tax_regime) : undefined"
        >
          {{ row.original.tax_regime ? (taxRegimeLabel[row.original.tax_regime] ?? row.original.tax_regime) : '—' }}
        </span>
      </template>

      <template #status-cell="{ row }">
        <UBadge
          class="max-w-full"
          :color="clientStatusPresentation[row.original.status]?.color ?? 'neutral'"
          :icon="clientStatusPresentation[row.original.status]?.icon"
          variant="subtle"
          :label="clientStatusPresentation[row.original.status]?.label ?? row.original.status"
          :ui="{ base: 'max-w-full', label: 'truncate' }"
        />
      </template>

      <template #certificate-cell="{ row }">
        <CustomersDocumentStatus
          :status="row.original.certificate_status"
          :value="row.original.certificate?.valid_until"
          kind="certificate"
          :actionable="canManageClients"
          @action="emit('open-certificate', row.original)"
        />
      </template>

      <template #ecac_power_of_attorney-cell="{ row }">
        <CustomersDocumentStatus
          :status="row.original.ecac_power_of_attorney_status"
          :value="row.original.ecac_power_of_attorney?.expires_at"
          kind="poa"
          :actionable="canManageClients"
          @action="emit('open-power-of-attorney', row.original)"
        />
      </template>

      <template #actions-cell="{ row }">
        <div class="text-right">
          <UDropdownMenu :items="rowActions(row.original)" :content="{ align: 'end' }">
            <UButton
              icon="i-lucide-ellipsis-vertical"
              color="neutral"
              variant="ghost"
              :aria-label="`Ações para ${row.original.name}`"
              @click="emit('remember-focus')"
            />
          </UDropdownMenu>
        </div>
      </template>
    </UTable>
  </div>
</template>
