<script setup lang="ts">
import { erpCatalogRoutes, formatErpDecimal } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type PriceGroup = { id: number, name: string, percentage_adjustment: string, is_default: boolean, status: string }
type Discount = { id: number, name: string, kind: string, value: string, coupon_code: string | null, status: string }
const groups = useErpCatalogList<PriceGroup>(erpCatalogRoutes.priceGroups)
const discounts = useErpCatalogList<Discount>(erpCatalogRoutes.discountRules)
</script>

<template>
  <ErpCatalogPageShell title="Preços e descontos" description="Grupos de preço e regras/cupons comerciais.">
    <UCard>
      <template #header>
        Grupos de preço
      </template>
      <UTable
        v-if="groups.hasLoaded.value && groups.state.value?.data.length"
        :loading="groups.pending.value"
        :data="groups.state.value?.data ?? []"
        :columns="[
          { accessorKey: 'name', header: 'Grupo' },
          { accessorKey: 'percentage_adjustment', header: 'Ajuste %', cell: ({ row }) => formatErpDecimal(row.original.percentage_adjustment) },
          { accessorKey: 'is_default', header: 'Padrão', cell: ({ row }) => row.original.is_default ? 'Sim' : 'Não' }
        ]"
      />
      <ErpCatalogPagination
        v-model:page="groups.page.value"
        :meta="groups.state.value?.meta"
        :pending="groups.pending.value"
        :error="groups.error.value"
        :loaded="groups.hasLoaded.value"
        :has-rows="Boolean(groups.state.value?.data.length)"
        :stale="groups.stale.value"
        :search-pending="groups.searchPending.value"
        :authority-blocked="groups.authorityBlocked.value"
        :search="groups.search.value"
        @retry="groups.retry"
      />
    </UCard>
    <UCard>
      <template #header>
        Descontos e cupons
      </template>
      <UTable
        v-if="discounts.hasLoaded.value && discounts.state.value?.data.length"
        :loading="discounts.pending.value"
        :data="discounts.state.value?.data ?? []"
        :columns="[
          { accessorKey: 'name', header: 'Regra' },
          { accessorKey: 'kind', header: 'Tipo' },
          { accessorKey: 'value', header: 'Valor', cell: ({ row }) => formatErpDecimal(row.original.value) },
          { accessorKey: 'coupon_code', header: 'Cupom' }
        ]"
      />
      <ErpCatalogPagination
        v-model:page="discounts.page.value"
        :meta="discounts.state.value?.meta"
        :pending="discounts.pending.value"
        :error="discounts.error.value"
        :loaded="discounts.hasLoaded.value"
        :has-rows="Boolean(discounts.state.value?.data.length)"
        :stale="discounts.stale.value"
        :search-pending="discounts.searchPending.value"
        :authority-blocked="discounts.authorityBlocked.value"
        :search="discounts.search.value"
        @retry="discounts.retry"
      />
    </UCard>
  </ErpCatalogPageShell>
</template>
