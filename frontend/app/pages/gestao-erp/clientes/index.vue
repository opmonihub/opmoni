<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { OfficeErpPortfolioClient, OfficeErpPortfolioClientListResponse } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const query = ref('')
const debouncedQuery = refDebounced(query, 300)
const page = ref(1)
const perPage = ref<25 | 50 | 100>(25)
const clients = ref<OfficeErpPortfolioClient[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
let requestGeneration = 0

const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null, currentAccount.value?.role ?? null,
  context.capabilities.value?.capability_key ?? null
]))
const columns: TableColumn<OfficeErpPortfolioClient>[] = [
  { accessorKey: 'name', header: 'Cliente' },
  { accessorKey: 'tax_id', header: 'CNPJ' },
  { id: 'actions', header: '' }
]

async function loadClients() {
  const request = ++requestGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (request !== requestGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) {
      clients.value = []
      total.value = 0
      loadError.value = 'A conta selecionada não tem permissão para consultar a carteira do escritório.'
      return
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value }
    const search = debouncedQuery.value.trim()
    if (search) params.q = search
    const response = await api<OfficeErpPortfolioClientListResponse>('/erp-commercial/clients', { params })
    if (request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)
      || !Number.isSafeInteger(response.meta.current_page) || !Number.isSafeInteger(response.meta.last_page)
      || ![25, 50, 100].includes(response.meta.per_page)) throw new Error('A resposta da carteira está incompleta.')
    clients.value = response.data
    total.value = response.meta.total
    lastPage.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (request === requestGeneration && identity === identityStamp.value)
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar a carteira agora.'
  } finally {
    if (request === requestGeneration && identity === identityStamp.value) loading.value = false
  }
}

watch([debouncedQuery, page, perPage], () => {
  void loadClients()
}, { immediate: true })
watch([debouncedQuery, perPage], () => {
  page.value = 1
})
watch(identityStamp, () => {
  requestGeneration++
  clients.value = []
  total.value = 0
  confirmedEmpty.value = false
  loadError.value = ''
  void loadClients()
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 flex-col gap-4">
    <DataTablePanelList title="Clientes da carteira" description="Convide responsáveis para acessar o ERP dos clientes do escritório.">
      <template #action>
        <UButton to="/gestao-erp/clientes/novo" icon="i-lucide-send">
          Novo convite
        </UButton>
      </template>
      <template #toolbar>
        <UFormField name="client-search" label="Buscar cliente" class="w-full max-w-sm">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Nome ou CNPJ"
            aria-label="Buscar cliente"
          />
        </UFormField>
      </template>
      <div :class="panelBodyClass">
        <UAlert
          v-if="loadError"
          color="error"
          title="Não foi possível carregar a carteira"
          :description="loadError"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>{{ loadError }}</span>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                @click="loadClients"
              >
                Tentar novamente
              </UButton>
            </div>
          </template>
        </UAlert>
        <UAlert
          v-else-if="confirmedEmpty"
          color="neutral"
          :title="debouncedQuery.trim() ? 'Nenhum cliente encontrado' : 'Nenhum cliente cadastrado'"
          :description="debouncedQuery.trim() ? 'Altere a busca para consultar outros clientes da carteira.' : 'A carteira deste escritório ainda não tem clientes para convidar.'"
        />
        <UTable
          v-else
          :data="clients"
          :columns="columns"
          :loading="loading"
          :ui="panelTableUi"
        >
          <template #name-cell="{ row }">
            <div class="min-w-0">
              <p class="truncate font-medium text-highlighted">
                {{ row.original.trade_name || row.original.name }}
              </p><p v-if="row.original.trade_name" class="truncate text-sm text-muted">
                {{ row.original.name }}
              </p>
            </div>
          </template>
          <template #tax_id-cell="{ row }">
            <span class="text-sm text-muted">{{ row.original.tax_id || '—' }}</span>
          </template>
          <template #actions-cell="{ row }">
            <UButton
              :to="{ path: '/gestao-erp/clientes/novo', query: { cliente: row.original.id } }"
              color="neutral"
              variant="ghost"
              size="sm"
            >
              Convidar responsável
            </UButton>
          </template>
          <template #empty>
            <div class="py-8 text-center text-sm text-muted">
              Nenhum resultado nesta página.
            </div>
          </template>
        </UTable>
        <div v-if="!loadError && !confirmedEmpty" :class="panelFooterClass">
          <span :class="panelFooterCountClass">{{ total }} clientes</span>
          <UPagination
            v-model:page="page"
            :total="total"
            :items-per-page="perPage"
            :disabled="loading || page >= lastPage"
            :class="panelPaginationClass"
          />
        </div>
      </div>
    </DataTablePanelList>
  </div>
</template>
