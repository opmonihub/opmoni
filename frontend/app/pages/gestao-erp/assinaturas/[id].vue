<script setup lang="ts">
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { formatOfficeErpMoney, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass } from '~/components/data-table/panel'
import { focusFormError } from '~/utils/formPresentation'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'

definePageMeta({ middleware: 'auth' })

interface ContractRevision {
  id: number
  revision_number: number
  price: string
  interval_months: number
  due_in_days: number
  module_keys: string[]
  service_period_start: string | null
  service_period_end: string | null
  reason: string | null
}
interface ContractTrial {
  id: number
  started_at: string
  ends_at: string
  converted_at: string | null
  expired_at: string | null
  situation: 'trialing' | 'expired' | 'converted'
}
interface ContractDetail {
  id: number
  erp_plan_id?: number | null
  erp_plan?: { id: number, name: string } | null
  client: { id: number, name: string }
  company: { id: number, name: string, owner_setup_status?: 'unclaimed' | 'claimed' } | null
  trial: ContractTrial | null
  next_billing_on?: string | null
  agreement_status: string
  licence_status: string | null
  current_revision: ContractRevision | null
  pending_revision: ContractRevision | null
  service_anchor_on: string | null
  next_period_month_offset: number | null
  cancel_effective_on: string | null
  cancellation_reason: string | null
  manual_hold: boolean
  balance: { outstanding: string, invoice_count: number }
  revisions: ContractRevision[]
}
interface EnrollmentPlan {
  id: number
  slug: string
  name: string
  module_keys: string[]
}
interface EnrollmentPlansResponse {
  data: EnrollmentPlan[]
  eligibility: {
    can_provision: boolean
    reasons: string[]
    licence_limit: number | null
    used_licences: number
    available_module_keys: string[]
    trial_conversion?: { eligible: boolean, capacity_mode: 'occupied_trial' | 'new_slot' | null, reason: string | null }
  }
}
interface OfficeErpSettingsResponse {
  data: { billing_timezone: string }
}

const route = useRoute()
const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const toast = useToast()
const record = ref<ContractDetail | null>(null)
const loading = ref(false)
const loadError = ref('')
const mutationError = ref('')
const recoveryInFlight = ref(false)
const pendingAction = ref<'suspend' | 'cancel' | ''>('')
const actionReason = ref('')
const price = ref('')
const intervalMonths = ref('1')
const dueInDays = ref('0')
const selectedModules = ref<string[]>([])
const conversionOpen = ref(false)
const conversionLoading = ref(false)
const conversionPlans = ref<EnrollmentPlan[]>([])
const conversionPlanId = ref<number | null>(null)
const conversionPlanSelectModel = computed<number | undefined>({
  get: () => conversionPlanId.value ?? undefined,
  set: (value) => { conversionPlanId.value = value ?? null }
})
const conversionPrice = ref('')
const conversionIntervalMonths = ref('1')
const conversionDueInDays = ref('0')
const conversionStartOn = ref('')
const conversionTimezone = ref('America/Sao_Paulo')
const conversionError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const activeFormId = ref('office-erp-contract-terms')
const reasonOpen = computed(() => pendingAction.value !== '')
const restoreReasonFocus = useModalFocusReturn(reasonOpen)
watch(fieldErrors, () => {
  void focusFormError(undefined, activeFormId.value)
})
function invalid(name: string, message: string) {
  fieldErrors.value = { [name]: [message] }
  if (activeFormId.value === 'office-erp-trial-conversion') conversionError.value = message
  else mutationError.value = message
}
let loadGeneration = 0
let conversionGeneration = 0

