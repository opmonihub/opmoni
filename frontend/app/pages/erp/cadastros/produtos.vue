<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpProductCatalogForm from '~/components/erp/catalogs/ErpProductCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus } from '#shared/utils/erpCatalogs'
import { formatErpDecimalDisplay } from '#shared/utils/erpDecimalDisplay'

const catalogResource = ref<InstanceType<typeof ErpCatalogResource> | null>(null)

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Produtos · Cadastros ERP' })

const recordLabel = (record: Record<string, unknown>) => String(record.name ?? '')
const columns = [
  { accessorKey: 'name', header: 'Nome' },
  { accessorKey: 'sku', header: 'SKU' },
  { accessorKey: 'kind', header: 'Tipo', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.kind === 'service' ? 'Serviço' : 'Mercadoria' },
  { accessorKey: 'sale_price', header: 'Preço', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimalDisplay(row.original.sale_price as string | number | null, { minimumFractionDigits: 2 }) },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
</script>

<template>
  <ErpCatalogPageShell embedded title="Produtos" description="Mercadorias, serviços e suas variações.">
    <template #actions>
      <UButton
        v-if="catalogResource?.createAction.allowed"
        :aria-label="catalogResource.createAction.label"
        icon="i-lucide-plus"
        :disabled="catalogResource.createAction.busy"
        @click="catalogResource.createAction.trigger"
      >
        <span class="hidden sm:inline">{{ catalogResource.createAction.label }}</span>
      </UButton>
    </template>
    <ErpCatalogResource
      ref="catalogResource"
      :path="erpCatalogRoutes.products"
      title="Produtos"
      :show-heading="false"
      search-placeholder="Nome, SKU ou código de barras"
      :form="ErpProductCatalogForm"
      :columns="columns"
      :record-label="recordLabel"
    />
  </ErpCatalogPageShell>
</template>
