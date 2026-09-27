<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { sheetBodyClass, sheetTableUi, sheetToolbarUi } from '~/components/data-table/sheet'
import type { MonitoringClient, MonitoringSituacao } from '~/types/serpro'
import type { MonitoringObligation } from '~/utils/monitoringNav'

const props = defineProps<{
  obligation: MonitoringObligation
  situacao: MonitoringSituacao | null
}>()

const search = ref('')

const rows: MonitoringClient[] = []

const columns = computed<TableColumn<MonitoringClient>[]>(() =>
  props.obligation.columns.map(column => ({
    accessorKey: column.id,
    header: column.header
  }))
)

const detailFields = computed(() =>
  props.obligation.columns.filter(column => column.id !== 'name' && column.id !== 'situacao')
)

function fieldValue(row: MonitoringClient, id: string) {
  if (id === 'name') return row.name
  if (id === 'situacao') return row.situacao
  const value = row.fields[id]
  return value == null || value === '' ? '—' : String(value)
}

const hasQuery = computed(() => search.value.trim() !== '')
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <UDashboardToolbar
      class="hidden min-w-0 md:flex"
      :ui="sheetToolbarUi"
    />

    <div :class="sheetBodyClass">
      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Buscar por nome ou CNPJ"
        class="max-w-md"
        aria-label="Buscar por nome ou CNPJ"
      />

      <UEmpty
        v-if="rows.length === 0"
        :icon="hasQuery ? 'i-lucide-search-x' : 'i-lucide-inbox'"
        :title="hasQuery ? 'Nenhum cliente encontrado' : 'Nenhum cliente nesta lista'"
        :description="hasQuery ? 'Ajuste a busca ou escolha outra situação.' : 'Esta lista ainda não tem clientes.'"
        variant="naked"
      />

      <template v-else>
        <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto md:hidden">
          <UCard v-for="row in rows" :key="row.client_id" :ui="{ body: 'p-3 sm:p-4' }">
            <div class="flex items-start justify-between gap-3">
              <DataTableIdentity :title="row.name" :meta="row.tax_id ?? ''" />
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
              <div v-for="field in detailFields" :key="field.id" class="min-w-0">
                <dt class="text-xs text-muted">
                  {{ field.header }}
                </dt>
                <dd class="truncate text-sm text-default tabular-nums">
                  {{ fieldValue(row, field.id) }}
                </dd>
              </div>
            </dl>
          </UCard>
        </div>

        <div class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex">
          <UTable
            sticky
            :data="rows"
            :columns="columns"
            class="h-full min-h-0 w-full flex-1"
            :ui="sheetTableUi"
          >
            <template #name-cell="{ row }">
              <DataTableIdentity :title="row.original.name" :meta="row.original.tax_id ?? ''" />
            </template>
          </UTable>
        </div>
      </template>
    </div>
  </div>
</template>