const identityStamp = useOfficeErpReadIdentity(() => [
  user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null, currentAccount.value?.role ?? null
], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const recoveryKey = computed(() => command.recoveryKey.value)
const canMaintain = computed(() => !!context.capabilities.value?.can_maintain && !!record.value)
const canConvertTrial = computed(() => canMaintain.value && ['trialing', 'expired'].includes(record.value?.trial?.situation ?? ''))
const lifecycleStatus = computed(() => {
  const contract = record.value
  if (!contract) return 'Indisponível'
  if (contract.agreement_status === 'effective') return 'Vigente'
  if (contract.agreement_status === 'accepted') {
    if (contract.trial?.situation === 'trialing') return 'Em teste'
    if (contract.trial?.situation === 'expired') return 'Teste encerrado'
    return 'Aceito · aguardando ativação'
  }
  if (contract.agreement_status === 'canceled') return 'Cancelado'
  return contract.agreement_status
})
const licenceStatus = computed(() => ({ active: 'Ativa', pending: 'Pendente', suspended: 'Suspensa', past_due: 'Em atraso', canceled: 'Cancelada' } as Record<string, string>)[record.value?.licence_status ?? ''] ?? 'Sem licença vinculada')
const trialStatus = computed(() => ({ trialing: 'Em teste', expired: 'Teste encerrado', converted: 'Convertido em assinatura paga' } as Record<string, string>)[record.value?.trial?.situation ?? ''] ?? '')

function formatUtcDateTime(value: string) {
  const date = new Date(value)
  if (!Number.isFinite(date.getTime())) return 'Data indisponível'
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(date) + ' UTC'
}
const moduleOptions = computed(() => [...new Set([
  ...(record.value?.current_revision?.module_keys ?? []),
  ...(context.capabilities.value?.allowance.module_keys ?? [])
])].map(value => ({ label: value, value })))

async function loadContract() {
  const read = reads.begin('loadContract')
  const request = ++loadGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  try {
    await context.refresh()
    if (!read.current() || request !== loadGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) throw new Error('A conta selecionada não tem permissão para consultar este contrato.')
    const token = context.capture()
    const response = await api<{ data: ContractDetail }>(`/erp-commercial/contracts/${route.params.id}`, { signal: read.signal })
    if (!read.current() || request !== loadGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!response?.data || response.data.id !== Number(route.params.id)) throw new Error('A resposta do contrato está incompleta.')
    record.value = response.data
    price.value = response.data.current_revision?.price ?? ''
    intervalMonths.value = String(response.data.current_revision?.interval_months ?? 1)
    dueInDays.value = String(response.data.current_revision?.due_in_days ?? 0)
    selectedModules.value = [...(response.data.current_revision?.module_keys ?? [])]
  } catch (error) {
    if (read.current() && request === loadGeneration && identity === identityStamp.value) loadError.value = apiErrorMessage(error) ?? (error instanceof Error ? error.message : 'Não foi possível carregar o contrato.')
  } finally {
    if (read.current() && request === loadGeneration && identity === identityStamp.value) loading.value = false
  }
}

async function execute(action: string, path: string, body: Record<string, unknown> = {}, method: 'POST' | 'PATCH' = 'POST') {
  if (!canMaintain.value || command.pending.value || recoveryInFlight.value || !record.value) return
  const scope = identityStamp.value
  const token = context.capture()
  mutationError.value = ''
  fieldErrors.value = {}
  const result = await command.execute<{ contract_id?: number, revision_id?: number }>(action, path, body, method)
  if (scope !== identityStamp.value || !context.isCurrent(token)) return
  if (result.status === 'confirmed') {
    pendingAction.value = ''
    actionReason.value = ''
    if (action === 'trial.convert') {
      conversionOpen.value = false
      conversionError.value = ''
    }
    toast.add({ title: 'Alteração confirmada', color: 'success' })
    await loadContract()
  } else if (result.status === 'unknown') {
    mutationError.value = 'A resposta não confirmou o resultado. Recupere a operação pelo identificador original antes de tentar outra alteração.'
  } else if (result.status === 'failed') {
    fieldErrors.value = result.failure.errors ?? {}
    if (action === 'trial.convert') conversionError.value = result.failure.message
    else mutationError.value = result.failure.message
  }
}

async function recoverOperation() {
  const key = recoveryKey.value
  if (!key || recoveryInFlight.value) return
  const scope = identityStamp.value
  const token = context.capture()
  recoveryInFlight.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (scope !== identityStamp.value || !context.isCurrent(token)) return
    if (result.status === 'confirmed') {
      mutationError.value = ''
      pendingAction.value = ''
      actionReason.value = ''
      if (record.value?.trial)
        conversionOpen.value = false
      await loadContract()
    } else if (result.status === 'failed') {
      mutationError.value = result.failure.message
    } else if (result.status === 'unknown') {
      mutationError.value = 'A operação ainda não pôde ser confirmada. Os campos foram mantidos para você tentar recuperar novamente.'
    }
  } finally {
    recoveryInFlight.value = false
  }
}

