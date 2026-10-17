<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Unidades · Cadastros ERP' })

type UnitRow = { id: number, short_name: string, actual_name: string, status: string }
const { search, pending, state, error } = useErpCatalogList<UnitRow>(erpCatalogRoutes.units)
</script>

<template>
  <ErpCatalogPageShell title="Unidades" description="Unidades de medida e fatores de conversão.">
    <UFormField label="Busca" class="max-w-md">
      <UInput v-model="search" />
    </UFormField>
    <UAlert v-if="error" color="error" :title="error" />
    <UTable
      :loading="pending"
      :data="state?.data ?? []"
      :columns="[
        { accessorKey: 'short_name', header: 'Sigla' },
        { accessorKey: 'actual_name', header: 'Nome' },
        { accessorKey: 'status', header: 'Status' }
      ]"
    />
  </ErpCatalogPageShell>
</template>
