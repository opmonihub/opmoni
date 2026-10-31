<script setup lang="ts">
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/filter-model'
import { toStoredClientFilters, toPanelClientFilters } from '~/utils/clientFilterPanel'
import { toPanelColumns } from '~/utils/filterPanel'

const props = defineProps<{
  search: string
  filterColumns: DataTableFilterColumn[]
  filterModels: DataTableFilterModel[]
  canManageClients: boolean
  isLoading: boolean
  desktopTable: boolean
  rowsLength: number
  columnVisibility: Record<string, boolean>
  hideableColumns: Array<{ id: string, label: string }>
}>()

const emit = defineEmits<{
  'update:search': [value: string]
  'update:filterModels': [value: DataTableFilterModel[]]
  'update:columnVisibility': [value: Record<string, boolean>]
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
  <div
    role="group"
    aria-label="Buscar e gerenciar clientes"
    class="flex min-w-0 shrink-0 flex-wrap items-center justify-between gap-1.5"
  >
    <UInput
      v-model="searchModel"
      icon="i-lucide-search"
      placeholder="Buscar por nome, CPF/CNPJ ou e-mail..."
      aria-label="Buscar clientes"
      color="neutral"
      variant="outline"
      class="min-w-0 w-full flex-1"
      :disabled="isLoading"
    >
      <template v-if="searchModel.trim()" #trailing>
        <UButton
          color="neutral"
          variant="link"
          size="sm"
          icon="i-lucide-x"
          aria-label="Limpar busca"
          :disabled="isLoading"
          @click="searchModel = ''"
        />
      </template>
    </UInput>
    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
      <DataTableFilterPanel
        :columns="panelColumns"
        :model-value="panelModels"
        :disabled="isLoading"
        class="w-auto! shrink-0 [&>div:first-child]:hidden [&>div:last-child]:hidden"
        @update:model-value="onPanelFilters"
      />

      <CustomersClientListMenu
        :compact="!desktopTable"
        class="shrink-0"
        :can-manage-tags="canManageClients"
        @open-tags="(intent, focusCreate) => emit('open-tags', intent, focusCreate)"
      />

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
  </div>
</template>
