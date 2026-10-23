<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpUnitCatalogForm from '~/components/erp/catalogs/ErpUnitCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus, formatErpDecimal } from '#shared/utils/erpCatalogs'

const catalogResource = ref<InstanceType<typeof ErpCatalogResource> | null>(null)

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Unidades · Cadastros ERP' })

const recordLabel = (record: Record<string, unknown>) => String(record.short_name ?? '')
const columns = [
  { accessorKey: 'short_name', header: 'Sigla' },
  { accessorKey: 'actual_name', header: 'Nome' },
  { accessorKey: 'base_unit_id', header: 'Unidade base', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.base_unit_id ? `#${row.original.base_unit_id} · ${formatErpDecimal(row.original.conversion_factor as string | number | null)}` : '—' },
  { accessorKey: 'allow_decimal', header: 'Frações', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.allow_decimal ? 'Sim' : 'Não' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
</script>

<template>
  <ErpCatalogPageShell title="Unidades" description="Unidades de medida e fatores de conversão decimal.">
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
      :path="erpCatalogRoutes.units"
      title="Unidades"
      :show-heading="false"
      search-placeholder="Sigla ou nome da unidade"
      :form="ErpUnitCatalogForm"
      :columns="columns"
      :record-label="recordLabel"
    />
  </ErpCatalogPageShell>
</template>
