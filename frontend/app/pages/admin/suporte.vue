<script setup lang="ts">
import type { TableColumn, TabsItem } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'
import { readPaginatedMeta } from '~/utils/adminListFilters'

definePageMeta({
  middleware: ['auth', 'super-admin']
})

interface AdminAccount {
  id: number
  name: string
  status: 'active' | 'suspended'
}

interface SupportLog {
  id: number
  action: string
  ip?: string | null
  metadata?: Record<string, unknown> | null
  created_at: string
  super_admin?: { id: number, name: string, email: string } | null
  account?: { id: number, name: string } | null
}

interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
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
const { accounts, switchAccount, enterSupport } = useAuth()

const tab = ref('entrar')
const tabs = [{
  label: 'Entrar',
  value: 'entrar',
  icon: 'i-lucide-life-buoy'
}, {
  label: 'Auditoria',
  value: 'auditoria',
  icon: 'i-lucide-scroll-text'
}] satisfies TabsItem[]

const query = ref('')
const found = ref<AdminAccount[]>([])
const searching = ref(false)

const logs = ref<SupportLog[]>([])
const logsTotal = ref(0)
const logsPage = ref(1)
const logsPerPage = 15
const accountQuery = ref('')
const loadingLogs = ref(false)

const logsAccountId = computed(() => {
  const value = Number(accountQuery.value)
  return accountQuery.value && Number.isInteger(value) && value > 0 ? value : null
})

const logColumns: TableColumn<SupportLog>[] = [
  { accessorKey: 'created', header: 'Quando' },
  { accessorKey: 'actor', header: 'Super admin' },
  { accessorKey: 'account', header: 'Conta' },
  { accessorKey: 'action', header: 'Ação' },
  { accessorKey: 'ip', header: 'IP' }
]

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = [
  { id: 'created', label: 'Quando' },
  { id: 'actor', label: 'Super admin' },
  { id: 'account', label: 'Conta' },
  { id: 'action', label: 'Ação' },
  { id: 'ip', label: 'IP' }
]

function isOwnAccount(id: number) {
  return accounts.value.some(a => a.id === id)
}

async function enterAccount(account: AdminAccount) {
  try {
    if (isOwnAccount(account.id)) {
      await switchAccount(account.id)
    } else {
      await enterSupport(account.id)
    }
    window.location.assign('/')
  } catch {
    toast.add({ title: 'Não foi possível entrar na conta', color: 'error' })
  }
}

async function search() {
  const term = query.value.trim()
  if (!term) {
    found.value = []
    return
  }
  searching.value = true
  try {
    if (/^\d+$/.test(term)) {
      const account = await $api<AdminAccount>(`/admin/accounts/${term}`)
      found.value = [account]
    } else {
      const lower = term.toLowerCase()
      const matches: AdminAccount[] = []
      let page = 1
      let lastPage = 1
      do {
        const res = await $api<Paginated<AdminAccount>>('/admin/accounts', { params: { page } })
        lastPage = readPaginatedMeta(res).last_page
        matches.push(...res.data.filter(a => a.name.toLowerCase().includes(lower)))
        page += 1
      } while (page <= lastPage)
      found.value = matches
    }
    if (!found.value.length) {
      toast.add({ title: 'Nenhuma conta encontrada', color: 'neutral' })
    }
  } catch {
    toast.add({ title: 'Conta não encontrada', color: 'warning' })
    found.value = []
  } finally {
    searching.value = false
  }
}

/**
 * Falha fatal de carga inicial x falha de refresh da auditoria: sem carga
 * bem-sucedida ainda, o alerta com retry toma o corpo do painel; depois, falha
 * de paginação ou filtro é só toast, e a última boa lista fica visível.
 */
const loadLogsError = ref<unknown>(null)
const logsLoaded = ref(false)

const { showError: showLogsError, retry: retryLogs } = useRetryableLoad({
  refresh: loadLogs,
  error: loadLogsError,
  loading: loadingLogs,
  loadErrorTitle: 'Não foi possível carregar os logs',
  refreshErrorTitle: 'Não foi possível atualizar os logs'
})

async function loadLogs() {
  loadingLogs.value = true
  loadLogsError.value = null
  try {
    const params: Record<string, number> = { page: logsPage.value }
    if (logsAccountId.value) params.account_id = logsAccountId.value
    const res = await $api<Paginated<SupportLog>>('/admin/support/logs', { params })
    logs.value = res.data
    logsTotal.value = readPaginatedMeta(res).total
    logsLoaded.value = true
  } catch (err) {
    if (logsLoaded.value) {
      toast.add({ title: 'Não foi possível atualizar os logs', color: 'error' })
    } else {
      loadLogsError.value = err
    }
  } finally {
    loadingLogs.value = false
  }
}

