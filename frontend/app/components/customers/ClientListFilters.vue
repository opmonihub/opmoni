<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/Filter.vue'
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

const tagMenuItems = [[{
  label: 'Criar tag',
  icon: 'i-lucide-plus',
  onSelect: () => emit('open-tags', 'catalog', true)
}, {
  label: 'Gerenciar tags',
  icon: 'i-lucide-library',
  onSelect: () => emit('open-tags', 'catalog')
}]] satisfies DropdownMenuItem[][]
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex items-start gap-2 md:hidden">
      <DataTableStatusChips class="flex-1" :items="mobileStatusItems" :active="documentStatus" />
      <CustomersSavedFilters
        v-if="documentStatus === 'all'"
        compact
        :search="search"
        :filters="filterModels"
        @apply="emit('apply-saved-filter', $event)"
      />
      <UDropdownMenu
        v-if="canManageClients"
        :items="tagMenuItems"
        :content="{ align: 'end' }"
      >
        <UButton
          icon="i-lucide-tags"
          color="neutral"
          variant="outline"
          aria-label="Opções de tags"
        />
      </UDropdownMenu>
    </div>

    <DataTableFilter
      :columns="filterColumns"
      :model-value="filterModels"
      :disabled="isLoading"
      class="min-w-0"
      @update:model-value="emit('update:filterModels', $event)"
    >
      <UInput
        v-model="searchModel"
        icon="i-lucide-search"
        placeholder="Buscar por nome, CPF/CNPJ ou e-mail..."
        class="min-w-0 flex-1"
        :disabled="isLoading"
      />
      <template #trailing>
        <div class="ml-auto flex shrink-0 items-center gap-1.5">
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

          <DataTableColumnMenu v-model="columnVisibilityModel" :columns="hideableColumns" />
        </div>
      </template>
    </DataTableFilter>
  </div>
</template>
