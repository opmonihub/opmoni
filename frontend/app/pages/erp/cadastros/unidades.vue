<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Unidades · Cadastros ERP' })

type UnitRow = { id: number, short_name: string, actual_name: string, status: string }
const { search, page, pending, state, error, refresh } = useErpCatalogList<UnitRow>(erpCatalogRoutes.units)
</script>

<template>
  <ErpCatalogPageShell title="Unidades" description="Unidades de medida e fatores de conversão.">
    <UFormField label="Busca" class="max-w-md">
      <UInput v-model="search" />
    </UFormField>
    <UTable
      :loading="pending"
      :data="state?.data ?? []"
      :columns="[
        { accessorKey: 'short_name', header: 'Sigla' },
        { accessorKey: 'actual_name', header: 'Nome' },
        { accessorKey: 'status', header: 'Status' }
      ]"
    />
    <ErpCatalogPagination
      v-model:page="page"
      :meta="state?.meta"
      :pending="pending"
      :error="error"
      @retry="refresh"
    />
  </ErpCatalogPageShell>
</template>
