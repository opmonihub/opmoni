<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { officeErpSortParams } from '~/utils/officeErpListSort'
import { companyListBodyClass, companyListFooterClass } from '~/components/office-erp/companyList'
import { formatTaxId } from '~/utils/taxId'

definePageMeta({ middleware: 'auth' })

interface SubscriptionCompanyRow {
  id: number
  registration_id: number
  client_id: number
  name: string
  cnpj: string
  registered_at: string
  owner_setup_status: 'unclaimed' | 'claimed'
  current_contract_id: number | null
  linked_contract_id: number | null
  relationship: 'current' | 'unassigned' | 'historical'
  subscription: { id: number, status: string, erp_plan_id: number | null, erp_plan: { id: number, name: string } | null } | null
  current_contract: { id: number, agreement_status: string, next_billing_on?: string | null, cancel_effective_on?: string | null, trial?: { ends_at: string, situation: 'trialing' | 'expired' | 'converted' } | null, balance?: { outstanding: string } | null } | null
}
interface SubscriptionCompanyResponse {
  data: SubscriptionCompanyRow[]
  meta: { current_page: number, last_page: number, per_page: 25 | 50 | 100, total: number }
}
const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const navigation = useOfficeErpListNavigation('subscription-companies', { q: '', page: 1, perPage: 25 as 25 | 50 | 100 }, context)
const query = listNavigationField(navigation, 'q')
const page = listNavigationField(navigation, 'page')
const perPage = listNavigationField(navigation, 'perPage')
const sort = listNavigationField(navigation, 'sort')
const direction = listNavigationField(navigation, 'direction')
const debouncedQuery = refDebounced(query, 300)
const rows = ref<SubscriptionCompanyRow[]>([])
const total = ref(0)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
let requestGeneration = 0
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const columns: TableColumn<SubscriptionCompanyRow>[] = [
  { accessorKey: 'name', header: 'Empresa', enableSorting: true, meta: { class: { th: 'min-w-0', td: 'min-w-0' } } },
  { id: 'plan', header: 'Plano e situação' },
  { id: 'next_billing', header: 'Próxima cobrança', meta: { class: { th: 'w-36', td: 'tabular-nums' } } },
  { id: 'actions', header: '', meta: { class: { th: 'w-36', td: 'text-right whitespace-nowrap' } } }
]
const tableSorting = computed(() => sort.value ? [{ id: sort.value, desc: direction.value === 'desc' }] : [])

