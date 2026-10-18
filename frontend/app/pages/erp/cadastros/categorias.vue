<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type Row = { id: number, name: string, parent_id: number | null, status: string }
const { search, page, pending, searchPending, state, error, hasLoaded, stale, authorityBlocked, retry } = useErpCatalogList<Row>(erpCatalogRoutes.categories)
</script>

<template>
  <ErpCatalogPageShell title="Categorias">
    <UInput v-model="search" class="max-w-md" />
    <UTable
      v-if="hasLoaded && state?.data.length"
      :loading="pending"
      :data="state?.data ?? []"
      :columns="[{ accessorKey: 'name', header: 'Categoria' }, { accessorKey: 'status', header: 'Status' }]"
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
