<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpOperationNatureCatalogForm from '~/components/erp/catalogs/ErpOperationNatureCatalogForm.vue'
import ErpTaxRateCatalogForm from '~/components/erp/catalogs/ErpTaxRateCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus, formatErpDecimal } from '#shared/utils/erpCatalogs'
import type { ComponentPublicInstance } from 'vue'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Referências fiscais · Cadastros ERP' })

const taxRateColumns = [
  { accessorKey: 'name', header: 'Nome' },
  { accessorKey: 'rate', header: 'Alíquota', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimal(row.original.rate as string | number | null) },
  { accessorKey: 'tax_kind', header: 'Tipo' },
  { accessorKey: 'erp_establishment_id', header: 'Estabelecimento', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.erp_establishment_id ? `#${row.original.erp_establishment_id}` : 'Todos' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const natureColumns = [
  { accessorKey: 'cfop', header: 'CFOP' },
  { accessorKey: 'description', header: 'Descrição' },
  { accessorKey: 'movement', header: 'Movimento', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.movement === 'in' ? 'Entrada' : 'Saída' },
  { accessorKey: 'erp_establishment_id', header: 'Estabelecimento', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.erp_establishment_id ? `#${row.original.erp_establishment_id}` : 'Todos' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const taxRateLabel = (record: Record<string, unknown>) => String(record.name ?? '')
const natureLabel = (record: Record<string, unknown>) => String(record.cfop ?? '')
type CatalogResourceHandle = InstanceType<typeof ErpCatalogResource>
type CatalogCreateAction = CatalogResourceHandle['createAction']
const taxRateResource = ref<CatalogResourceHandle | null>(null)
const natureResource = ref<CatalogResourceHandle | null>(null)
const createMenuTrigger = ref<ComponentPublicInstance | null>(null)
let pendingCreateFocusAction: CatalogCreateAction | null = null
const createActions = computed(() => [taxRateResource.value?.createAction, natureResource.value?.createAction]
  .filter((action): action is CatalogCreateAction => !!action && action.allowed))
function handoffCreateMenuFocus(event: Event) {
  const action = pendingCreateFocusAction
  if (!action)
    return
  pendingCreateFocusAction = null
  event.preventDefault()
}
const createMenuItems = computed(() => [createActions.value.map(action => ({
  label: action.label,
  disabled: action.busy,
  onSelect: () => {
    if (action.trigger(createMenuTrigger.value?.$el as HTMLElement | undefined))
      pendingCreateFocusAction = action
  }
}))])
</script>

<template>
  <ErpCatalogPageShell title="Referências fiscais" description="Alíquotas e naturezas de operação por empresa e estabelecimento.">
    <template #actions>
      <UDropdownMenu v-if="createActions.length" :items="createMenuItems" :content="{ onCloseAutoFocus: handoffCreateMenuFocus }">
        <UButton ref="createMenuTrigger" aria-label="Adicionar cadastro fiscal" icon="i-lucide-plus">
          <span class="hidden sm:inline">Adicionar</span>
        </UButton>
      </UDropdownMenu>
    </template>
    <ErpCatalogResource
      ref="taxRateResource"
      :path="erpCatalogRoutes.taxRates"
      title="Alíquotas"
      :form="ErpTaxRateCatalogForm"
      :columns="taxRateColumns"
      :record-label="taxRateLabel"
    />
    <ErpCatalogResource
      ref="natureResource"
      :path="erpCatalogRoutes.operationNatures"
      title="Naturezas de operação"
      :form="ErpOperationNatureCatalogForm"
      :columns="natureColumns"
      :record-label="natureLabel"
    />
  </ErpCatalogPageShell>
</template>
