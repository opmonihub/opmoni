<script setup lang="ts">
import OfficeErpDataTable from '~/components/office-erp/OfficeErpDataTable.vue'
import OfficeErpPagination from '~/components/office-erp/OfficeErpPagination.vue'
import OfficeErpPanel from '~/components/office-erp/OfficeErpPanel.vue'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import type { TableColumn } from '@nuxt/ui'
import type { OfficeErpPortfolioClient, OfficeErpPortfolioClientListResponse } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const toast = useToast()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const route = useRoute()
function explicitCriteria() {
  const query = route.query ?? {}
  if (!Object.hasOwn(query, 'q') && !Object.hasOwn(query, 'pagina') && !Object.hasOwn(query, 'porPagina')) return null
  const requestedPage = typeof query.pagina === 'string' && /^[1-9]\d*$/.test(query.pagina) ? Number(query.pagina) : 1
  const requestedSize = Number(query.porPagina)
  return { q: typeof query.q === 'string' ? query.q : '', page: Number.isSafeInteger(requestedPage) ? requestedPage : 1, perPage: ([25, 50, 100].includes(requestedSize) ? requestedSize : 25) as 25 | 50 | 100 }
}
const navigation = useOfficeErpListNavigation('clients', { q: '', page: 1, perPage: 25 as 25 | 50 | 100 }, context, {}, explicitCriteria)
const query = listNavigationField(navigation, 'q')
const delayedQuery = refDebounced(query, 300)
const debouncedQuery = ref(query.value)
watch(delayedQuery, (value) => {
  debouncedQuery.value = value
}, { flush: 'sync' })
watch(explicitCriteria, (criteria) => {
  // URL/history criteria are one applied snapshot; only manual input waits for debounce.
  if (criteria) debouncedQuery.value = criteria.q
}, { flush: 'sync' })
const page = listNavigationField(navigation, 'page')
const perPage = listNavigationField(navigation, 'perPage')
const clients = ref<OfficeErpPortfolioClient[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
const copyingTrialLink = ref(false)
let requestGeneration = 0

const identityStamp = useOfficeErpReadIdentity(() => [
  user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null, currentAccount.value?.role ?? null
], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const columns: TableColumn<OfficeErpPortfolioClient>[] = [
  { accessorKey: 'name', header: 'Cliente' },
  { accessorKey: 'tax_id', header: 'CNPJ' },
  { id: 'subscription', header: 'Plano e situação' },
  { id: 'billing', header: 'Próxima data' },
  { id: 'actions', header: '' }
]

const licenceLabels: Record<string, string> = {
  active: 'Assinatura ativa',
  pending: 'Assinatura pendente',
  suspended: 'Assinatura suspensa',
  past_due: 'Assinatura em atraso',
  canceled: 'Assinatura cancelada'
}
const trialLabels = { trialing: 'Em teste', expired: 'Teste encerrado', converted: 'Período de teste convertido' }

function formatOfficeDate(value: string | null | undefined) {
  if (!value) return null
  const date = new Date(`${value.slice(0, 10)}T00:00:00Z`)
  if (!Number.isFinite(date.getTime())) return null
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' }).format(date)
}

async function loadClients() {
  const read = reads.begin('loadClients')
  const request = ++requestGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value) return
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
    const response = await api<OfficeErpPortfolioClientListResponse>('/erp-commercial/clients', { signal: read.signal, params })
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)
      || !Number.isSafeInteger(response.meta.current_page) || !Number.isSafeInteger(response.meta.last_page)
      || ![25, 50, 100].includes(response.meta.per_page)) throw new Error('A resposta da carteira está incompleta.')
    const availablePage = officeErpAvailablePage(response.meta)
    if (page.value !== availablePage) {
      page.value = availablePage
      return
    }
    clients.value = response.data
    total.value = response.meta.total
    lastPage.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (read.current() && request === requestGeneration && identity === identityStamp.value)
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar a carteira agora.'
  } finally {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) loading.value = false
  }
}

async function copyTrialLink() {
  if (copyingTrialLink.value) return
  const read = reads.begin('copyTrialLink')
  copyingTrialLink.value = true
  const identity = identityStamp.value
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_manage_trial_link) {
      toast.add({ title: 'Ação indisponível', description: 'Sua conta não pode gerenciar o link de teste deste escritório.', color: 'warning' })
      return
    }
    const token = context.capture()
    const response = await api<{ data: { configured: boolean, enabled: boolean, url: string | null } }>('/erp-commercial/trial-link', { signal: read.signal })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!response?.data?.configured || !response.data.enabled || !response.data.url) {
      toast.add({ title: 'Link de teste indisponível', description: 'Configure e ative o link de teste nas configurações do ERP.', color: 'warning' })
      return
    }
    const target = new URL(response.data.url, window.location.origin)
    if (target.origin !== window.location.origin || target.search || target.hash || !/^\/erp\/testar\/[a-f\d-]{36}$/i.test(target.pathname))
      throw new Error('O endereço retornado não é um link de teste válido deste escritório.')
    await navigator.clipboard.writeText(target.href)
    toast.add({ title: 'Link de teste copiado', description: 'Compartilhe o link para iniciar um teste de sete dias.', color: 'success' })
  } catch (error) {
    if (read.current() && identity === identityStamp.value)
      toast.add({ title: 'Não foi possível copiar o link', description: apiErrorMessage(error) ?? 'Verifique a conexão e tente novamente.', color: 'error' })
  } finally {
    if (read.current() && identity === identityStamp.value) copyingTrialLink.value = false
  }
}

