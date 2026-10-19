<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpDiscountRuleCatalogForm from '~/components/erp/catalogs/ErpDiscountRuleCatalogForm.vue'
import ErpPriceGroupCatalogForm from '~/components/erp/catalogs/ErpPriceGroupCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus, formatErpDecimal } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Preços · Cadastros ERP' })

const groupColumns = [
  { accessorKey: 'name', header: 'Grupo' },
  { accessorKey: 'percentage_adjustment', header: 'Ajuste %', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimal(row.original.percentage_adjustment as string | number | null) },
  { accessorKey: 'is_default', header: 'Padrão', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.is_default ? 'Sim' : 'Não' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const discountColumns = [
  { accessorKey: 'name', header: 'Regra' },
  { accessorKey: 'kind', header: 'Tipo', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.kind === 'percentage' ? 'Percentual' : 'Valor fixo' },
  { accessorKey: 'value', header: 'Valor', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimal(row.original.value as string | number | null) },
  { accessorKey: 'coupon_code', header: 'Cupom' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const groupLabel = (record: Record<string, unknown>) => String(record.name ?? '')
const discountLabel = (record: Record<string, unknown>) => String(record.name ?? '')
</script>

<template>
  <ErpCatalogPageShell title="Preços e descontos" description="Grupos de preço e regras comerciais com valores decimais exatos.">
    <ErpCatalogResource
      :path="erpCatalogRoutes.priceGroups"
      title="Grupos de preço"
      :form="ErpPriceGroupCatalogForm"
      :columns="groupColumns"
      :record-label="groupLabel"
    />
    <ErpCatalogResource
      :path="erpCatalogRoutes.discountRules"
      title="Descontos e cupons"
      :form="ErpDiscountRuleCatalogForm"
      :columns="discountColumns"
      :record-label="discountLabel"
    />
  </ErpCatalogPageShell>
</template>
