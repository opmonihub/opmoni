<script setup lang="ts">
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import type { OfficeErpCommercialSummary, OfficeErpCommercialSummaryResponse } from '#shared/types/officeErpCommercial'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'

definePageMeta({ middleware: 'auth' })

const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const { api } = useAccessApi()
const context = useOfficeErpContext()
const summary = ref<OfficeErpCommercialSummary | null>(null)
const loading = ref(false)
const refreshing = ref(false)
const loadError = ref('')
const stale = ref(false)
const permissionMessage = ref('')
let requestGeneration = 0

const identityStamp = useOfficeErpReadIdentity(() => [
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null
], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const capabilities = computed(() => context.capabilities.value)
const empty = computed(() => summary.value !== null
  && summary.value.contracted_licences === 0
  && summary.value.reserved_licences === 0
  && summary.value.invoiced_movement === '0.00'
  && summary.value.effective_received === '0.00'
  && summary.value.outstanding === '0.00'
  && summary.value.overdue === '0.00')
const periodMovements = computed(() => summary.value
  ? [
      { label: 'Faturado', value: formatOfficeErpMoney(summary.value.invoiced_movement) },
      { label: 'Recebido', value: formatOfficeErpMoney(summary.value.effective_received) }
    ]
  : [])
const currentBalances = computed(() => summary.value
  ? [
      { label: 'Em aberto', value: formatOfficeErpMoney(summary.value.outstanding) },
      { label: 'Vencido', value: formatOfficeErpMoney(summary.value.overdue) }
    ]
  : [])
const licenceCounts = computed(() => summary.value
  ? [
      { label: 'Contratadas', value: String(summary.value.contracted_licences) },
      { label: 'Reservas pendentes', value: String(summary.value.reserved_licences) }
    ]
  : [])
const summaryPeriod = computed(() => summary.value ? `${formatDate(summary.value.from_on)} a ${formatDate(summary.value.to_on)}` : '')
const balanceDueCriteria = computed(() => {
  if (summary.value?.due_from && summary.value.due_to) return `Vencimentos de ${formatDate(summary.value.due_from)} a ${formatDate(summary.value.due_to)}`
  if (summary.value?.due_from) return `Vencimentos a partir de ${formatDate(summary.value.due_from)}`
  if (summary.value?.due_to) return `Vencimentos até ${formatDate(summary.value.due_to)}`
  return 'Todos os vencimentos'
})
const saleReasons = computed(() => {
  const reasons = capabilities.value?.reasons ?? {}
  return Object.keys(reasons).map((reason) => {
    const labels: Record<string, string> = {
      subscription_inactive: 'A assinatura do escritório está inativa.',
      commercial_resale_disabled: 'A revenda de licenças ERP não está habilitada no plano do escritório.',
      licence_capacity_reached: 'A capacidade de licenças do escritório foi atingida.'
    }
    return labels[reason] ?? reason
  })
})

function formatDate(value: string): string {
  const [year, month, day] = value.split('-')
  return `${day}/${month}/${year}`
}

function isSummary(value: unknown): value is OfficeErpCommercialSummary {
  if (!value || typeof value !== 'object') return false
  const candidate = value as Partial<OfficeErpCommercialSummary>
  return Number.isSafeInteger(candidate.contracted_licences) && Number.isSafeInteger(candidate.reserved_licences)
    && ['invoiced_movement', 'effective_received', 'outstanding', 'overdue', 'from_on', 'to_on'].every(key => typeof candidate[key as keyof OfficeErpCommercialSummary] === 'string')
    && (candidate.due_from === null || typeof candidate.due_from === 'string')
    && (candidate.due_to === null || typeof candidate.due_to === 'string')
}

function isAuthorityFailure(error: unknown): boolean {
  const status = apiStatus(error)
  if (status === 401 || status === 403) return true
  if (status !== 409 || !error || typeof error !== 'object') return false
  const data = 'data' in error && error.data && typeof error.data === 'object'
    ? error.data as Record<string, unknown>
    : null
  return data?.code === 'account_context_changed'
}

async function loadSummary(): Promise<void> {
  const read = reads.begin('loadSummary')
  const request = ++requestGeneration
  const identity = identityStamp.value
  const hasPriorSummary = summary.value !== null
  loading.value = !hasPriorSummary
  refreshing.value = hasPriorSummary
  loadError.value = ''
  permissionMessage.value = ''

  try {
    await context.refresh()
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value) return
    const currentCapabilities = capabilities.value
    if (!currentCapabilities?.can_read) {
      summary.value = null
      stale.value = false
      permissionMessage.value = currentCapabilities
        ? 'A conta selecionada não tem autorização para consultar o resumo comercial.'
        : 'Não foi possível confirmar as permissões do escritório selecionado.'
      return
    }

    const token = context.capture()
    const response = await api<OfficeErpCommercialSummaryResponse>('/erp-commercial/summary', { signal: read.signal })
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!isSummary(response?.data)) throw new Error('A resposta do resumo comercial está incompleta.')
    summary.value = response.data
    stale.value = false
  } catch (error) {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) {
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível carregar o resumo comercial agora.'
      if (isAuthorityFailure(error)) {
        summary.value = null
        stale.value = false
      } else {
        stale.value = summary.value !== null
      }
    }
  } finally {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) {
      loading.value = false
      refreshing.value = false
    }
  }
}

