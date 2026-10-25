<script setup lang="ts">
import type { OfficeErpCommercialSummary, OfficeErpCommercialSummaryResponse } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
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

const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null,
  context.capabilities.value?.capability_key ?? null
]))
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

async function loadSummary(): Promise<void> {
  const request = ++requestGeneration
  const identity = identityStamp.value
  const hasPriorSummary = summary.value !== null
  loading.value = !hasPriorSummary
  refreshing.value = hasPriorSummary
  loadError.value = ''
  permissionMessage.value = ''

  try {
    await context.refresh()
    if (request !== requestGeneration || identity !== identityStamp.value) return
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
    const response = await api<OfficeErpCommercialSummaryResponse>('/erp-commercial/summary')
    if (request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!isSummary(response?.data)) throw new Error('A resposta do resumo comercial está incompleta.')
    summary.value = response.data
    stale.value = false
  } catch (error) {
    if (request === requestGeneration && identity === identityStamp.value) {
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível carregar o resumo comercial agora.'
      stale.value = summary.value !== null
    }
  } finally {
    if (request === requestGeneration && identity === identityStamp.value) {
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
        <p class="text-sm font-medium text-primary">
          Comercial do escritório
        </p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-highlighted">
          Visão geral
        </h1>
        <p class="mt-2 text-sm text-muted">
          Acompanhe licenças, faturamento e recebimentos do ERP vendidos por este escritório.
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
        title="Ainda não há movimentação comercial"
        description="Os indicadores serão atualizados quando houver contratos ou cobranças neste escritório."
      />
      <UPageGrid class="gap-4 sm:gap-6 lg:grid-cols-3 lg:gap-px">
        <UPageCard
          v-for="metric in metrics"
          :key="metric.label"
          :title="metric.label"
          :description="metric.detail"
          :icon="metric.icon"
          variant="subtle"
          :ui="{ container: 'gap-y-1.5', wrapper: 'items-start', title: 'font-normal text-muted text-xs uppercase' }"
          class="min-w-0 lg:rounded-none first:rounded-l-lg last:rounded-r-lg hover:z-1"
        >
          <p class="break-all text-2xl font-semibold tabular-nums tracking-tight text-highlighted">
            {{ metric.value }}
          </p>
        </UPageCard>
      </UPageGrid>
    </template>

    <section aria-labelledby="atalhos-comerciais" class="space-y-3">
      <div>
        <h2 id="atalhos-comerciais" class="text-base font-semibold text-highlighted">
          Atalhos
        </h2>
        <p class="text-sm text-muted">
          Acesse as áreas comerciais disponíveis.
        </p>
      </div>
      <UPageGrid class="gap-3 lg:grid-cols-3">
        <UPageCard
          title="Ofertas"
          description="Gerencie os planos ERP oferecidos pelo escritório."
          icon="i-lucide-tags"
          to="/gestao-erp/planos"
        />
        <UPageCard
          title="Clientes da carteira"
          description="Consulte clientes e inicie novos convites quando permitido."
          icon="i-lucide-users"
          to="/gestao-erp/clientes"
        />
        <UPageCard
          title="Assinaturas"
          description="Acompanhe contratos e licenças dos clientes."
          icon="i-lucide-file-key"
          to="/gestao-erp/assinaturas"
        />
      </UPageGrid>
    </section>
  </div>
</template>
