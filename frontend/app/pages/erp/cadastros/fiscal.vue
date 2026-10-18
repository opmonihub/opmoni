<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type TaxRate = { id: number, name: string, rate: string, tax_kind: string, status: string }
type Nature = { id: number, cfop: string, description: string, movement: string, status: string }
const taxRates = useErpCatalogList<TaxRate>(erpCatalogRoutes.taxRates)
const natures = useErpCatalogList<Nature>(erpCatalogRoutes.operationNatures)
</script>

<template>
  <ErpCatalogPageShell title="Referências fiscais" description="Alíquotas e naturezas de operação por empresa/estabelecimento.">
    <UCard>
      <template #header>
        Alíquotas
      </template>
      <UTable :loading="taxRates.pending.value" :data="taxRates.state.value?.data ?? []" :columns="[{ accessorKey: 'name', header: 'Nome' }, { accessorKey: 'rate', header: 'Alíquota' }, { accessorKey: 'tax_kind', header: 'Tipo' }]" />
      <ErpCatalogPagination
        v-model:page="taxRates.page.value"
        :meta="taxRates.state.value?.meta"
        :pending="taxRates.pending.value"
        :error="taxRates.error.value"
        @retry="taxRates.refresh"
      />
    </UCard>
    <UCard>
      <template #header>
        Naturezas de operação
      </template>
      <UTable :loading="natures.pending.value" :data="natures.state.value?.data ?? []" :columns="[{ accessorKey: 'cfop', header: 'CFOP' }, { accessorKey: 'description', header: 'Descrição' }, { accessorKey: 'movement', header: 'Mov.' }]" />
      <ErpCatalogPagination
        v-model:page="natures.page.value"
        :meta="natures.state.value?.meta"
        :pending="natures.pending.value"
        :error="natures.error.value"
        @retry="natures.refresh"
      />
    </UCard>
  </ErpCatalogPageShell>
</template>
