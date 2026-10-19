<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpCategoryCatalogForm from '~/components/erp/catalogs/ErpCategoryCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Categorias · Cadastros ERP' })

const columns = [
  { accessorKey: 'name', header: 'Categoria' },
  { accessorKey: 'parent_id', header: 'Categoria superior', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.parent_id ? `#${row.original.parent_id}` : '—' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const recordLabel = (record: Record<string, unknown>) => String(record.name ?? '')
</script>

<template>
  <ErpCatalogPageShell title="Categorias" description="Organize os produtos em uma hierarquia da própria empresa.">
    <ErpCatalogResource
      :path="erpCatalogRoutes.categories"
      title="Categorias"
      :form="ErpCategoryCatalogForm"
      :columns="columns"
      :record-label="recordLabel"
    />
  </ErpCatalogPageShell>
</template>
