<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type Row = { id: number, name: string, status: string }
const { search, page, pending, state, error, refresh } = useErpCatalogList<Row>(erpCatalogRoutes.brands)
</script>

<template>
  <ErpCatalogPageShell title="Marcas">
    <UInput v-model="search" placeholder="Buscar" class="max-w-md" />
    <UTable :loading="pending" :data="state?.data ?? []" :columns="[{ accessorKey: 'name', header: 'Marca' }, { accessorKey: 'status', header: 'Status' }]" />
    <ErpCatalogPagination
      v-model:page="page"
      :meta="state?.meta"
      :pending="pending"
      :error="error"
      @retry="refresh"
    />
  </ErpCatalogPageShell>
</template>