function date(value: string | null | undefined) {
  if (!value) return '—'
  const parsed = new Date(`${value.slice(0, 10)}T00:00:00Z`)
  return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' }).format(parsed) : 'Data indisponível'
}
function statusLabel(row: SubscriptionCompanyRow) {
  if (row.relationship === 'historical') return 'Histórico'
  if (row.relationship === 'unassigned' || !row.current_contract) return 'Sem assinatura'
  const agreement = row.current_contract.agreement_status
  if (row.current_contract.trial?.situation === 'trialing') return 'Em teste'
  if (row.current_contract.trial?.situation === 'expired') return 'Teste expirado'
  if (row.current_contract.trial?.situation === 'converted') return 'Teste convertido'
  if (agreement === 'accepted') return 'Aguardando ativação'
  return row.subscription?.status === 'active' ? 'Ativa' : row.subscription?.status === 'pending' ? 'Pendente' : row.subscription?.status ?? 'Sem situação'
}
function statusColor(row: SubscriptionCompanyRow) {
  if (row.relationship === 'historical') return 'warning'
  if (!row.current_contract) return 'neutral'
  return row.current_contract.agreement_status === 'effective' ? 'success' : 'info'
}
function ordered(field: 'name' | 'registered_at') {
  navigation.value = { ...navigation.value, sort: field, direction: sort.value === field && direction.value === 'asc' ? 'desc' : 'asc', page: 1 }
}
async function loadCompanies() {
  const read = reads.begin('loadCompanies')
  const request = ++requestGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) {
      rows.value = []
      total.value = 0
      loadError.value = 'A conta selecionada não tem permissão para consultar as assinaturas do escritório.'
      return
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value, ...officeErpSortParams('subscription-companies', sort.value, direction.value) }
    if (debouncedQuery.value.trim()) params.search = debouncedQuery.value.trim()
    const response = await api<SubscriptionCompanyResponse>('/erp-commercial/subscription-companies', { signal: read.signal, params })
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta das assinaturas por empresa está incompleta.')
    const available = officeErpAvailablePage(response.meta)
    if (page.value !== available) {
      page.value = available
      return
    }
    rows.value = response.data
    total.value = response.meta.total
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar as assinaturas agora.'
  } finally {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) loading.value = false
  }
}
watch([debouncedQuery, page, perPage, sort, direction, identityStamp], () => {
  void loadCompanies()
}, { immediate: true })
watch([debouncedQuery, perPage, sort, direction], () => {
  page.value = 1
}, { flush: 'sync' })
watch(identityStamp, () => {
  requestGeneration++
  rows.value = []
  total.value = 0
  confirmedEmpty.value = false
  loadError.value = ''
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-col gap-5">
    <OfficeErpPanel title="Assinaturas" description="Planos e acesso ao ERP, organizados por empresa." company-list>
      <template #toolbar>
        <UInput
          v-model="query"
          icon="i-lucide-search"
          placeholder="Buscar por nome ou CNPJ"
          aria-label="Buscar empresa"
          class="w-full sm:max-w-sm"
        />
      </template>
      <div :class="companyListBodyClass">
        <UAlert
          v-if="loadError"
          color="error"
          title="Não foi possível carregar as assinaturas"
          :description="loadError"
        >
          <template #actions>
            <UButton color="neutral" variant="outline" @click="loadCompanies">
              Tentar novamente
            </UButton>
          </template>
        </UAlert>
        <UAlert
          v-else-if="confirmedEmpty"
          color="neutral"
          title="Nenhuma assinatura encontrada"
          description="Empresas com assinatura aparecerão nesta lista. O histórico fica nos detalhes de cada empresa."
        />
        <template v-else>
          <OfficeErpDataTable
            :columns="columns"
            :data="rows"
            :loading="loading"
            :get-row-id="row => String(row.id)"
            :mobile-columns="['name', 'actions']"
            compact
            label="Assinaturas por empresa"
            :sorting="tableSorting"
          >
            <template #name-header>
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                class="-mx-2 min-h-8"
                :trailing-icon="sort === 'name' ? (direction === 'asc' ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down') : 'i-lucide-arrow-up-down'"
                @click="ordered('name')"
              >
                Empresa
              </UButton>
            </template>
            <template #name-cell="{ row }">
              <div class="min-w-0 space-y-1">
                <NuxtLink class="block break-words font-semibold text-highlighted underline-offset-4 hover:underline" :to="`/gestao-erp/assinaturas/empresas/${row.original.id}`">{{ row.original.name }}</NuxtLink>
                <p class="text-xs text-muted">
                  {{ formatTaxId(row.original.cnpj) }}
                </p>
                <div class="space-y-1 md:hidden">
                  <p class="text-xs text-muted">
                    {{ row.original.subscription?.erp_plan?.name ?? 'Plano indisponível' }}
                  </p>
                  <UBadge :color="statusColor(row.original)" variant="subtle" size="sm">
                    {{ statusLabel(row.original) }}
                  </UBadge>
                  <span v-if="row.original.current_contract?.trial?.situation === 'trialing'" class="block text-xs text-muted">Teste até {{ date(row.original.current_contract.trial.ends_at) }}</span>
                  <p class="text-xs text-muted">
                    Próxima cobrança {{ date(row.original.current_contract?.next_billing_on) }}
                  </p>
                </div>
              </div>
            </template>
            <template #plan-cell="{ row }">
              <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                <p class="font-medium text-highlighted">
                  {{ row.original.subscription?.erp_plan?.name ?? '—' }}
                </p>
                <UBadge :color="statusColor(row.original)" variant="subtle" size="sm">
                  {{ statusLabel(row.original) }}
                </UBadge>
                <span v-if="row.original.current_contract?.trial?.situation === 'trialing'" class="block text-xs text-muted">Teste até {{ date(row.original.current_contract.trial.ends_at) }}</span>
              </div>
            </template>
            <template #next_billing-cell="{ row }">
              {{ date(row.original.current_contract?.next_billing_on) }}
            </template>
            <template #actions-cell="{ row }">
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                label="Ver detalhes"
                trailing-icon="i-lucide-chevron-right"
                :aria-label="`Ver detalhes de ${row.original.name}`"
                :to="`/gestao-erp/assinaturas/empresas/${row.original.id}`"
              />
            </template>
          </OfficeErpDataTable>
          <OfficeErpPagination
            v-model:page="page"
            v-model:per-page="perPage"
            :class="companyListFooterClass"
            :total="total"
            label="empresas"
            :loading="loading"
          />
        </template>
      </div>
      <template #hint>
        <UIcon name="i-lucide-mouse-pointer-2" class="mt-0.5 size-3.5 shrink-0" />
        Abra uma empresa para consultar a assinatura, ativar o ERP e configurar o acesso.
      </template>
    </OfficeErpPanel>
  </div>
</template>
