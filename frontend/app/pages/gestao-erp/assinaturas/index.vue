<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { officeErpSortParams } from '~/utils/officeErpListSort'
import { panelBodyClass } from '~/components/data-table/panel'

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
  { accessorKey: 'name', header: 'Empresa' },
  { id: 'plan', header: 'Plano' },
  { id: 'situation', header: 'Situação' },
  { id: 'next_billing', header: 'Próxima cobrança' },
  { id: 'actions', header: '' }
]
function date(value: string | null | undefined) {
  if (!value) return '—'
  const parsed = new Date(`${value.slice(0, 10)}T00:00:00Z`)
  return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeZone: 'UTC' }).format(parsed) : 'Data indisponível'
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
    <OfficeErpPanel title="Assinaturas" description="Acompanhe a assinatura atual de cada empresa e seu histórico comercial.">
      <template #action>
        <UButton
          color="neutral"
          variant="outline"
          to="/gestao-erp/assinaturas/contratos"
          icon="i-lucide-files"
        >
          Ver contratos
        </UButton>
      </template>
      <template #toolbar>
        <UFormField label="Buscar empresa" class="w-full sm:max-w-sm">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Nome da empresa"
            aria-label="Buscar empresa"
            class="w-full"
          />
        </UFormField>
        <div class="flex gap-2">
          <UButton
            size="sm"
            color="neutral"
            variant="outline"
            :aria-pressed="sort === 'name'"
            @click="ordered('name')"
          >
            Nome
          </UButton><UButton
            size="sm"
            color="neutral"
            variant="outline"
            :aria-pressed="sort === 'registered_at'"
            @click="ordered('registered_at')"
          >
            Cadastro
          </UButton>
        </div>
      </template>
      <div :class="panelBodyClass">
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
          description="Empresas com assinatura aparecerão nesta lista. Consulte os contratos para ver registros legados."
        />
        <template v-else>
          <OfficeErpDataTable
            :columns="columns"
            :data="rows"
            :loading="loading"
            :get-row-id="row => String(row.id)"
            :mobile-columns="['name', 'situation']"
            compact
            label="Assinaturas por empresa"
          >
            <template #name-cell="{ row }">
              <NuxtLink class="font-medium text-highlighted underline-offset-4 hover:underline" :to="`/gestao-erp/assinaturas/empresas/${row.original.id}`">{{ row.original.name }}</NuxtLink>
            </template>
            <template #plan-cell="{ row }">
              {{ row.original.subscription?.erp_plan?.name ?? '—' }}
            </template>
            <template #situation-cell="{ row }">
              <UBadge :color="statusColor(row.original)" variant="subtle">
                {{ statusLabel(row.original) }}
              </UBadge><span v-if="row.original.current_contract?.trial?.situation === 'trialing'" class="mt-1 block text-xs text-muted">Teste até {{ date(row.original.current_contract.trial.ends_at) }}</span>
            </template>
            <template #next_billing-cell="{ row }">
              {{ date(row.original.current_contract?.next_billing_on) }}
            </template>
            <template #actions-cell="{ row }">
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                :to="`/gestao-erp/assinaturas/empresas/${row.original.id}`"
              >
                Detalhes
              </UButton>
            </template>
          </OfficeErpDataTable>
          <OfficeErpPagination
            v-model:page="page"
            v-model:per-page="perPage"
            :total="total"
            label="empresas"
            :loading="loading"
          />
        </template>
      </div>
    </OfficeErpPanel>
  </div>
</template>