function amend() {
  activeFormId.value = 'office-erp-contract-terms'
  let canonicalPrice: string
  try {
    canonicalPrice = parseOfficeErpMoney(price.value.trim())
  } catch {
    invalid('price', 'Informe um preço em reais com até duas casas decimais.')
    return
  }
  void execute('contract.amend', `/erp-commercial/contracts/${route.params.id}/terms`, {
    price: canonicalPrice, interval_months: Number(intervalMonths.value), due_in_days: Number(dueInDays.value), module_keys: selectedModules.value
  }, 'PATCH')
}

function todayLocalDate() {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function formatDate(value: string) {
  const date = new Date(value)
  return Number.isNaN(date.valueOf())
    ? 'Data indisponível'
    : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' }).format(date)
}

async function prepareTrialConversion() {
  if (!canConvertTrial.value || conversionLoading.value || command.pending.value || !record.value) return
  const read = reads.begin('prepareTrialConversion')
  const request = ++conversionGeneration
  const scope = identityStamp.value
  conversionLoading.value = true
  conversionError.value = ''
  try {
    await context.refresh()
    if (!read.current() || request !== conversionGeneration || scope !== identityStamp.value || !canConvertTrial.value) return
    const token = context.capture()
    const [plans, settings] = await Promise.all([
      api<EnrollmentPlansResponse>(`/erp-commercial/enrollment-plans?contract_id=${record.value.id}`, { signal: read.signal }),
      api<OfficeErpSettingsResponse>('/erp-commercial/settings', { signal: read.signal })
    ])
    if (!read.current() || request !== conversionGeneration || scope !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(plans.data) || typeof settings.data?.billing_timezone !== 'string')
      throw new Error('Não foi possível carregar os termos disponíveis para conversão.')
    conversionPlans.value = plans.data.filter(plan => Number.isSafeInteger(plan.id) && typeof plan.name === 'string' && Array.isArray(plan.module_keys))
    if (plans.eligibility?.trial_conversion?.eligible !== true || conversionPlans.value.length === 0)
      throw new Error('O escritório não tem capacidade ou plano elegível para converter este teste agora.')
    const existingPlanId = record.value?.erp_plan_id ?? null
    conversionPlanId.value = conversionPlans.value.some(plan => plan.id === existingPlanId)
      ? existingPlanId
      : null
    conversionStartOn.value = todayLocalDate()
    conversionTimezone.value = settings.data.billing_timezone
    conversionOpen.value = true
  } catch (error) {
    if (read.current() && request === conversionGeneration && scope === identityStamp.value)
      conversionError.value = apiErrorMessage(error) ?? (error instanceof Error ? error.message : 'Não foi possível preparar a conversão agora.')
  } finally {
    if (read.current() && request === conversionGeneration)
      conversionLoading.value = false
  }
}

function confirmTrialConversion() {
  activeFormId.value = 'office-erp-trial-conversion'
  if (!canConvertTrial.value || !record.value || command.pending.value) return
  let canonicalPrice: string
  try {
    canonicalPrice = parseOfficeErpMoney(conversionPrice.value.trim())
  } catch {
    invalid('price', 'Informe um preço em reais com até duas casas decimais.')
    return
  }
  const interval = Number(conversionIntervalMonths.value)
  const dueInDays = Number(conversionDueInDays.value)
  if (!conversionPlans.value.some(plan => plan.id === conversionPlanId.value)) {
    invalid('erp_plan_id', 'Selecione um plano ERP disponível para este escritório.')
    return
  }
  if (![1, 3, 6, 12].includes(interval) || !Number.isInteger(dueInDays) || dueInDays < 0 || dueInDays > 65535) {
    invalid(![1, 3, 6, 12].includes(interval) ? 'interval_months' : 'due_in_days', 'Confira o ciclo e os dias até o vencimento.')
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(conversionStartOn.value) || !conversionTimezone.value.trim()) {
    invalid(!conversionTimezone.value.trim() ? 'billing_timezone' : 'start_on', 'Informe a data de início e o fuso horário de cobrança.')
    return
  }
  conversionError.value = ''
  void execute('trial.convert', `/erp-commercial/contracts/${record.value.id}/convert-trial`, {
    erp_plan_id: conversionPlanId.value,
    price: canonicalPrice,
    currency: 'BRL',
    interval_months: interval,
    due_in_days: dueInDays,
    start_on: conversionStartOn.value,
    billing_timezone: conversionTimezone.value.trim()
  })
}

function confirmReasonAction() {
  activeFormId.value = 'office-erp-contract-action'
  if (!actionReason.value.trim()) {
    invalid('reason', 'Informe o motivo para continuar.')
    return
  }
  if (pendingAction.value === 'suspend')
    void execute('contract.suspend', `/erp-commercial/contracts/${route.params.id}/suspend`, { reason: actionReason.value.trim() })
  if (pendingAction.value === 'cancel')
    void execute('contract.cancel', `/erp-commercial/contracts/${route.params.id}/cancel`, { mode: cancelMode.value, reason: actionReason.value.trim() })
}

const cancelMode = ref<'immediate' | 'period_end'>('period_end')
watch(identityStamp, () => {
  loadGeneration++
  conversionGeneration++
  record.value = null
  mutationError.value = ''
  loadError.value = ''
  conversionOpen.value = false
  conversionPlans.value = []
  conversionPlanId.value = null
  conversionError.value = ''
  fieldErrors.value = {}
  void loadContract()
}, { immediate: true, flush: 'sync' })
</script>

<template>
  <div class="mx-auto flex min-h-0 w-full min-w-0 max-w-5xl flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm text-muted">
          Contrato {{ route.params.id }}
        </p>
        <h2 class="mt-1 text-base font-semibold wrap-anywhere text-highlighted">
          {{ record?.client.name ?? 'Contrato ERP' }}
        </h2>
      </div>
      <UButton
        to="/gestao-erp/assinaturas"
        color="neutral"
        variant="ghost"
        icon="i-lucide-arrow-left"
        size="sm"
      >
        Voltar para assinaturas
      </UButton>
    </div>
    <UAlert
      v-if="loadError"
      color="error"
      title="Não foi possível carregar o contrato"
      :description="loadError"
    />
    <UAlert
      v-if="mutationError || recoveryKey"
      color="error"
      title="A alteração precisa de atenção"
      :description="mutationError"
    >
      <template #description>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span>{{ mutationError || 'Há uma operação sem confirmação. Recupere-a pelo identificador original antes de iniciar outra alteração.' }}</span>
          <UButton
            v-if="recoveryKey"
            color="neutral"
            variant="outline"
            :disabled="recoveryInFlight"
            :loading="recoveryInFlight"
            @click="recoverOperation"
          >
            Recuperar operação
          </UButton>
        </div>
      </template>
    </UAlert>
    <div v-if="record" :class="panelBodyClass" class="rounded-xl border border-default bg-default">
      <section class="grid gap-4 sm:grid-cols-2">
        <div>
          <p class="text-sm text-muted">
            Situação do contrato
          </p><UBadge class="mt-1" :color="record.agreement_status === 'effective' ? 'success' : 'neutral'" variant="subtle">
            {{ lifecycleStatus }}
          </UBadge>
        </div>
        <div>
          <p class="text-sm text-muted">
            Situação da licença
          </p><UBadge class="mt-1" :color="record.licence_status === 'active' ? 'success' : 'warning'" variant="subtle">
            {{ licenceStatus }}
          </UBadge>
        </div>
        <div>
          <p class="text-sm text-muted">
            Empresa ERP
          </p><p class="mt-1 font-medium text-highlighted">
            {{ record.company?.name ?? 'Aguardando vínculo' }}
          </p>
        </div>
        <div v-if="record.erp_plan_id">
          <p class="text-sm text-muted">
            Plano técnico
          </p>
          <p class="mt-1 font-medium text-highlighted">
            {{ record.erp_plan?.name ?? `Plano ERP #${record.erp_plan_id}` }}
          </p>
        </div>
        <div v-if="record.company?.owner_setup_status">
          <p class="text-sm text-muted">
            Acesso do responsável
          </p>
          <p class="mt-1 font-medium text-highlighted">
            {{ record.company.owner_setup_status === 'claimed' ? 'Configurado' : 'Aguardando configuração' }}
          </p>
        </div>
        <div v-if="record.trial">
          <p class="text-sm text-muted">
            Teste de 7 dias
          </p>
          <UBadge class="mt-1" :color="record.trial.situation === 'trialing' ? 'info' : record.trial.situation === 'expired' ? 'warning' : 'success'" variant="subtle">
            {{ trialStatus }}
          </UBadge>
          <p class="mt-1 text-sm text-muted">
            {{ record.trial.situation === 'trialing' ? 'Encerra' : record.trial.situation === 'expired' ? 'Encerrou' : 'Período original até' }} {{ formatUtcDateTime(record.trial.ends_at) }}
          </p>
        </div>
        <div v-if="record.next_billing_on">
          <p class="text-sm text-muted">
            Próxima cobrança
          </p>
          <p class="mt-1 font-medium text-highlighted">
            {{ record.next_billing_on }}
          </p>
        </div>
        <div>
          <p class="text-sm text-muted">
            Saldo em aberto
          </p><p class="mt-1 font-medium text-highlighted">
            {{ formatOfficeErpMoney(record.balance.outstanding) }} · {{ record.balance.invoice_count }} faturas
          </p>
        </div>
        <div>
          <p class="text-sm text-muted">
            Ativação
          </p><p class="mt-1 text-highlighted">
            {{ record.service_anchor_on ?? 'Ainda não ativado' }}
          </p>
        </div>
        <div v-if="record.cancel_effective_on">
          <p class="text-sm text-muted">
            Cancelamento efetivo
          </p><p class="mt-1 text-highlighted">
            {{ record.cancel_effective_on }} · {{ record.cancellation_reason }}
          </p>
        </div>
      </section>

      <UAlert
        v-if="record.pending_revision"
        color="warning"
        title="Alteração agendada"
        :description="`A revisão ${record.pending_revision.revision_number} começa em ${record.pending_revision.service_period_start}. As faturas anteriores preservam os termos emitidos.`"
      />
      <UAlert
        v-if="record.manual_hold"
        color="warning"
        title="Suspensão manual ativa"
        description="A suspensão manual é preservada separadamente do estado de cobrança."
      />

      <section class="border-t border-default pt-4">
        <h2 class="font-semibold text-highlighted">
          Termos vigentes
        </h2>
        <p v-if="record.current_revision" class="mt-2 text-sm text-muted">
          Revisão {{ record.current_revision.revision_number }} · {{ formatOfficeErpMoney(record.current_revision.price) }} · ciclo de {{ record.current_revision.interval_months }} mês(es) · vencimento em {{ record.current_revision.due_in_days }} dia(s) · módulos: {{ record.current_revision.module_keys.join(', ') || 'nenhum' }}
        </p>
        <p v-if="record.pending_revision" class="mt-1 text-sm text-muted">
          Próxima revisão: {{ formatOfficeErpMoney(record.pending_revision.price) }} · {{ record.pending_revision.interval_months }} mês(es) · {{ record.pending_revision.module_keys.join(', ') || 'nenhum' }}
        </p>
      </section>

      <UAlert
        v-if="record.trial"
        :color="record.trial.situation === 'trialing' ? 'success' : 'neutral'"
        :title="record.trial.situation === 'trialing' ? 'Em teste' : record.trial.situation === 'expired' ? 'Teste encerrado' : 'Teste convertido'"
      >
        <template #description>
          <div class="space-y-1">
            <p>Início do teste: {{ formatDate(record.trial.started_at) }}</p>
            <p>Fim original: {{ formatDate(record.trial.ends_at) }}</p>
            <p v-if="record.trial.situation === 'expired'">
              A licença do teste foi liberada. A conversão depende da capacidade atual do escritório.
            </p>
          </div>
        </template>
      </UAlert>

      <section v-if="canConvertTrial" class="border-t border-default pt-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="max-w-2xl space-y-1">
            <h2 class="font-semibold text-highlighted">
              Conversão paga explícita
            </h2>
            <p class="text-sm leading-6 text-muted">
              A conversão mantém a mesma empresa e assinatura. Nenhum valor será cobrado até você confirmar os termos e a data de início.
            </p>
          </div>
          <UButton
            color="neutral"
            :loading="conversionLoading"
            :disabled="conversionLoading || !canMaintain || command.pending.value"
            @click="prepareTrialConversion"
          >
            Iniciar cobrança paga
          </UButton>
        </div>
        <UAlert
          v-if="conversionError"
          class="mt-4"
          color="error"
          title="Não foi possível preparar a conversão"
          :description="conversionError"
        />
        <UForm
          v-if="conversionOpen"
          id="office-erp-trial-conversion"
          :state="{ erp_plan_id: conversionPlanId, price: conversionPrice, interval_months: conversionIntervalMonths, due_in_days: conversionDueInDays, start_on: conversionStartOn, billing_timezone: conversionTimezone }"
          :disabled="command.pending.value"
          class="mt-4 space-y-4"
          @submit="confirmTrialConversion"
          @error="event => focusFormError(event, 'office-erp-trial-conversion')"
        >
          <p class="text-sm font-medium text-highlighted">
            Confirme o plano e os termos pagos
          </p>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              name="erp_plan_id"
              :error="fieldErrors.erp_plan_id?.[0]"
              label="Plano ERP"
              required
            >
              <USelect
                v-model="conversionPlanSelectModel"
                :items="conversionPlans.map(plan => ({ label: plan.name, value: plan.id }))"
                placeholder="Selecione um plano elegível"
                class="w-full"
              />
            </UFormField>
            <UFormField
              name="price"
              :error="fieldErrors.price?.[0]"
              label="Preço em reais"
              required
              hint="Até duas casas decimais."
            >
              <UInput
                v-model="conversionPrice"
                inputmode="decimal"
                autocomplete="off"
                class="w-full"
              />
            </UFormField>
            <UFormField
              name="interval_months"
              :error="fieldErrors.interval_months?.[0]"
              label="Ciclo de cobrança"
              required
            >
              <USelect v-model="conversionIntervalMonths" :items="[{ label: 'Mensal', value: '1' }, { label: 'A cada 3 meses', value: '3' }, { label: 'A cada 6 meses', value: '6' }, { label: 'Anual', value: '12' }]" class="w-full" />
            </UFormField>
            <UFormField
              name="due_in_days"
              :error="fieldErrors.due_in_days?.[0]"
              label="Dias até o vencimento"
              required
            >
              <UInput
                v-model="conversionDueInDays"
                type="number"
                min="0"
                max="65535"
                step="1"
                class="w-full"
              />
            </UFormField>
            <UFormField
              name="start_on"
              :error="fieldErrors.start_on?.[0]"
              label="Início da cobrança"
              required
            >
              <UInput v-model="conversionStartOn" type="date" class="w-full" />
            </UFormField>
            <UFormField
              name="billing_timezone"
              :error="fieldErrors.billing_timezone?.[0]"
              label="Fuso horário de cobrança"
              required
            >
              <UInput v-model="conversionTimezone" autocomplete="off" class="w-full" />
            </UFormField>
          </div>
          <p class="text-sm text-muted">
            O teste não será cobrado retroativamente. Se estiver encerrado, a conversão exige uma licença ERP disponível no escritório.
          </p>
          <div class="flex flex-wrap gap-3">
            <UButton
              color="neutral"
              :loading="command.pending.value"
              :disabled="command.pending.value || !canMaintain"
              type="submit"
            >
              Confirmar conversão e emitir primeira fatura
            </UButton>
            <UButton
              color="neutral"
              variant="outline"
              :disabled="command.pending.value"
              @click="conversionOpen = false"
            >
              Cancelar
            </UButton>
          </div>
        </UForm>
      </section>

      <section v-if="record.agreement_status === 'accepted' && !record.trial" class="border-t border-default pt-4">
        <h2 class="font-semibold text-highlighted">
          Ativar contrato aceito
        </h2>
        <p class="mt-1 text-sm text-muted">
          A ativação emite a primeira fatura e vincula a licença já ocupada nesta mesma operação.
        </p>
        <UButton
          class="mt-3"
          :disabled="!canMaintain || command.pending.value"
          :loading="command.pending.value"
          @click="execute('contract.activate', `/erp-commercial/contracts/${record.id}/activate`)"
        >
          Ativar e emitir primeira fatura
        </UButton>
      </section>

      <section v-if="record.agreement_status === 'effective'" class="grid gap-4 border-t border-default pt-4 lg:grid-cols-2">
        <div class="space-y-3">
          <UForm
            v-if="!record.pending_revision && !record.cancel_effective_on"
            id="office-erp-contract-terms"
            :state="{ price, interval_months: intervalMonths, due_in_days: dueInDays, module_keys: selectedModules }"
            class="space-y-3"
            @submit="amend"
            @error="event => focusFormError(event, 'office-erp-contract-terms')"
          >
            <h2 class="font-semibold text-highlighted">
              Agendar termos para o próximo ciclo
            </h2>
            <UFormField
              name="price"
              :error="fieldErrors.price?.[0]"
              label="Preço mensal ou do ciclo"
              hint="Informe reais com até duas casas decimais."
            >
              <UInput
                v-model="price"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                aria-label="Preço do contrato"
              />
            </UFormField>
            <UFormField name="interval_months" :error="fieldErrors.interval_months?.[0]" label="Intervalo em meses">
              <USelect v-model="intervalMonths" :items="['1', '3', '6', '12']" aria-label="Intervalo do contrato" />
            </UFormField>
            <UFormField name="due_in_days" :error="fieldErrors.due_in_days?.[0]" label="Dias até o vencimento">
              <UInput
                v-model="dueInDays"
                type="text"
                inputmode="numeric"
                aria-label="Dias até o vencimento"
              />
            </UFormField>
            <UFormField
              name="module_keys"
              :error="fieldErrors.module_keys?.[0]"
              :error-pattern="/^module_keys\..*/"
              label="Módulos no próximo ciclo"
              hint="Expandir módulos exige capacidade e direito de nova venda."
            >
              <USelectMenu
                v-model="selectedModules"
                :items="moduleOptions"
                value-key="value"
                multiple
                aria-label="Módulos do contrato"
              />
            </UFormField>
            <UButton type="submit" :disabled="!canMaintain || command.pending.value" :loading="command.pending.value">
              Agendar alteração
            </UButton>
          </UForm>
        </div>
        <div class="space-y-3">
          <h2 class="font-semibold text-highlighted">
            Ciclo e licença
          </h2>
          <p class="text-sm text-muted">
            A renovação mantém preço e permissões e cria uma nova revisão imutável para o próximo período.
          </p>
          <div class="flex flex-wrap gap-2">
            <UButton
              color="neutral"
              variant="outline"
              :disabled="!canMaintain || command.pending.value || !!record.pending_revision || !!record.cancel_effective_on"
              @click="execute('contract.renew', `/erp-commercial/contracts/${record.id}/renew`)"
            >
              Renovar sem mudar termos
            </UButton>
            <UButton
              v-if="!record.manual_hold"
              color="warning"
              variant="outline"
              :disabled="!canMaintain || command.pending.value"
              @click="pendingAction = 'suspend'; actionReason = ''"
            >
              Suspender manualmente
            </UButton>
            <UButton
              v-else
              color="success"
              variant="outline"
              :disabled="!canMaintain || command.pending.value"
              @click="execute('contract.resume', `/erp-commercial/contracts/${record.id}/resume`)"
            >
              Retomar licença
            </UButton>
            <UButton
              color="error"
              variant="outline"
              :disabled="!canMaintain || command.pending.value"
              @click="pendingAction = 'cancel'; cancelMode = 'period_end'; actionReason = ''"
            >
              Cancelar contrato
            </UButton>
          </div>
        </div>
      </section>

      <section class="border-t border-default pt-4">
        <h2 class="font-semibold text-highlighted">
          Histórico de revisões
        </h2>
        <ul class="mt-3 space-y-2">
          <li v-for="revision in record.revisions" :key="revision.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-default p-3 text-sm">
            <span>Revisão {{ revision.revision_number }} · {{ formatOfficeErpMoney(revision.price) }} · {{ revision.interval_months }} mês(es)</span>
            <span class="text-muted">{{ revision.reason === 'renewal' ? 'Renovação' : revision.reason === 'amendment' ? 'Alteração' : 'Termo aceito' }} · {{ revision.service_period_start ?? 'Aguardando ativação' }}</span>
          </li>
        </ul>
      </section>
    </div>
    <UModal
      :open="pendingAction !== ''"
      :title="pendingAction === 'suspend' ? 'Suspender licença' : 'Cancelar contrato'"
      description="A ação será registrada no histórico do contrato."
      :ui="{ footer: 'shrink-0 flex-wrap justify-end' }"
      @update:open="value => { if (!value) pendingAction = '' }"
      @after:leave="restoreReasonFocus"
    >
      <template #body>
        <UForm
          id="office-erp-contract-action"
          :state="{ reason: actionReason, mode: cancelMode }"
          class="space-y-4"
          @submit="confirmReasonAction"
          @error="event => focusFormError(event, 'office-erp-contract-action')"
        >
          <UFormField
            v-if="pendingAction === 'cancel'"
            name="mode"
            :error="fieldErrors.mode?.[0]"
            label="Quando cancelar"
          >
            <USelect v-model="cancelMode" :items="[{ label: 'No fim do período atual', value: 'period_end' }, { label: 'Imediatamente', value: 'immediate' }]" aria-label="Momento do cancelamento" />
          </UFormField>
          <UFormField
            name="reason"
            :error="fieldErrors.reason?.[0]"
            label="Motivo"
            required
          >
            <UTextarea v-model="actionReason" :rows="3" aria-label="Motivo da alteração" />
          </UFormField>
        </UForm>
      </template>
      <template #footer>
        <UButton
          color="neutral"
          variant="outline"
          :disabled="command.pending.value"
          @click="pendingAction = ''"
        >
          Voltar
        </UButton>
        <UButton
          :color="pendingAction === 'cancel' ? 'error' : 'warning'"
          :loading="command.pending.value"
          :disabled="command.pending.value"
          type="submit"
          form="office-erp-contract-action"
        >
          {{ pendingAction === 'suspend' ? 'Confirmar suspensão' : 'Confirmar cancelamento' }}
        </UButton>
      </template>
    </UModal>
  </div>
</template>
