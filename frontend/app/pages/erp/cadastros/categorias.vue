<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type Row = { id: number, name: string, parent_id: number | null, status: string }
const { search, pending, state, error } = useErpCatalogList<Row>(erpCatalogRoutes.categories)
</script>

<template>
  <ErpCatalogPageShell title="Categorias">
    <UInput v-model="search" class="max-w-md" />
    <UAlert v-if="error" color="error" :title="error" />
    <UTable :loading="pending" :data="state?.data ?? []" :columns="[{ accessorKey: 'name', header: 'Categoria' }, { accessorKey: 'status', header: 'Status' }]" />
  </ErpCatalogPageShell>
</template>
