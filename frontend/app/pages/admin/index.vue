<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AdminSummary, SupportAccessLog as SupportLog, DetailResponse } from '#shared/types/admin'
import { createLatestRequestRunner } from '~/utils/adminListFilters'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelTableUi } from '~/components/data-table/panel'

definePageMeta({
  middleware: ['auth', 'super-admin']
})
const ACTION_META: Record<string, {
  label: string
  color: 'info' | 'neutral' | 'success' | 'warning' | 'error'
}> = {
  enter: { label: 'Entrada', color: 'info' },
  exit: { label: 'Saída', color: 'neutral' },
  create: { label: 'Criação', color: 'success' },
  update: { label: 'Edição', color: 'warning' },
  delete: { label: 'Exclusão', color: 'error' }
}
const adminReadAllowed = useAdminReadAllowed()
const { $api } = useNuxtApp()
const runLatestLoad = createLatestRequestRunner()
onBeforeUnmount(runLatestLoad.invalidate)
const totals = ref<AdminSummary['totals']>({ accounts: 0, users: 0, subscriptions: 0, plans: 0 })
const recentLogs = ref<SupportLog[]>([])
const loading = ref(true)
const cards = computed(() => [
  { label: 'Contas', value: totals.value.accounts, icon: 'i-lucide-building-2', to: '/admin/contas' },
  { label: 'Planos', value: totals.value.plans, icon: 'i-lucide-layers', to: '/admin/planos' },
  { label: 'Assinaturas', value: totals.value.subscriptions, icon: 'i-lucide-receipt', to: '/admin/assinaturas' },
  { label: 'Usuários', value: totals.value.users, icon: 'i-lucide-users', to: '/admin/usuarios' }
])
const columns: TableColumn<SupportLog>[] = [
  { accessorKey: 'created_at', header: 'Quando' },
  { accessorKey: 'actor', header: 'Super admin' },
  { accessorKey: 'action', header: 'Ação' },
  { accessorKey: 'account', header: 'Conta' }
]
const columnVisibility = ref<Record<string, boolean>>({})
const hideableColumns = [
  { id: 'created_at', label: 'Quando' },
  { id: 'actor', label: 'Super admin' },
  { id: 'action', label: 'Ação' },
  { id: 'account', label: 'Conta' }
]
function actionMeta(action: string) {
  return ACTION_META[action] ?? { label: action, color: 'neutral' as const }
}
const loadError = ref<unknown>(null)
const hasLoaded = ref(false)
const { isLoading, showError, retry } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: 'Não foi possível carregar o resumo',
  refreshErrorTitle: 'Não foi possível atualizar o resumo'
})
function load() {
  if (!adminReadAllowed.value)
    return Promise.resolve()
  loading.value = true
  loadError.value = null
  return runLatestLoad(signal => $api<DetailResponse<AdminSummary>>('/admin/summary', { signal }), {
    onSuccess: ({ data }) => {
      totals.value = data.totals
      recentLogs.value = data.recent_support_logs
      hasLoaded.value = true
    },
    onError: (error) => {
      loadError.value = error
    },
    onSettled: () => {
      loading.value = false
    }
  })
}
onMounted(load)
const readAuthority = useAdminReadAuthority(() => {
  runLatestLoad.invalidate()
  totals.value = { accounts: 0, users: 0, subscriptions: 0, plans: 0 }
  recentLogs.value = []
  loadError.value = null
  hasLoaded.value = false
  loading.value = false
})
watch(readAuthority, load)
</script>

<template>
  <ErrorRetryAlert
    v-if="showError"
    :title="hasLoaded ? 'Não foi possível atualizar. Os resultados anteriores foram mantidos.' : 'Não foi possível carregar o resumo'"
    :loading="isLoading"
    class="mt-4"
    @retry="retry"
  />

  <div v-if="!showError || hasLoaded" class="flex flex-col gap-4 sm:gap-6">
    <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
      <MetricCard
        v-for="card in cards"
        :key="card.label"
        :icon="card.icon"
        :title="card.label"
        :to="card.to"
        tone="brand"
        :loading="loading"
        :value="card.value"
      />
    </UPageGrid>

    <div>
      <DataTablePanelList
        title="Acessos de suporte recentes"
        description="Últimos eventos registrados na auditoria."
      >
        <template #toolbar>
          <DataTableColumnMenu
            v-model="columnVisibility"
            :columns="hideableColumns"
            class="ms-auto shrink-0"
          />
        </template>
        <div class="p-4 sm:p-6">
          <UTable
            v-model:column-visibility="columnVisibility"
            :data="recentLogs"
            :columns="columns"
            :loading="loading"
            class="shrink-0"
            :ui="panelTableUi"
          >
            <template #created_at-cell="{ row }">
              {{ new Date(row.original.created_at).toLocaleString('pt-BR') }}
            </template>

            <template #actor-cell="{ row }">
              <span class="font-medium text-highlighted">{{ row.original.super_admin?.name ?? '—' }}</span>
            </template>

            <template #action-cell="{ row }">
              <UBadge :color="actionMeta(row.original.action).color" variant="subtle">
                {{ actionMeta(row.original.action).label }}
              </UBadge>
            </template>

            <template #account-cell="{ row }">
              {{ row.original.account?.name ?? '—' }}
            </template>

            <template #empty>
              <DataTablePanelTableEmpty
                icon="i-lucide-scroll-text"
                label="Nenhum acesso de suporte registrado ainda."
              />
            </template>
          </UTable>
        </div>
      </DataTablePanelList>
    </div>
  </div>
</template>
