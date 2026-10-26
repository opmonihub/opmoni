<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

interface OfficeErpContractRow {
  id: number
  erp_plan_id?: number | null
  erp_plan?: { id: number, name: string } | null
  client: { id: number, name: string }
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
const query = ref('')
const debouncedQuery = refDebounced(query, 300)
const page = ref(1)
const perPage = ref<25 | 50 | 100>(25)
const contracts = ref<OfficeErpContractRow[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
let requestGeneration = 0

const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null,
  context.capabilities.value?.capability_key ?? null
]))
const columns: TableColumn<OfficeErpContractRow>[] = [
  { accessorKey: 'client', header: 'Cliente' },
  { id: 'terms', header: 'Termos' },
  { id: 'agreement', header: 'Contrato' },
  { id: 'licence', header: 'Licença' },
  { id: 'actions', header: '' }
]
const agreementStatusLabels: Record<string, string> = {
  draft: 'Rascunho',
  awaiting_owner: 'Aguardando confirmação do responsável',
  accepted: 'Aceito · aguardando ativação',
  effective: 'Vigente',
  canceled: 'Cancelado'
}
const trialStatusLabels = { trialing: 'Em teste', expired: 'Teste encerrado', converted: 'Convertido' } as const

function formatDate(value: string) {
  const date = new Date(value)
  if (!Number.isFinite(date.getTime())) return 'Data indisponível'
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' }).format(date)
}

async function loadContracts() {
  const request = ++requestGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (request !== requestGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) {
      contracts.value = []
      total.value = 0
      loadError.value = 'A conta selecionada não tem permissão para consultar os contratos do escritório.'
      return
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value }
    if (debouncedQuery.value.trim()) params.q = debouncedQuery.value.trim()
    const response = await api<{ data: OfficeErpContractRow[], meta: { current_page: number, last_page: number, per_page: number, total: number } }>('/erp-commercial/contracts', { params })
    if (request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total))
      throw new Error('A resposta da lista de contratos está incompleta.')
    contracts.value = response.data
    total.value = response.meta.total
    lastPage.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (request === requestGeneration && identity === identityStamp.value)
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar os contratos agora.'
  } finally {
    if (request === requestGeneration && identity === identityStamp.value) loading.value = false
  }
}

watch([debouncedQuery, perPage], () => {
  page.value = 1
})
watch([page, debouncedQuery, perPage, identityStamp], () => {
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
  <div class="flex min-h-0 flex-col gap-4">
    <DataTablePanelList title="Assinaturas" description="Contratos aceitos, termos vigentes e situação da licença ERP.">
      <template #toolbar>
        <UFormField label="Buscar contrato" class="w-full max-w-sm">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Nome do cliente"
            aria-label="Buscar contrato"
          />
        </UFormField>
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
        <div v-else class="overflow-x-auto">
          <UTable
            :columns="columns"
            :data="contracts"
            :loading="loading"
            :ui="panelTableUi"
          >
            <template #client-cell="{ row }">
              <div class="min-w-40 py-1">
                <p class="font-medium text-highlighted">
                  {{ row.original.client.name }}
                </p>
                <p v-if="row.original.company" class="mt-1 text-xs text-muted">
                  {{ row.original.company.name }}
                </p>
                <p v-if="row.original.erp_plan_id" class="mt-1 text-xs text-muted">
                  Plano ERP: {{ row.original.erp_plan?.name ?? `#${row.original.erp_plan_id}` }}
                </p>
                <p v-if="row.original.company?.owner_setup_status" class="mt-1 text-xs text-muted">
                  Acesso do responsável: {{ row.original.company.owner_setup_status === 'claimed' ? 'configurado' : 'aguardando configuração' }}
                </p>
              </div>
            </template>
            <template #terms-cell="{ row }">
              <span v-if="row.original.current_revision" class="text-sm text-muted">
                {{ formatOfficeErpMoney(row.original.current_revision.price) }} · {{ row.original.current_revision.interval_months === 1 ? 'mensal' : `a cada ${row.original.current_revision.interval_months} meses` }}
              </span>
              <span v-else class="text-sm text-muted">Sem revisão</span>
            </template>
            <template #agreement-cell="{ row }">
              <UBadge :color="row.original.agreement_status === 'effective' ? 'success' : 'neutral'" variant="subtle">
                {{ agreementStatusLabels[row.original.agreement_status] ?? 'Situação não reconhecida' }}
              </UBadge>
            </template>
            <template #licence-cell="{ row }">
              <UBadge :color="row.original.licence_status === 'active' ? 'success' : 'warning'" variant="subtle">
                {{ row.original.trial ? trialStatusLabels[row.original.trial.situation] : row.original.licence_status === 'active' ? 'Ativa' : row.original.licence_status === 'suspended' ? 'Suspensa' : row.original.licence_status === 'past_due' ? 'Em atraso' : row.original.licence_status === 'canceled' ? 'Cancelada' : row.original.licence_status === 'trialing' ? 'Em teste' : row.original.licence_status === 'trial_expired' ? 'Teste encerrado' : row.original.licence_status === 'pending' ? 'Pendente' : 'Situação não reconhecida' }}
              </UBadge>
              <p v-if="row.original.trial" class="mt-1 text-xs text-muted">
                {{ row.original.trial.situation === 'trialing' ? 'Encerra' : row.original.trial.situation === 'expired' ? 'Encerrou' : 'Período original até' }} {{ formatDate(row.original.trial.ends_at) }}
              </p>
              <p v-else-if="row.original.next_billing_on" class="mt-1 text-xs text-muted">
                Próxima cobrança {{ formatDate(row.original.next_billing_on) }}
              </p>
            </template>
            <template #actions-cell="{ row }">
              <UButton
                :to="`/gestao-erp/assinaturas/${row.original.id}`"
                color="neutral"
                variant="ghost"
                size="sm"
              >
                Abrir contrato
              </UButton>
            </template>
            <template #empty>
              <div class="py-8 text-center text-sm text-muted">
                Nenhum contrato nesta página.
              </div>
            </template>
          </UTable>
        </div>
        <div v-if="!loadError && !confirmedEmpty && total > 0" :class="panelFooterClass">
          <span :class="panelFooterCountClass">{{ total }} contratos · página {{ page }} de {{ lastPage }}</span>
          <div :class="panelPaginationClass">
            <UPagination
              v-model:page="page"
              :total="total"
              :items-per-page="perPage"
              :disabled="loading"
            />
          </div>
        </div>
      </div>
    </DataTablePanelList>
  </div>
</template>
