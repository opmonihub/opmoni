<script setup lang="ts">
import { officeErpSortParams } from '~/utils/officeErpListSort'
import OfficeErpDataTable from '~/components/office-erp/OfficeErpDataTable.vue'
import OfficeErpPagination from '~/components/office-erp/OfficeErpPagination.vue'
import OfficeErpPanel from '~/components/office-erp/OfficeErpPanel.vue'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import type { TableColumn } from '@nuxt/ui'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

interface OfficeErpContractRow {
  id: number
  erp_plan_id?: number | null
  erp_plan?: { id: number, name: string } | null
  client: { id: number, name: string } | null
  company: { id: number, name: string, owner_setup_status?: 'unclaimed' | 'claimed' } | null
  trial?: { id: number, started_at: string, ends_at: string, converted_at: string | null, expired_at: string | null, situation: 'trialing' | 'expired' | 'converted' } | null
  next_billing_on?: string | null
  agreement_status: string
  licence_status: string | null
  current_revision: { price: string, interval_months: number } | null
  balance: { outstanding: string, invoice_count: number }
}

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const navigation = useOfficeErpListNavigation('contracts', { q: '', page: 1, perPage: 25 as 25 | 50 | 100 }, context)
const sort = listNavigationField(navigation, 'sort')
const direction = listNavigationField(navigation, 'direction')
const query = listNavigationField(navigation, 'q')
const debouncedQuery = refDebounced(query, 300)
const page = listNavigationField(navigation, 'page')
const perPage = listNavigationField(navigation, 'perPage')
const contracts = ref<OfficeErpContractRow[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
let requestGeneration = 0

const identityStamp = useOfficeErpReadIdentity(() => [
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null
], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const columns: TableColumn<OfficeErpContractRow>[] = [
  { id: 'details', header: '', meta: { class: { th: 'w-14' } } },
  { accessorKey: 'client', header: 'Cliente' },
  { id: 'plan', header: 'Plano' },
  { id: 'licence', header: 'Situação', meta: { class: { th: 'w-28' } } },
  { id: 'actions', header: '', meta: { class: { th: 'w-32' } } }
]
const agreementStatusLabels: Record<string, string> = {
  draft: 'Rascunho',
  awaiting_owner: 'Aguardando confirmação do responsável',
  accepted: 'Aceito · aguardando ativação',
  effective: 'Vigente',
  canceled: 'Cancelado'
}
const trialStatusLabels = { trialing: 'Em teste', expired: 'Teste encerrado', converted: 'Período de teste convertido' } as const
const licenceStatusLabels: Record<string, string> = {
  active: 'Ativa',
  pending: 'Pendente',
  suspended: 'Suspensa',
  past_due: 'Em atraso',
  canceled: 'Cancelada',
  trialing: 'Em teste',
  trial_expired: 'Teste encerrado'
}

function formatDate(value: string) {
  const date = new Date(value)
  if (!Number.isFinite(date.getTime())) return 'Data indisponível'
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' }).format(date)
}

async function loadContracts() {
  const read = reads.begin('loadContracts')
  const request = ++requestGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) {
      contracts.value = []
      total.value = 0
      loadError.value = 'A conta selecionada não tem permissão para consultar os contratos do escritório.'
      return
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value, ...officeErpSortParams('contracts', sort.value, direction.value) }
    if (debouncedQuery.value.trim()) params.q = debouncedQuery.value.trim()
    const response = await api<{ data: OfficeErpContractRow[], meta: { current_page: number, last_page: number, per_page: number, total: number } }>('/erp-commercial/contracts', { signal: read.signal, params })
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total))
      throw new Error('A resposta da lista de contratos está incompleta.')
    const availablePage = officeErpAvailablePage(response.meta)
    if (page.value !== availablePage) {
      page.value = availablePage
      return
    }
    contracts.value = response.data
    total.value = response.meta.total
    lastPage.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (read.current() && request === requestGeneration && identity === identityStamp.value)
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar os contratos agora.'
  } finally {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) loading.value = false
  }
}

