<script setup lang="ts">
import type { ErpContactRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import { apiFailure } from '#shared/utils/access'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Contatos · Cadastros ERP' })

const { search, page, pending, state, error, refresh } = useErpCatalogList<ErpContactRecord>(erpCatalogRoutes.contacts)
const { api, refreshCsrf } = useAccessApi()
const creating = ref(false)
const form = reactive({ name: '', tax_id: '', is_customer: true, is_supplier: false })
const formError = ref('')

async function createContact() {
  if (creating.value || !form.name.trim())
    return
  creating.value = true
  formError.value = ''
  try {
    await refreshCsrf()
    await api(erpCatalogRoutes.contacts, { method: 'POST', body: { ...form, tax_id: form.tax_id || null } })
    form.name = ''
    form.tax_id = ''
    await refresh()
  } catch (err) {
    formError.value = apiFailure(err).message
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <ErpCatalogPageShell
    title="Contatos"
    description="Clientes e fornecedores comerciais da empresa (separados do portfólio do escritório)."
  >
    <div class="flex flex-wrap items-end gap-3">
      <UFormField label="Busca" class="min-w-48 flex-1">
        <UInput v-model="search" placeholder="Nome, e-mail ou documento" />
      </UFormField>
    </div>

    <UCard>
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="createContact">
        <UFormField label="Nome" required>
          <UInput v-model="form.name" />
        </UFormField>
        <UFormField label="CPF/CNPJ">
          <UInput v-model="form.tax_id" />
        </UFormField>
        <div class="flex flex-wrap gap-4 sm:col-span-2">
          <UCheckbox v-model="form.is_customer" label="Cliente" />
          <UCheckbox v-model="form.is_supplier" label="Fornecedor" />
        </div>
        <div class="sm:col-span-2">
          <UAlert v-if="formError" color="error" :title="formError" />
          <UButton type="submit" :loading="creating">
            Adicionar contato
          </UButton>
        </div>
      </form>
    </UCard>

    <UTable
      :loading="pending"
      :data="state?.data ?? []"
      :columns="[
        { accessorKey: 'name', header: 'Nome' },
        { accessorKey: 'tax_id', header: 'Documento' },
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