watch(identityStamp, () => {
  requestGeneration++
  summary.value = null
  loadError.value = ''
  permissionMessage.value = ''
  stale.value = false
  void loadSummary()
}, { flush: 'sync' })

onMounted(() => {
  void loadSummary()
})
</script>

<template>
  <div class="flex min-w-0 shrink-0 flex-col gap-4">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-base font-semibold text-highlighted">
        Resumo
      </h2>
      <UButton
        color="neutral"
        variant="outline"
        icon="i-lucide-refresh-cw"
        size="sm"
        :loading="refreshing"
        :disabled="loading || refreshing"
        @click="loadSummary"
      >
        Atualizar
      </UButton>
    </header>

    <UAlert
      v-if="permissionMessage"
      color="warning"
      title="Resumo indisponível"
      :description="permissionMessage"
    />
    <UAlert
      v-else-if="loadError && !summary"
      color="error"
      title="Não foi possível carregar o resumo"
      :description="loadError"
    >
      <template #description>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span>{{ loadError }}</span>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            @click="loadSummary"
          >
            Tentar novamente
          </UButton>
        </div>
      </template>
    </UAlert>
    <UAlert
      v-else-if="stale && summary"
      color="warning"
      title="Dados desatualizados"
      :description="loadError || 'A atualização falhou. Os valores abaixo são da última consulta confirmada.'"
    />
    <UAlert v-if="capabilities?.can_maintain && !capabilities.can_sell_new && saleReasons.length" color="warning" title="Novas vendas indisponíveis">
      <ul class="list-disc pl-5">
        <li v-for="reason in saleReasons" :key="reason">
          {{ reason }}
        </li>
      </ul>
    </UAlert>

    <div
      v-if="loading && !summary"
      class="flex min-w-0 flex-col gap-4"
      aria-label="Carregando resumo comercial"
      aria-busy="true"
    >
      <USkeleton class="h-52 rounded-lg lg:h-40" />
      <USkeleton class="h-24 rounded-lg sm:h-16" />
    </div>
    <template v-else-if="summary">
      <UAlert
        v-if="empty"
        color="neutral"
        variant="subtle"
        title="Ainda não há movimentação comercial"
        description="Os indicadores serão atualizados quando houver contratos ou cobranças neste escritório."
      />
      <section aria-label="Resumo comercial" class="flex min-w-0 flex-col gap-4">
        <UCard
          as="article"
          aria-labelledby="erp-financial-summary"
          variant="subtle"
          class="min-w-0"
          :ui="{ header: 'px-4 py-3 sm:px-4', body: 'p-0 sm:p-0' }"
        >
          <template #header>
            <h3 id="erp-financial-summary" class="text-sm font-semibold text-highlighted">
              Financeiro
            </h3>
          </template>
          <div class="grid min-w-0 divide-y divide-default lg:grid-cols-2 lg:divide-x lg:divide-y-0">
            <section aria-labelledby="erp-period-movements" class="min-w-0">
              <h4 id="erp-period-movements" class="px-4 pt-3 text-xs font-medium text-muted">
                {{ summaryPeriod }}
              </h4>
              <dl class="grid min-w-0 divide-y divide-default sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div v-for="metric in periodMovements" :key="metric.label" class="min-w-0 space-y-1 px-4 py-3">
                  <dt class="text-xs text-muted">
                    {{ metric.label }}
                  </dt>
                  <dd class="min-w-0 text-xl font-semibold wrap-anywhere tabular-nums text-highlighted">
                    {{ metric.value }}
                  </dd>
                </div>
              </dl>
            </section>
            <section aria-labelledby="erp-current-balances" class="min-w-0">
              <div class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-3 gap-y-1 px-4 pt-3">
                <h4 id="erp-current-balances" class="text-xs font-medium text-muted">
                  Saldos atuais
                </h4>
                <p class="text-xs text-muted">
                  {{ balanceDueCriteria }}
                </p>
              </div>
              <dl class="grid min-w-0 divide-y divide-default sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div v-for="metric in currentBalances" :key="metric.label" class="min-w-0 space-y-1 px-4 py-3">
                  <dt class="text-xs text-muted">
                    {{ metric.label }}
                  </dt>
                  <dd class="min-w-0 text-xl font-semibold wrap-anywhere tabular-nums text-highlighted">
                    {{ metric.value }}
                  </dd>
                </div>
              </dl>
            </section>
          </div>
        </UCard>
        <UCard
          as="article"
          aria-labelledby="erp-licence-summary"
          class="min-w-0"
          :ui="{ body: 'flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-4 sm:py-3' }"
        >
          <h3 id="erp-licence-summary" class="shrink-0 text-sm font-semibold text-highlighted">
            Licenças
          </h3>
          <dl class="grid min-w-0 gap-x-6 gap-y-2 sm:grid-cols-2 sm:gap-x-8 sm:border-l sm:border-default sm:pl-4">
            <div v-for="metric in licenceCounts" :key="metric.label" class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
              <dt class="text-sm text-muted">
                {{ metric.label }}
              </dt>
              <dd class="min-w-0 text-base font-semibold wrap-anywhere tabular-nums text-highlighted">
                {{ metric.value }}
              </dd>
            </div>
          </dl>
        </UCard>
      </section>
    </template>
  </div>
</template>
