<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpBrandCatalogForm from '~/components/erp/catalogs/ErpBrandCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Marcas · Cadastros ERP' })

const columns = [
  { accessorKey: 'name', header: 'Marca' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const recordLabel = (record: Record<string, unknown>) => String(record.name ?? '')
</script>

<template>
  <ErpCatalogPageShell title="Marcas" description="Marcas comerciais disponíveis para os produtos da empresa.">
    <ErpCatalogResource
      :path="erpCatalogRoutes.brands"
      title="Marcas"
      search-placeholder="Buscar marca"
      :form="ErpBrandCatalogForm"
      :columns="columns"
      :record-label="recordLabel"
    />
  </ErpCatalogPageShell>
</template>
