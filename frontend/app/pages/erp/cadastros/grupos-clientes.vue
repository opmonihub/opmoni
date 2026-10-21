<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpCustomerGroupCatalogForm from '~/components/erp/catalogs/ErpCustomerGroupCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus, formatErpDecimal } from '#shared/utils/erpCatalogs'

const catalogResource = ref<InstanceType<typeof ErpCatalogResource> | null>(null)

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Grupos de clientes · Cadastros ERP' })

const recordLabel = (record: Record<string, unknown>) => String(record.name ?? '')
const columns = [
  { accessorKey: 'name', header: 'Nome' },
  { accessorKey: 'percentage_adjustment', header: 'Ajuste percentual', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimal(row.original.percentage_adjustment as string | number | null) },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
</script>

<template>
  <ErpCatalogPageShell title="Grupos de clientes" description="Organize contatos de clientes por grupo e ajuste percentual.">
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
      :path="erpCatalogRoutes.customerGroups"
      title="Grupos de clientes"
      :show-heading="false"
      search-placeholder="Nome do grupo"
      :form="ErpCustomerGroupCatalogForm"
      :columns="columns"
      :record-label="recordLabel"
    />
  </ErpCatalogPageShell>
</template>
