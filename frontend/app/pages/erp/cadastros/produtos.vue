<script setup lang="ts">
import type { ErpProductRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes, formatErpDecimal } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Produtos · Cadastros ERP' })

const { search, page, pending, searchPending, state, error, hasLoaded, stale, authorityBlocked, retry } = useErpCatalogList<ErpProductRecord>(erpCatalogRoutes.products)
</script>

<template>
  <ErpCatalogPageShell title="Produtos" description="Mercadorias e serviços com preço decimal e variantes.">
    <UFormField label="Busca" class="max-w-md">
      <UInput v-model="search" placeholder="Nome, SKU ou código de barras" />
    </UFormField>
    <UTable
      v-if="hasLoaded && state?.data.length"
      :loading="pending"
      :data="state?.data ?? []"
      :columns="[
        { accessorKey: 'name', header: 'Nome' },
        { accessorKey: 'sku', header: 'SKU' },
        { accessorKey: 'sale_price', header: 'Preço', cell: ({ row }) => formatErpDecimal(row.original.sale_price) },
        { accessorKey: 'status', header: 'Status' }
      ]"
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