watch([debouncedQuery, perPage, sort, direction], () => {
  page.value = 1
}, { flush: 'sync' })
watch([page, debouncedQuery, perPage, identityStamp, sort, direction], () => {
  void loadContracts()
}, { immediate: true, flush: 'post' })
watch(identityStamp, () => {
  requestGeneration++
  contracts.value = []
  total.value = 0
  confirmedEmpty.value = false
  loadError.value = ''
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-col gap-5">
    <OfficeErpPanel title="Assinaturas" description="Contratos e situação das licenças ERP.">
      <template #toolbar>
        <UFormField label="Buscar contrato" class="w-full sm:max-w-sm">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Nome do cliente"
            aria-label="Buscar contrato"
            class="w-full"
          />
        </UFormField>

        <DataTableSortMenu
          :sort="sort"
          :direction="direction"
          :fields="[{ value: 'client', label: 'Cliente' }, { value: 'agreement_status', label: 'Contrato' }, { value: 'id', label: 'Cadastro' }]"
          @change="ordering => navigation = { ...navigation, ...ordering, page: 1 }"
        />
      </template>
      <div :class="panelBodyClass">
        <UAlert
          v-if="loadError"
          color="error"
          title="Não foi possível carregar os contratos"
          :description="loadError"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>{{ loadError }}</span>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                @click="loadContracts"
              >
                Tentar novamente
              </UButton>
            </div>
          </template>
        </UAlert>
        <UAlert
          v-else-if="confirmedEmpty"
          color="neutral"
          title="Nenhum contrato encontrado"
          description="Contratos aceitos aparecerão aqui depois da confirmação do responsável."
        />
        <div v-else class="min-w-0">
          <OfficeErpDataTable
            :columns="columns"
            :get-row-id="row => String(row.id)"
            :mobile-columns="['details', 'client']"
            :data="contracts"
            :loading="loading"
            compact
            expandable
            label="Assinaturas"
          >
            <template #client-header>
              <DataTableSortButton
                label="Cliente"
                :sorted="sort === 'client' ? direction === 'desc' ? 'desc' : 'asc' : false"
                @toggle="navigation = { ...navigation, sort: sort === 'client' && direction === 'desc' ? '' : 'client', direction: sort === 'client' && direction === 'asc' ? 'desc' : 'asc', page: 1 }"
              />
            </template>
            <template #details-cell="{ row }">
              <UButton
                color="neutral"
                variant="ghost"
                :icon="row.getIsExpanded() ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
                :aria-label="row.getIsExpanded() ? 'Ocultar detalhes do contrato' : 'Ver detalhes do contrato'"
                :aria-expanded="row.getIsExpanded()"
                class="size-11 min-w-11"
                @click="row.toggleExpanded()"
              />
            </template>
            <template #client-cell="{ row }">
              <div class="min-w-0">
                <p class="max-w-56 wrap-anywhere font-medium text-highlighted">
                  {{ row.original.client?.name ?? 'Cliente excluído' }}
                </p>
              </div>
            </template>
            <template #plan-cell="{ row }">
              <div class="min-w-0">
                <p class="max-w-40 truncate font-medium text-highlighted sm:max-w-56">
                  {{ row.original.erp_plan?.name ?? (row.original.erp_plan_id ? `Plano #${row.original.erp_plan_id}` : 'Plano indisponível') }}
                </p>
                <p v-if="row.original.current_revision" class="truncate text-xs text-muted">
                  {{ formatOfficeErpMoney(row.original.current_revision.price) }} · {{ row.original.current_revision.interval_months === 1 ? 'mensal' : `${row.original.current_revision.interval_months} meses` }}
                </p>
                <p v-else class="text-xs text-muted">
                  Termos indisponíveis
                </p>
              </div>
            </template>
            <template #licence-cell="{ row }">
              <UBadge :color="row.original.licence_status === 'active' ? 'success' : 'warning'" variant="subtle">
                {{ row.original.trial?.situation === 'trialing' ? 'Em teste' : row.original.trial?.situation === 'expired' ? 'Teste encerrado' : licenceStatusLabels[row.original.licence_status ?? ''] ?? 'Situação não reconhecida' }}
              </UBadge>
            </template>
            <template #actions-cell="{ row }">
              <UButton
                :to="`/gestao-erp/assinaturas/${row.original.id}`"
                variant="outline"
                size="sm"
              >
                Abrir
              </UButton>
            </template>
            <template #expanded="{ row }">
              <div class="grid gap-x-6 gap-y-3 px-4 py-3 text-sm sm:grid-cols-3">
                <div class="md:hidden">
                  <p class="text-xs text-muted">
                    Plano e valor
                  </p>
                  <p class="wrap-anywhere text-highlighted">
                    {{ row.original.erp_plan?.name ?? 'Plano indisponível' }}
                  </p>
                  <p v-if="row.original.current_revision" class="tabular-nums">
                    {{ formatOfficeErpMoney(row.original.current_revision.price) }} · {{ row.original.current_revision.interval_months === 1 ? 'Mensal' : `A cada ${row.original.current_revision.interval_months} meses` }}
                  </p>
                </div>
                <div class="md:hidden">
                  <p class="text-xs text-muted">
                    Licença
                  </p>
                  <p>{{ row.original.trial?.situation === 'trialing' ? 'Em teste' : row.original.trial?.situation === 'expired' ? 'Teste encerrado' : licenceStatusLabels[row.original.licence_status ?? ''] ?? 'Situação não reconhecida' }}</p>
                </div>
                <div>
                  <p class="text-xs text-muted">
                    Empresa ERP
                  </p>
                  <p class="wrap-anywhere text-highlighted">
                    {{ row.original.company?.name ?? 'Empresa indisponível' }}
                  </p>
                </div>
                <div>
                  <p class="text-xs text-muted">
                    Contrato
                  </p>
                  <p class="text-highlighted">
                    {{ agreementStatusLabels[row.original.agreement_status] ?? 'Situação não reconhecida' }}
                  </p>
                </div>
                <div>
                  <p class="text-xs text-muted">
                    Acesso do responsável
                  </p>
                  <p class="text-highlighted">
                    {{ row.original.company?.owner_setup_status === 'claimed' ? 'Configurado' : row.original.company?.owner_setup_status === 'unclaimed' ? 'Aguardando configuração' : 'Indisponível' }}
                  </p>
                </div>
                <div>
                  <p class="text-xs text-muted">
                    Próxima cobrança
                  </p>
                  <p class="text-highlighted">
                    {{ formatDate(row.original.next_billing_on ?? '') }}
                  </p>
                </div>
                <div>
                  <p class="text-xs text-muted">
                    Saldo em aberto
                  </p>
                  <p class="text-highlighted">
                    {{ formatOfficeErpMoney(row.original.balance.outstanding) }} · {{ row.original.balance.invoice_count }} faturas
                  </p>
                </div>
                <div v-if="row.original.trial">
                  <p class="text-xs text-muted">
                    Teste
                  </p>
                  <p class="text-highlighted">
                    {{ trialStatusLabels[row.original.trial.situation] }} · {{ row.original.trial.situation === 'trialing' ? 'encerra' : 'encerrou' }} {{ formatDate(row.original.trial.ends_at) }}
                  </p>
                </div>
                <UButton
                  :to="`/gestao-erp/assinaturas/${row.original.id}`"
                  class="w-fit"
                  variant="ghost"
                  size="sm"
                >
                  Abrir contrato
                </UButton>
              </div>
            </template>

            <template #empty>
              <div class="py-8 text-center text-sm text-muted">
                Nenhum contrato nesta página.
              </div>
            </template>
          </OfficeErpDataTable>
        </div>
        <OfficeErpPagination
          v-if="!loadError && !confirmedEmpty && total > 0"
          v-model:page="page"
          v-model:per-page="perPage"
          :total="total"
          :loading="loading"
          label="contratos"
        />
      </div>
    </OfficeErpPanel>
  </div>
</template>
