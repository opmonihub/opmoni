<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpContactCatalogForm from '~/components/erp/catalogs/ErpContactCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import ErpContactNotesPanel from '~/components/erp/catalogs/ErpContactNotesPanel.vue'
import { erpCatalogRoutes, formatErpCatalogStatus } from '#shared/utils/erpCatalogs'

const catalogResource = ref<InstanceType<typeof ErpCatalogResource> | null>(null)

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Contatos · Cadastros ERP' })

const columns = [
  { accessorKey: 'name', header: 'Nome' },
  { accessorKey: 'tax_id', header: 'Documento' },
  { accessorKey: 'email', header: 'E-mail' },
  { id: 'roles', header: 'Relação', cell: ({ row }: { row: { original: Record<string, unknown> } }) => [row.original.is_customer ? 'Cliente' : '', row.original.is_supplier ? 'Fornecedor' : ''].filter(Boolean).join(' · ') || '—' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const recordLabel = (record: Record<string, unknown>) => String(record.name ?? '')
</script>

<template>
  <ErpCatalogPageShell title="Contatos" description="Clientes e fornecedores comerciais da empresa, separados do portfólio do escritório.">
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
      :path="erpCatalogRoutes.contacts"
      title="Contatos comerciais"
      :show-heading="false"
      search-placeholder="Nome, e-mail ou documento"
      :form="ErpContactCatalogForm"
      :columns="columns"
      :record-label="recordLabel"
      detail-form-label="Dados do cadastro"
      detail-panel-label="Notas e anexos"
    >
      <template #detail-panel="{ record }">
        <ErpContactNotesPanel :key="record.id" :contact-id="record.id" />
      </template>
    </ErpCatalogResource>
  </ErpCatalogPageShell>
</template>
