<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/filter-model'
import { toStoredClientFilters, toPanelClientFilters } from '~/utils/clientFilterPanel'
import { toPanelColumns } from '~/utils/filterPanel'
import type { DeadlineStatus } from '~/types/client'

const props = defineProps<{
  documentStatus: string
  search: string
  filterColumns: DataTableFilterColumn[]
  filterModels: DataTableFilterModel[]
  mobileStatusItems: Array<{
    label: string
    value: DeadlineStatus | 'all'
    to: string
    count?: number
  }>
  canManageClients: boolean
  isLoading: boolean
  selectedCount: number
  desktopTable: boolean
  rowsLength: number
  columnVisibility: Record<string, boolean>
  selectionMenu: DropdownMenuItem[][]
  hideableColumns: Array<{ id: string, label: string }>
}>()

const emit = defineEmits<{
  'update:search': [value: string]
  'update:filterModels': [value: DataTableFilterModel[]]
  'update:columnVisibility': [value: Record<string, boolean>]
  'apply-saved-filter': [value: { q: string, filters: DataTableFilterModel[] }]
  'open-tags': [intent: 'selection' | 'catalog', focusCreate?: boolean]
  'export': []
}>()

const searchModel = computed({
  get: () => props.search,
  set: (value: string) => emit('update:search', value)
})

const columnVisibilityModel = computed({
  get: () => props.columnVisibility,
  set: (value: Record<string, boolean>) => emit('update:columnVisibility', value)
})

const panelColumns = computed(() => toPanelColumns(props.filterColumns, { operators: true }))
const panelModels = computed(() => toPanelClientFilters(props.filterModels))

function onPanelFilters(models: DataTableFilterModel[]) {
  emit('update:filterModels', toStoredClientFilters(models))
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <!-- Mobile only: the counters are one scrolling line, and the saved-filters
         and tags menu moved down to the search row so it stops taking the width
         the counters need. From `md` the list toolbar carries it instead. -->
    <DataTableStatusChips
      class="md:hidden"
      :items="mobileStatusItems"
      :active="documentStatus"
    />

    <DataTableFilterPanel
      :columns="panelColumns"
      :model-value="panelModels"
      :disabled="isLoading"
      class="min-w-0"
      @update:model-value="onPanelFilters"
    >
      <UInput
        v-model="searchModel"
        icon="i-lucide-search"
        placeholder="Buscar por nome, CPF/CNPJ ou e-mail..."
        class="w-full min-w-0 flex-1"
        :disabled="isLoading"
      />
      <template #trailing>
        <div class="ml-auto flex shrink-0 items-center gap-1.5">
          <CustomersClientListMenu
            compact
            class="shrink-0 md:hidden"
            :search="search"
            :filters="filterModels"
            :show-saved="documentStatus === 'all'"
            :can-manage-tags="canManageClients"
            @apply="emit('apply-saved-filter', $event)"
            @open-tags="(intent, focusCreate) => emit('open-tags', intent, focusCreate)"
          />

          <UDropdownMenu
            v-if="selectedCount"
            :items="selectionMenu"
            :content="{ align: 'end' }"
          >
            <UButton
              :label="desktopTable ? 'Seleção' : undefined"
              icon="i-lucide-list-checks"
              color="neutral"
              variant="subtle"
              class="shrink-0"
              aria-label="Ações da seleção"
            >
              <template #trailing>
                <UKbd>{{ selectedCount }}</UKbd>
              </template>
            </UButton>
          </UDropdownMenu>

          <UButton
            :label="desktopTable ? 'Exportar' : undefined"
            icon="i-lucide-file-spreadsheet"
            color="neutral"
            variant="outline"
            class="shrink-0"
            aria-label="Exportar para Excel"
            :disabled="isLoading || !rowsLength"
            @click="emit('export')"
          />

          <!-- The mobile list is cards, so there are no columns to hide. -->
          <DataTableColumnMenu
            v-model="columnVisibilityModel"
            :columns="hideableColumns"
            class="hidden md:flex"
          />
        </div>
      </template>
    </DataTableFilterPanel>
  </div>
</template>
