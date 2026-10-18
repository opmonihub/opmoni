<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type Row = { id: number, name: string, status: string }
const { search, page, pending, searchPending, state, error, hasLoaded, stale, authorityBlocked, retry } = useErpCatalogList<Row>(erpCatalogRoutes.brands)
</script>

<template>
  <ErpCatalogPageShell title="Marcas">
    <UInput v-model="search" placeholder="Buscar" class="max-w-md" />
    <UTable
      v-if="hasLoaded && state?.data.length"
      :loading="pending"
      :data="state?.data ?? []"
      :columns="[{ accessorKey: 'name', header: 'Marca' }, { accessorKey: 'status', header: 'Status' }]"
    />
    <ErpCatalogPagination
      v-model:page="page"
      :meta="state?.meta"
      :pending="pending"
      :error="error"
      :loaded="hasLoaded"
      :has-rows="Boolean(state?.data.length)"
      :stale="stale"
      :search-pending="searchPending"
      :authority-blocked="authorityBlocked"
      :search="search"
      @retry="retry"
    />
  </ErpCatalogPageShell>
</template>
