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
const metrics = computed(() => summary.value
  ? [
      { label: 'Licenças contratadas', value: String(summary.value.contracted_licences), icon: 'i-lucide-key-round', detail: 'Acessos ocupados por contratos aceitos' },
      { label: 'Reservas pendentes', value: String(summary.value.reserved_licences), icon: 'i-lucide-hourglass', detail: 'Vagas temporariamente reservadas' },
      { label: 'Movimentação faturada', value: formatOfficeErpMoney(summary.value.invoiced_movement), icon: 'i-lucide-file-text', detail: `${formatDate(summary.value.from_on)} a ${formatDate(summary.value.to_on)}` },
      { label: 'Recebido efetivamente', value: formatOfficeErpMoney(summary.value.effective_received), icon: 'i-lucide-circle-check', detail: 'Recebimentos não estornados no período' },
      { label: 'Saldo em aberto', value: formatOfficeErpMoney(summary.value.outstanding), icon: 'i-lucide-wallet', detail: 'Saldo atual das cobranças emitidas' },
      { label: 'Saldo vencido', value: formatOfficeErpMoney(summary.value.overdue), icon: 'i-lucide-calendar-clock', detail: 'Saldo atual de cobranças vencidas' }
    ]
  : [])
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
  <div class="flex shrink-0 flex-col gap-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-2xl">
        <h1 class="text-base font-semibold text-highlighted">
          Visão geral
        </h1>
        <p class="mt-2 text-sm text-muted">
          Clientes, assinaturas e cobranças do escritório.
        </p>
      </div>
      <UButton
        color="neutral"
        variant="outline"
        icon="i-lucide-refresh-cw"
        :loading="refreshing"
        :disabled="loading || refreshing"
        @click="loadSummary"
      >
        Atualizar
      </UButton>
    </header>

    <section aria-labelledby="erp-start" class="flex flex-col gap-5 rounded-xl border border-default bg-default p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
      <div class="max-w-2xl space-y-2">
        <h2 id="erp-start" class="text-lg font-semibold text-highlighted">
          Ative o ERP para um cliente
        </h2>
        <p class="text-sm text-muted">
          Selecione um cliente e defina o plano para ativar a assinatura.
        </p>
      </div>
      <div class="flex shrink-0 flex-col gap-2">
        <UButton to="/gestao-erp/clientes/novo" icon="i-lucide-user-plus" class="justify-center">
          Cadastrar cliente e ativar ERP
        </UButton>
        <UButton
          to="/gestao-erp/clientes"
          color="neutral"
          variant="ghost"
          trailing-icon="i-lucide-arrow-right"
          class="justify-center"
        >
          Consultar clientes da carteira
        </UButton>
      </div>
    </section>

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
      class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
      aria-label="Carregando resumo comercial"
      aria-busy="true"
    >
      <div v-for="item in 6" :key="item" class="h-36 animate-pulse rounded-lg border border-muted bg-elevated/50" />
    </div>
    <template v-else-if="summary">
      <UAlert
        v-if="empty"
        color="neutral"
        variant="subtle"
        title="Ainda não há movimentação comercial"
        description="Os indicadores serão atualizados quando houver contratos ou cobranças neste escritório."
      />
      <section aria-labelledby="erp-summary" class="space-y-4">
        <h2 id="erp-summary" class="text-base font-semibold text-highlighted">
          Resumo comercial
        </h2>
        <dl class="grid grid-cols-1 gap-x-8 gap-y-5 border-y border-default py-5 sm:grid-cols-2 xl:grid-cols-3">
          <div v-for="metric in metrics" :key="metric.label" class="min-w-0 space-y-1">
            <dt class="text-sm text-muted">
              {{ metric.label }}
            </dt>
            <dd class="break-words text-xl font-semibold tabular-nums text-highlighted">
              {{ metric.value }}
            </dd>
            <dd class="text-xs text-muted">
              {{ metric.detail }}
            </dd>
          </div>
        </dl>
      </section>
    </template>

    <section aria-labelledby="atalhos-comerciais" class="space-y-3">
      <h2 id="atalhos-comerciais" class="text-base font-semibold text-highlighted">
        Continue a operação
      </h2>
      <div class="divide-y divide-default">
        <NuxtLink
          v-for="item in [
            { title: 'Assinaturas', detail: 'Acompanhe contratos, licenças e acesso dos responsáveis.', to: '/gestao-erp/assinaturas', icon: 'i-lucide-file-key' },
            { title: 'Faturas e cobranças', detail: 'Consulte valores em aberto, vencimentos e documentos emitidos.', to: '/gestao-erp/faturas', icon: 'i-lucide-receipt' },
            { title: 'Recebimentos', detail: 'Registre pagamentos e acompanhe suas alocações.', to: '/gestao-erp/recebimentos', icon: 'i-lucide-banknote' },
            { title: 'Planos do escritório', detail: 'Prepare os termos comerciais para novas vendas.', to: '/gestao-erp/planos', icon: 'i-lucide-tags' }
          ]"
          :key="item.to"
          :to="item.to"
          class="group flex items-center gap-3 rounded-lg px-2 py-4 transition-colors hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary"
        >
          <UIcon :name="item.icon" class="size-5 shrink-0 text-muted" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-highlighted">{{ item.title }}</p>
            <p class="mt-0.5 text-sm text-muted">{{ item.detail }}</p>
          </div>
          <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted" />
        </NuxtLink>
      </div>
    </section>
  </div>
</template>
