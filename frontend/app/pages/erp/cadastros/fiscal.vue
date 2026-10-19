<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpOperationNatureCatalogForm from '~/components/erp/catalogs/ErpOperationNatureCatalogForm.vue'
import ErpTaxRateCatalogForm from '~/components/erp/catalogs/ErpTaxRateCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus, formatErpDecimal } from '#shared/utils/erpCatalogs'

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
</script>

<template>
  <ErpCatalogPageShell title="Referências fiscais" description="Alíquotas e naturezas de operação por empresa e estabelecimento.">
    <ErpCatalogResource
      :path="erpCatalogRoutes.taxRates"
      title="Alíquotas"
      :form="ErpTaxRateCatalogForm"
      :columns="taxRateColumns"
      :record-label="taxRateLabel"
    />
    <ErpCatalogResource
      :path="erpCatalogRoutes.operationNatures"
      title="Naturezas de operação"
      :form="ErpOperationNatureCatalogForm"
      :columns="natureColumns"
      :record-label="natureLabel"
    />
  </ErpCatalogPageShell>
</template>
