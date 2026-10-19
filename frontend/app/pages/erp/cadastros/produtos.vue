<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpProductCatalogForm from '~/components/erp/catalogs/ErpProductCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus, formatErpDecimal } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Produtos · Cadastros ERP' })

const recordLabel = (record: Record<string, unknown>) => String(record.name ?? '')
const columns = [
  { accessorKey: 'name', header: 'Nome' },
  { accessorKey: 'sku', header: 'SKU' },
  { accessorKey: 'kind', header: 'Tipo', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.kind === 'service' ? 'Serviço' : 'Mercadoria' },
  { accessorKey: 'sale_price', header: 'Preço', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimal(row.original.sale_price as string | number | null) },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
</script>

<template>
  <ErpCatalogPageShell title="Produtos" description="Mercadorias, serviços e suas variações.">
    <ErpCatalogResource
      :path="erpCatalogRoutes.products"
      title="Produtos"
      :show-heading="false"
      search-placeholder="Nome, SKU ou código de barras"
      :form="ErpProductCatalogForm"
      :columns="columns"
      :record-label="recordLabel"
    />
  </ErpCatalogPageShell>
</template>
