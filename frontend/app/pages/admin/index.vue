<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelTableUi } from '~/components/data-table/panel'

definePageMeta({
  middleware: ['auth', 'super-admin']
})

interface Totals {
  accounts: number
  users: number
  subscriptions: number
  plans: number
}

interface SupportLog {
  id: number
  action: string
  created_at: string
  super_admin?: { name: string } | null
  account?: { name: string } | null
}

const ACTION_META: Record<string, { label: string, color: 'info' | 'neutral' | 'success' | 'warning' | 'error' }> = {
  enter: { label: 'Entrada', color: 'info' },
  exit: { label: 'Saída', color: 'neutral' },
  create: { label: 'Criação', color: 'success' },
  update: { label: 'Edição', color: 'warning' },
  delete: { label: 'Exclusão', color: 'error' }
}

const { $api } = useNuxtApp()
const toast = useToast()

const totals = ref<Totals>({ accounts: 0, users: 0, subscriptions: 0, plans: 0 })
const recentLogs = ref<SupportLog[]>([])
const loading = ref(false)

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

/**
 * Falha fatal de carga inicial x falha de refresh: sem carga bem-sucedida ainda,
 * o alerta com retry toma a página inteira — cartões zerados diriam que não há
 * nada no sistema. Depois de carregado, falha de refresh é só toast, e o último
 * bom resumo fica visível.
 */
const loadError = ref<unknown>(null)
const hasLoaded = ref(false)

const { isLoading, showError, retry } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: 'Não foi possível carregar o resumo',
  refreshErrorTitle: 'Não foi possível atualizar o resumo'
})

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const [accountsRes, usersRes, subsRes, plansRes, logsRes] = await Promise.all([
      $api<{ total: number }>('/admin/accounts', { params: { page: 1 } }),
      $api<{ total: number }>('/admin/users', { params: { page: 1 } }),
      $api<{ total: number }>('/admin/subscriptions', { params: { page: 1 } }),
      $api<unknown[]>('/admin/plans'),
      $api<{ data: SupportLog[] }>('/admin/support/logs', { params: { page: 1 } })
    ])
    totals.value = {
      accounts: accountsRes.total,
      users: usersRes.total,
      subscriptions: subsRes.total,
      plans: plansRes.length
    }
    recentLogs.value = logsRes.data.slice(0, 5)
    hasLoaded.value = true
  } catch (err) {
    if (hasLoaded.value) {
      toast.add({ title: 'Não foi possível atualizar o resumo', color: 'error' })
    } else {
      loadError.value = err
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <ErrorRetryAlert
    v-if="showError"
    title="Não foi possível carregar o resumo"
    :loading="isLoading"
    class="mt-4"
    @retry="retry"
  />

  <div v-else class="flex flex-col gap-4 sm:gap-6">
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
