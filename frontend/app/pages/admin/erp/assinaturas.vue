<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { adminListParams, readPaginatedMeta } from '~/utils/adminListFilters'
import { panelBodyClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({ middleware: ['auth', 'super-admin'] })

interface ErpSubscriptionRow {
  id: number
  status: string
  company?: { id: number, name: string } | null
  plan?: { id: number, name: string } | null
}

const { $api } = useNuxtApp()
const toast = useToast()
const rows = ref<ErpSubscriptionRow[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const q = ref('')
const debouncedQ = refDebounced(q, 300)
const statusFilter = ref<'all' | string>('all')

const columns: TableColumn<ErpSubscriptionRow>[] = [
  { accessorKey: 'id', header: 'ID' },
  { id: 'company', header: 'Empresa' },
  { id: 'plan', header: 'Plano' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions' }
]

async function load() {
  loading.value = true
  try {
    const response = await $api<{ data: ErpSubscriptionRow[], meta: { total: number, last_page: number } }>('/admin/erp/subscriptions', {
      params: adminListParams(page.value, debouncedQ.value, 'status', statusFilter.value)
    })
    rows.value = response.data
    total.value = readPaginatedMeta(response).total
  } catch {
    toast.add({ title: 'Não foi possível carregar assinaturas ERP', color: 'error' })
  } finally {
    loading.value = false
  }
}

async function activate(row: ErpSubscriptionRow) {
  try {
    await $api(`/admin/erp/subscriptions/${row.id}`, { method: 'PATCH', body: { status: 'active' } })
    toast.add({ title: 'Contrato habilitado', color: 'success' })
    await load()
  } catch {
    toast.add({ title: 'Falha ao atualizar assinatura', color: 'error' })
  }
}

watch([page, debouncedQ, statusFilter], load, { immediate: true })
</script>

<template>
  <div :class="panelBodyClass" class="space-y-4">
    <div class="flex flex-wrap gap-2">
      <UInput v-model="q" placeholder="Buscar empresa ou plano" class="min-w-48" />
      <USelect
        v-model="statusFilter"
        :items="[
          { label: 'Todos', value: 'all' },
          { label: 'Pendente', value: 'pending' },
          { label: 'Ativo', value: 'active' },
          { label: 'Inadimplente', value: 'past_due' },
          { label: 'Cancelado', value: 'canceled' }
        ]"
        class="min-w-40"
      />
    </div>
    <UTable
      :columns="columns"
      :data="rows"
      :loading="loading"
      :ui="panelTableUi"
    >
      <template #company-cell="{ row }">
        {{ row.original.company?.name ?? '—' }}
      </template>
      <template #plan-cell="{ row }">
        {{ row.original.plan?.name ?? '—' }}
      </template>
      <template #actions-cell="{ row }">
        <UButton
          v-if="row.original.status === 'pending'"
          size="xs"
          variant="soft"
          @click="activate(row.original)"
        >
          Habilitar
        </UButton>
      </template>
    </UTable>
    <div :class="panelPaginationClass">
      <UPagination v-model:page="page" :total="total" :items-per-page="15" />
    </div>
  </div>
</template>
