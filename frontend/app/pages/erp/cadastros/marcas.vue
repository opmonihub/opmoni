<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type Row = { id: number, name: string, status: string }
const { search, pending, state, error } = useErpCatalogList<Row>(erpCatalogRoutes.brands)
</script>

<template>
  <ErpCatalogPageShell title="Marcas">
    <UInput v-model="search" placeholder="Buscar" class="max-w-md" />
    <UAlert v-if="error" color="error" :title="error" />
    <UTable :loading="pending" :data="state?.data ?? []" :columns="[{ accessorKey: 'name', header: 'Marca' }, { accessorKey: 'status', header: 'Status' }]" />
  </ErpCatalogPageShell>
</template>