onMounted(loadLogs)
watch(logsPage, loadLogs)
watch(logsAccountId, () => {
  if (logsPage.value === 1) {
    void loadLogs()
    return
  }
  logsPage.value = 1
})

function actionMeta(action: string) {
  return ACTION_META[action] ?? { label: action, color: 'neutral' as const }
}
</script>

<template>
  <DataTablePanelList
    title="Suporte"
    description="Entre numa conta ou consulte a auditoria."
  >
    <template #toolbar>
      <UTabs v-model="tab" :items="tabs" :content="false" />

      <div class="flex flex-wrap items-center gap-1.5">
        <UInput
          v-if="tab === 'entrar'"
          v-model="query"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Nome ou ID da conta"
          @keydown.enter.prevent="search"
        />
        <UInput
          v-else
          v-model="accountQuery"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Filtrar por ID da conta..."
        />
        <UButton
          v-if="tab === 'entrar'"
          label="Buscar"
          color="neutral"
          :loading="searching"
          @click="search"
        />
        <DataTableColumnMenu
          v-if="tab === 'auditoria'"
          v-model="columnVisibility"
          :columns="hideableColumns"
          class="shrink-0"
        />
      </div>
    </template>

    <ul v-if="tab === 'entrar' && found.length" role="list" class="divide-y divide-default">
      <li
        v-for="account in found"
        :key="account.id"
        class="flex items-center justify-between gap-3 py-3 px-4 sm:px-6"
      >
        <div class="min-w-0">
          <p class="font-medium text-highlighted truncate">
            {{ account.name }}
          </p>
          <p class="text-sm text-muted">
            #{{ account.id }}
          </p>
        </div>

        <div class="flex items-center gap-3">
          <UBadge :color="account.status === 'active' ? 'success' : 'error'" variant="subtle">
            {{ account.status === 'active' ? 'Ativa' : 'Suspensa' }}
          </UBadge>
          <UButton
            icon="i-lucide-life-buoy"
            color="neutral"
            variant="ghost"
            @click="enterAccount(account)"
          />
        </div>
      </li>
    </ul>
    <div v-else-if="tab === 'entrar'" class="flex flex-col items-center justify-center gap-2 py-10 text-sm text-muted">
      <UIcon name="i-lucide-search" class="size-6" />
      <span>Busque uma conta pelo nome ou pelo ID para entrar em suporte.</span>
    </div>

    <div v-else :class="panelBodyClass">
      <ErrorRetryAlert
        v-if="showLogsError"
        title="Não foi possível carregar os logs"
        :loading="loadingLogs"
        @retry="retryLogs"
      />

      <template v-else>
        <UTable
          v-model:column-visibility="columnVisibility"
          :data="logs"
          :columns="logColumns"
          :loading="loadingLogs"
          class="shrink-0"
          :ui="panelTableUi"
        >
          <template #created-cell="{ row }">
            {{ new Date(row.original.created_at).toLocaleString('pt-BR') }}
          </template>

          <template #actor-cell="{ row }">
            <p class="font-medium text-highlighted">
              {{ row.original.super_admin?.name ?? '—' }}
            </p>
          </template>

          <template #account-cell="{ row }">
            {{ row.original.account?.name ?? '—' }}
          </template>

          <template #action-cell="{ row }">
            <UBadge :color="actionMeta(row.original.action).color" variant="subtle">
              {{ actionMeta(row.original.action).label }}
            </UBadge>
          </template>

          <template #ip-cell="{ row }">
            <span class="text-muted">{{ row.original.ip ?? '—' }}</span>
          </template>

          <template #empty>
            <DataTablePanelTableEmpty
              icon="i-lucide-scroll-text"
              label="Nenhum evento encontrado."
            />
          </template>
        </UTable>

        <div :class="panelFooterClass">
          <div :class="panelFooterCountClass">
            {{ logsTotal }} evento(s)
          </div>

          <div :class="panelPaginationClass">
            <UPagination v-model:page="logsPage" :total="logsTotal" :items-per-page="logsPerPage" />
          </div>
        </div>
      </template>
    </div>
  </DataTablePanelList>
</template>
