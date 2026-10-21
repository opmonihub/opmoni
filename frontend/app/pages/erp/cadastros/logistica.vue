<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpCarrierCatalogForm from '~/components/erp/catalogs/ErpCarrierCatalogForm.vue'
import ErpDriverCatalogForm from '~/components/erp/catalogs/ErpDriverCatalogForm.vue'
import ErpVehicleCatalogForm from '~/components/erp/catalogs/ErpVehicleCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Logística · Cadastros ERP' })

const carrierColumns = [
  { accessorKey: 'legal_name', header: 'Razão social' },
  { accessorKey: 'tax_id', header: 'CNPJ/CPF' },
  { accessorKey: 'ie', header: 'Inscrição estadual' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const vehicleColumns = [
  { accessorKey: 'plate', header: 'Placa' },
  { accessorKey: 'model', header: 'Modelo' },
  { accessorKey: 'brand', header: 'Marca' },
  { accessorKey: 'uf', header: 'UF' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const driverColumns = [
  { accessorKey: 'name', header: 'Nome' },
  { accessorKey: 'tax_id', header: 'CPF' },
  { accessorKey: 'license_number', header: 'CNH' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const carrierLabel = (record: Record<string, unknown>) => String(record.legal_name ?? '')
const vehicleLabel = (record: Record<string, unknown>) => String(record.plate ?? '')
const driverLabel = (record: Record<string, unknown>) => String(record.name ?? '')
type CatalogResourceHandle = InstanceType<typeof ErpCatalogResource>
type CatalogCreateAction = CatalogResourceHandle['createAction']
const carrierResource = ref<CatalogResourceHandle | null>(null)
const vehicleResource = ref<CatalogResourceHandle | null>(null)
const driverResource = ref<CatalogResourceHandle | null>(null)
const createActions = computed(() => [carrierResource.value?.createAction, vehicleResource.value?.createAction, driverResource.value?.createAction]
  .filter((action): action is CatalogCreateAction => !!action && action.allowed))
const createMenuItems = computed(() => [createActions.value.map(action => ({ label: action.label, disabled: action.busy, onSelect: action.trigger }))])
</script>

<template>
  <ErpCatalogPageShell title="Logística" description="Transportadoras, veículos e motoristas para operações de transporte.">
    <template #actions>
      <UDropdownMenu v-if="createActions.length" :items="createMenuItems">
        <UButton aria-label="Adicionar cadastro de logística" icon="i-lucide-plus">
          <span class="hidden sm:inline">Adicionar</span>
        </UButton>
      </UDropdownMenu>
    </template>
    <ErpCatalogResource
      ref="carrierResource"
      :path="erpCatalogRoutes.carriers"
      title="Transportadoras"
      :form="ErpCarrierCatalogForm"
      :columns="carrierColumns"
      :record-label="carrierLabel"
    />
    <ErpCatalogResource
      ref="vehicleResource"
      :path="erpCatalogRoutes.vehicles"
      title="Veículos"
      :form="ErpVehicleCatalogForm"
      :columns="vehicleColumns"
      :record-label="vehicleLabel"
    />
    <ErpCatalogResource
      ref="driverResource"
      :path="erpCatalogRoutes.drivers"
      title="Motoristas"
      :form="ErpDriverCatalogForm"
      :columns="driverColumns"
      :record-label="driverLabel"
    />
  </ErpCatalogPageShell>
</template>