watch([debouncedQuery, page, perPage], () => {
  void loadClients()
}, { immediate: true })
watch([debouncedQuery, perPage], () => {
  const explicit = explicitCriteria()
  if (explicit && debouncedQuery.value === explicit.q && page.value === explicit.page && perPage.value === explicit.perPage) return
  page.value = 1
}, { flush: 'sync' })
watch(identityStamp, () => {
  requestGeneration++
  copyingTrialLink.value = false
  clients.value = []
  total.value = 0
  confirmedEmpty.value = false
  loadError.value = ''
  void loadClients()
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-col gap-5">
    <OfficeErpPanel title="Clientes ERP" description="Clientes e assinaturas ERP do escritório.">
      <template #action>
        <div class="flex flex-wrap gap-2">
          <UButton
            v-if="context.capabilities.value?.can_manage_trial_link"
            color="neutral"
            variant="outline"
            icon="i-lucide-copy"
            :loading="copyingTrialLink"
            @click="copyTrialLink"
          >
            Copiar link de teste
          </UButton>
          <UButton to="/gestao-erp/clientes/novo" icon="i-lucide-plus">
            Cadastrar cliente ERP
          </UButton>
        </div>
      </template>
      <template #toolbar>
        <UFormField name="client-search" label="Buscar cliente" class="w-full sm:max-w-sm">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Nome ou CNPJ"
            aria-label="Buscar cliente"
            class="w-full"
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
        <OfficeErpDataTable
          v-else
          :data="clients"
          :columns="columns"
          :loading="loading"
        >
          <template #name-cell="{ row }">
            <div class="max-w-72 min-w-56 whitespace-normal">
              <p class="font-medium wrap-anywhere text-highlighted">
                {{ row.original.trade_name || row.original.name }}
              </p><p v-if="row.original.trade_name" class="text-sm wrap-anywhere text-muted">
                {{ row.original.name }}
              </p>
            </div>
          </template>
          <template #tax_id-cell="{ row }">
            <span class="text-sm text-muted">{{ row.original.tax_id || '—' }}</span>
          </template>
          <template #subscription-cell="{ row }">
            <div v-if="row.original.subscription" class="min-w-0">
              <p class="truncate font-medium text-highlighted">
                {{ row.original.subscription.erp_plan?.name || 'Plano ERP' }}
              </p>
              <p class="text-sm text-muted">
                {{ row.original.subscription.trial?.situation === 'trialing' ? trialLabels.trialing : row.original.subscription.trial?.situation === 'expired' ? trialLabels.expired : licenceLabels[row.original.subscription.licence_status || ''] || 'Situação indisponível' }}
              </p>
              <p v-if="row.original.subscription.trial?.situation === 'converted'" class="text-xs text-muted">
                {{ trialLabels.converted }}
              </p>
            </div>
            <span v-else-if="row.original.subscription === null" class="text-sm text-muted">Sem contrato ERP vinculado</span>
            <span v-else class="text-sm text-muted">Resumo do contrato indisponível</span>
          </template>
          <template #billing-cell="{ row }">
            <template v-if="row.original.subscription?.trial?.situation === 'trialing' || row.original.subscription?.trial?.situation === 'expired'">
              <span class="text-sm text-muted">Termina em {{ formatOfficeDate(row.original.subscription.trial.ends_at) || 'data indisponível' }}</span>
            </template>
            <template v-else-if="row.original.subscription">
              <span class="text-sm text-muted">
                {{ formatOfficeDate(row.original.subscription.next_billing_on) ? `Próxima cobrança ${formatOfficeDate(row.original.subscription.next_billing_on)}` : 'Próxima cobrança indisponível' }}
              </span>
            </template>
            <span v-else-if="row.original.subscription === null" class="text-sm text-muted">—</span>
            <span v-else class="text-sm text-muted">Data indisponível</span>
          </template>
          <template #actions-cell="{ row }">
            <div class="flex flex-wrap gap-1">
              <UButton
                v-if="row.original.subscription"
                :to="`/gestao-erp/assinaturas/${row.original.subscription.contract_id}`"
                color="neutral"
                variant="ghost"
                size="sm"
              >
                Ver contrato
              </UButton>
              <UButton
                :to="{
                  path: '/gestao-erp/clientes/novo',
                  query: {
                    cliente: row.original.id,
                    pagina: page,
                    ...(perPage !== 25 ? { porPagina: perPage } : {}),
                    ...(debouncedQuery.trim() ? { q: debouncedQuery.trim() } : {})
                  }
                }"
                color="neutral"
                variant="ghost"
                size="sm"
              >
                Selecionar da carteira
              </UButton>
            </div>
          </template>
          <template #empty>
            <div class="py-8 text-center text-sm text-muted">
              Nenhum resultado nesta página.
            </div>
          </template>
        </OfficeErpDataTable>
        <OfficeErpPagination
          v-if="!loadError && !confirmedEmpty"
          v-model:page="page"
          v-model:per-page="perPage"
          :total="total"
          :loading="loading"
          label="clientes"
        />
      </div>
    </OfficeErpPanel>
  </div>
</template>
