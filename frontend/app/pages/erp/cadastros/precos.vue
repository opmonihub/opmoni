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
        :loading="groups.pending.value"
        :data="groups.state.value?.data ?? []"
        :columns="[
          { accessorKey: 'name', header: 'Grupo' },
          { accessorKey: 'percentage_adjustment', header: 'Ajuste %', cell: ({ row }) => formatErpDecimal(row.original.percentage_adjustment) },
          { accessorKey: 'is_default', header: 'Padrão', cell: ({ row }) => row.original.is_default ? 'Sim' : 'Não' }
        ]"
      />
    </UCard>
    <UCard>
      <template #header>
        Descontos e cupons
      </template>
      <UTable
        :loading="discounts.pending.value"
        :data="discounts.state.value?.data ?? []"
        :columns="[
          { accessorKey: 'name', header: 'Regra' },
          { accessorKey: 'kind', header: 'Tipo' },
          { accessorKey: 'value', header: 'Valor', cell: ({ row }) => formatErpDecimal(row.original.value) },
          { accessorKey: 'coupon_code', header: 'Cupom' }
        ]"
      />
    </UCard>
  </ErpCatalogPageShell>
</template>
