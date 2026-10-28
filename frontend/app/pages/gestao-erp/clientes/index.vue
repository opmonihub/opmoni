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
  { id: 'details', header: '', meta: { class: { th: 'w-14', td: 'w-14' } } },
  { accessorKey: 'name', header: 'Cliente' },
  { id: 'subscription', header: 'Plano e situação', meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } } },
  { id: 'actions', header: '', meta: { class: { th: 'hidden w-32 sm:table-cell', td: 'hidden sm:table-cell' } } }
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

function clientSelectionTarget(clientId: number) {
  return {
    path: '/gestao-erp/clientes/novo',
    query: {
      cliente: clientId,
      pagina: page.value,
      ...(perPage.value !== 25 ? { porPagina: perPage.value } : {}),
      ...(debouncedQuery.value.trim() ? { q: debouncedQuery.value.trim() } : {})
    }
  }
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
    <OfficeErpPanel title="Clientes ERP">
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
          compact
          expandable
          label="Clientes ERP"
        >
          <template #details-cell="{ row }">
            <UButton
              color="neutral"
              variant="ghost"
              :icon="row.getIsExpanded() ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
              :aria-label="row.getIsExpanded() ? 'Ocultar detalhes do cliente' : 'Ver detalhes do cliente'"
              :aria-expanded="row.getIsExpanded()"
              class="size-11 min-w-11"
              @click="row.toggleExpanded()"
            />
          </template>
          <template #name-cell="{ row }">
            <div class="min-w-0">
              <p class="font-medium wrap-anywhere text-highlighted">
                {{ row.original.trade_name || row.original.name }}
              </p>
              <p class="mt-0.5 truncate text-xs text-muted sm:hidden">
                {{ row.original.subscription?.erp_plan?.name || (row.original.subscription === null ? 'Sem assinatura' : 'Resumo indisponível') }}
              </p>
            </div>
          </template>
          <template #subscription-cell="{ row }">
            <div v-if="row.original.subscription" class="min-w-0">
              <p class="max-w-40 truncate font-medium text-highlighted sm:max-w-56">
                {{ row.original.subscription.erp_plan?.name || 'Plano ERP' }}
              </p>
              <p class="truncate text-xs text-muted">
                {{ row.original.subscription.trial?.situation === 'trialing' ? trialLabels.trialing : row.original.subscription.trial?.situation === 'expired' ? trialLabels.expired : licenceLabels[row.original.subscription.licence_status || ''] || 'Situação indisponível' }}
              </p>
            </div>
            <span v-else-if="row.original.subscription === null" class="text-xs text-muted">Sem assinatura</span>
            <span v-else class="text-xs text-muted">Resumo indisponível</span>
          </template>
          <template #actions-cell="{ row }">
            <div class="flex justify-end">
              <UButton
                v-if="row.original.subscription"
                :to="`/gestao-erp/assinaturas/${row.original.subscription.contract_id}`"
                color="neutral"
                variant="outline"
                size="sm"
              >
                Abrir contrato
              </UButton>
              <UButton
                v-else
                :to="clientSelectionTarget(row.original.id)"
                color="neutral"
                variant="outline"
                size="sm"
              >
                Ativar ERP
              </UButton>
            </div>
          </template>
          <template #expanded="{ row }">
            <div class="grid gap-4 p-4 text-sm sm:grid-cols-2">
              <div class="sm:hidden">
                <p class="text-xs text-muted">Plano e situação</p>
                <p class="wrap-anywhere text-highlighted">{{ row.original.subscription?.erp_plan?.name || 'Sem plano' }} · {{ licenceLabels[row.original.subscription?.licence_status || ''] || 'Sem assinatura' }}</p>
              </div>
              <UButton
                :to="row.original.subscription ? `/gestao-erp/assinaturas/${row.original.subscription.contract_id}` : clientSelectionTarget(row.original.id)"
                color="neutral" variant="outline" class="min-h-11 w-fit sm:hidden"
              >
                {{ row.original.subscription ? 'Abrir contrato' : 'Ativar ERP' }}
              </UButton>
              <div>
                <p class="text-xs text-muted">
                  Razão social
                </p>
                <p class="wrap-anywhere font-medium text-highlighted">
                  {{ row.original.name }}
                </p>
              </div>
              <div>
                <p class="text-xs text-muted">
                  CNPJ
                </p>
                <p class="text-highlighted">
                  {{ row.original.tax_id || '—' }}
                </p>
              </div>
              <div>
                <p class="text-xs text-muted">
                  Próxima data
                </p>
                <p v-if="row.original.subscription?.trial?.situation === 'trialing' || row.original.subscription?.trial?.situation === 'expired'" class="text-highlighted">
                  {{ row.original.subscription.trial.situation === 'trialing' ? 'Teste termina' : 'Teste terminou' }} {{ formatOfficeDate(row.original.subscription.trial.ends_at) || '—' }}
                </p>
                <p v-else class="text-highlighted">
                  {{ row.original.subscription ? `Próxima cobrança ${formatOfficeDate(row.original.subscription.next_billing_on) || 'indisponível'}` : '—' }}
                </p>
              </div>
              <div v-if="row.original.subscription?.trial?.situation === 'converted'" class="text-muted">
                {{ trialLabels.converted }}
              </div>
              <UButton
                :to="clientSelectionTarget(row.original.id)"
                color="neutral"
                variant="ghost"
                size="sm"
                class="w-fit"
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
