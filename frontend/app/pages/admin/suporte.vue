<script setup lang="ts">
import type { ExtendedPlatformAccount as AdminAccount, SupportAccessLog as SupportLog, AccountListResponse, DetailResponse, ResourceListResponse } from '#shared/types/admin'
import type { TableColumn, TabsItem } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'
import { readPaginatedMeta, createLatestRequestRunner, pageWithinLastPage } from '~/utils/adminListFilters'

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
const { $api } = useNuxtApp()
const toast = useToast()
const { accounts, switchAccount } = useAuth()
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
const searchError = ref<unknown>(null)
const searchLoaded = ref(false)
const searchPage = ref(1)
const searchTotal = ref(0)
const searchPerPage = ref(15)
const appliedQuery = ref('')
const runSearch = createLatestRequestRunner()
const runLogs = createLatestRequestRunner()
const switching = ref(false)
const logs = ref<SupportLog[]>([])
const logsTotal = ref(0)
const logsPage = ref(1)
const logsPerPage = ref(15)
const accountQuery = ref('')
const loadingLogs = ref(true)
const logsAccountId = computed(() => {
  const value = Number(accountQuery.value)
  return accountQuery.value && /^\d+$/.test(accountQuery.value.trim()) && Number.isSafeInteger(value) && value > 0 ? value : null
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
  return accounts.value.some(a => a.id === id && a.status === 'active')
}
function canEnter(account: AdminAccount) {
  return account.status === 'active' && isOwnAccount(account.id)
}
async function enterAccount(account: AdminAccount) {
  if (switching.value || !canEnter(account))
    return
  switching.value = true
  try {
    await switchAccount(account.id)
  } catch {
    toast.add({ title: 'Não foi possível entrar na conta', color: 'error' })
  } finally {
    switching.value = false
  }
}
function search() {
  if (searching.value && appliedQuery.value === query.value.trim())
    return
  appliedQuery.value = query.value.trim()
  if (searchPage.value !== 1) {
    searchPage.value = 1
    return
  }
  return loadSearch()
}
function loadSearch() {
  const term = appliedQuery.value
  searchError.value = null
  if (!term) {
    runSearch.invalidate()
    found.value = []
    searchLoaded.value = false
    searching.value = false
    return
  }
  searching.value = true
  const numeric = /^\d+$/.test(term)
  return runSearch(async () => {
    if (numeric) {
      try {
        const result = await $api<DetailResponse<AdminAccount>>(`/admin/accounts/${term}`)
        return { data: [result.data], meta: { page: 1, last_page: 1, per_page: 15, total: 1 } }
      } catch (error) {
        const failure = error as {
          statusCode?: number
          status?: number
        }
        if ((failure.statusCode ?? failure.status) === 404)
          return { data: [], meta: { page: 1, last_page: 1, per_page: 15, total: 0 } }
        throw error
      }
    }
    return $api<AccountListResponse>('/admin/accounts', { params: { page: searchPage.value, q: term } })
  }, {
    onSuccess: (result) => {
      const meta = readPaginatedMeta(result)
      const recovered = pageWithinLastPage(searchPage.value, meta.last_page)
      if (recovered !== searchPage.value) {
        searchPage.value = recovered
        return
      }
      found.value = result.data
      searchTotal.value = meta.total
      searchPerPage.value = meta.per_page
      searchLoaded.value = true
    },
    onError: (error) => {
      searchError.value = error
    },
    onSettled: () => {
      searching.value = false
    }
  })
}
watch(searchPage, loadSearch)
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
  loadLogsError.value = null
  if (accountQuery.value.trim() && !logsAccountId.value) {
    runLogs.invalidate()
    loadLogsError.value = new Error('Informe um ID de conta inteiro e positivo.')
    loadingLogs.value = false
    return
  }
  loadingLogs.value = true
  const params: Record<string, number> = { page: logsPage.value }
  if (logsAccountId.value)
    params.account_id = logsAccountId.value
  return runLogs(() => $api<ResourceListResponse<SupportLog>>('/admin/support/logs', { params }), {
    onSuccess: (res) => {
      const meta = readPaginatedMeta(res)
      const recovered = pageWithinLastPage(logsPage.value, meta.last_page)
      if (recovered !== logsPage.value) {
        logsPage.value = recovered
        return
      }
      logs.value = res.data
      logsTotal.value = meta.total
      logsPerPage.value = meta.per_page
      logsLoaded.value = true
    },
    onError: (error) => {
      loadLogsError.value = error
    },
    onSettled: () => {
      loadingLogs.value = false
    }
  })
}
onMounted(loadLogs)
onBeforeUnmount(() => {
  runSearch.invalidate()
  runLogs.invalidate()
})
watch(logsPage, loadLogs)
watch(accountQuery, () => {
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
    description="Acesse contas das quais você é membro ou consulte a auditoria."
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
          aria-label="Buscar conta por nome ou ID"
          @keydown.enter.prevent="search"
        />
        <UInput
          v-else
          v-model="accountQuery"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Filtrar por ID da conta..."
          aria-label="Filtrar auditoria por ID da conta"
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

    <ErrorRetryAlert
      v-if="tab === 'entrar' && searchError"
      :title="searchLoaded ? 'Não foi possível atualizar a busca. Os resultados anteriores foram mantidos.' : 'Não foi possível buscar contas.'"
      :loading="searching"
      @retry="loadSearch"
    />
    <p v-if="tab === 'entrar' && searching" role="status">
      Buscando contas…
    </p>
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
            :disabled="switching || !canEnter(account)"
            :title="account.status !== 'active' ? 'Conta suspensa' : !isOwnAccount(account.id) ? 'É necessário ser membro desta conta' : 'Acessar com seu vínculo atual'"
            aria-label="Acessar conta como membro"
            @click="enterAccount(account)"
          />
        </div>
      </li>
    </ul>
    <div v-else-if="tab === 'entrar'" class="flex flex-col items-center justify-center gap-2 py-10 text-sm text-muted">
      <UIcon name="i-lucide-search" class="size-6" />
      <span>{{ searchLoaded ? 'Nenhuma conta encontrada.' : 'Busque uma conta pelo nome ou pelo ID. O acesso exige vínculo de membro.' }}</span>
    </div>

    <UPagination
      v-if="tab === 'entrar' && searchLoaded && searchTotal > searchPerPage"
      v-model:page="searchPage"
      :total="searchTotal"
      :items-per-page="searchPerPage"
    />
    <div v-if="tab === 'auditoria'" :class="panelBodyClass">
      <ErrorRetryAlert
        v-if="showLogsError"
        :title="accountQuery.trim() && !logsAccountId ? 'Informe um ID de conta inteiro e positivo. Os resultados anteriores foram mantidos.' : logsLoaded ? 'Não foi possível atualizar os logs. Os resultados anteriores foram mantidos.' : 'Não foi possível carregar os logs.'"
        :loading="loadingLogs"
        @retry="retryLogs"
      />

      <template v-if="!showLogsError || logsLoaded">
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
