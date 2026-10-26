<script setup lang="ts">
import { formatOfficeErpMoney, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass } from '~/components/data-table/panel'

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
  contract_type?: string
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
const conversionPrice = ref('')
const conversionIntervalMonths = ref('1')
const conversionDueInDays = ref('0')
const conversionStartOn = ref('')
const conversionTimezone = ref('America/Sao_Paulo')
const conversionError = ref('')
let loadGeneration = 0
let conversionGeneration = 0

const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null, currentAccount.value?.role ?? null,
  context.capabilities.value?.capability_key ?? null
]))
const recoveryKey = computed(() => command.recoveryKey.value)
const canMaintain = computed(() => !!context.capabilities.value?.can_maintain && !!record.value)
const canConvertTrial = computed(() => canMaintain.value && ['trialing', 'expired'].includes(record.value?.trial?.situation ?? ''))
const lifecycleStatus = computed(() => record.value?.agreement_status === 'effective' ? 'Vigente' : record.value?.agreement_status === 'accepted' ? 'Aceito · aguardando ativação' : record.value?.agreement_status === 'canceled' ? 'Cancelado' : record.value?.agreement_status ?? 'Indisponível')
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
  const request = ++loadGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  try {
    await context.refresh()
    if (request !== loadGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) throw new Error('A conta selecionada não tem permissão para consultar este contrato.')
    const token = context.capture()
    const response = await api<{ data: ContractDetail }>(`/erp-commercial/contracts/${route.params.id}`)
    if (request !== loadGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!response?.data || response.data.id !== Number(route.params.id)) throw new Error('A resposta do contrato está incompleta.')
    record.value = response.data
    price.value = response.data.current_revision?.price ?? ''
    intervalMonths.value = String(response.data.current_revision?.interval_months ?? 1)
    dueInDays.value = String(response.data.current_revision?.due_in_days ?? 0)
    selectedModules.value = [...(response.data.current_revision?.module_keys ?? [])]
  } catch (error) {
    if (request === loadGeneration && identity === identityStamp.value) loadError.value = apiErrorMessage(error) ?? (error instanceof Error ? error.message : 'Não foi possível carregar o contrato.')
  } finally {
    if (request === loadGeneration && identity === identityStamp.value) loading.value = false
  }
}

async function execute(action: string, path: string, body: Record<string, unknown> = {}, method: 'POST' | 'PATCH' = 'POST') {
  if (!canMaintain.value || command.pending.value || recoveryInFlight.value || !record.value) return
  const scope = identityStamp.value
  const token = context.capture()
  mutationError.value = ''
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
    mutationError.value = result.failure.message
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
  let canonicalPrice: string
  try {
    canonicalPrice = parseOfficeErpMoney(price.value.trim())
  } catch {
    mutationError.value = 'Informe um preço em reais com até duas casas decimais.'
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
  const request = ++conversionGeneration
  const scope = identityStamp.value
  conversionLoading.value = true
  conversionError.value = ''
  try {
    await context.refresh()
    if (request !== conversionGeneration || scope !== identityStamp.value || !canConvertTrial.value) return
    const token = context.capture()
    const [plans, settings] = await Promise.all([
      api<EnrollmentPlansResponse>(`/erp-commercial/enrollment-plans?contract_id=${record.value.id}`),
      api<OfficeErpSettingsResponse>('/erp-commercial/settings')
    ])
    if (request !== conversionGeneration || scope !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(plans.data) || typeof settings.data?.billing_timezone !== 'string')
      throw new Error('Não foi possível carregar os termos disponíveis para conversão.')
    conversionPlans.value = plans.data.filter(plan => Number.isSafeInteger(plan.id) && typeof plan.name === 'string' && Array.isArray(plan.module_keys))
    if (plans.eligibility?.trial_conversion?.eligible !== true || conversionPlans.value.length === 0)
      throw new Error('O escritório não tem capacidade ou plano elegível para converter este teste agora.')
    conversionPlanId.value = conversionPlans.value.some(plan => plan.id === record.value?.erp_plan_id)
      ? record.value!.erp_plan_id
      : null
    conversionStartOn.value = todayLocalDate()
    conversionTimezone.value = settings.data.billing_timezone
    conversionOpen.value = true
  } catch (error) {
    if (request === conversionGeneration && scope === identityStamp.value)
      conversionError.value = apiErrorMessage(error) ?? (error instanceof Error ? error.message : 'Não foi possível preparar a conversão agora.')
  } finally {
    if (request === conversionGeneration)
      conversionLoading.value = false
  }
}

function confirmTrialConversion() {
  if (!canConvertTrial.value || !record.value || command.pending.value) return
  let canonicalPrice: string
  try {
    canonicalPrice = parseOfficeErpMoney(conversionPrice.value.trim())
  } catch {
    conversionError.value = 'Informe um preço em reais com até duas casas decimais.'
    return
  }
  const interval = Number(conversionIntervalMonths.value)
  const dueInDays = Number(conversionDueInDays.value)
  if (!conversionPlans.value.some(plan => plan.id === conversionPlanId.value)) {
    conversionError.value = 'Selecione um plano ERP disponível para este escritório.'
    return
  }
  if (![1, 3, 6, 12].includes(interval) || !Number.isInteger(dueInDays) || dueInDays < 0 || dueInDays > 65535) {
    conversionError.value = 'Confira o ciclo e os dias até o vencimento.'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(conversionStartOn.value) || !conversionTimezone.value.trim()) {
    conversionError.value = 'Informe a data de início e o fuso horário de cobrança.'
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
  if (!actionReason.value.trim()) {
    mutationError.value = 'Informe o motivo para continuar.'
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
  void loadContract()
}, { immediate: true, flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm text-muted">
          <NuxtLink to="/gestao-erp/assinaturas" class="underline">Assinaturas</NuxtLink> / contrato {{ route.params.id }}
        </p>
        <h1 class="mt-1 truncate text-xl font-semibold text-highlighted">
          {{ record?.client.name ?? 'Contrato ERP' }}
        </h1>
      </div>
      <UButton to="/gestao-erp/assinaturas" color="neutral" variant="outline">
        Voltar às assinaturas
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
        <div v-else-if="record.next_billing_on">
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
            color="primary"
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
        <div v-if="conversionOpen" class="mt-5 space-y-4 rounded-xl border border-default p-4 sm:p-5">
          <p class="text-sm font-medium text-highlighted">
            Confirme o plano e os termos pagos
          </p>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Plano ERP" required>
              <USelect
                v-model="conversionPlanId"
                :items="conversionPlans.map(plan => ({ label: plan.name, value: plan.id }))"
                placeholder="Selecione um plano elegível"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Preço em reais" required hint="Até duas casas decimais.">
              <UInput
                v-model="conversionPrice"
                inputmode="decimal"
                autocomplete="off"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Ciclo de cobrança" required>
              <USelect v-model="conversionIntervalMonths" :items="[{ label: 'Mensal', value: '1' }, { label: 'A cada 3 meses', value: '3' }, { label: 'A cada 6 meses', value: '6' }, { label: 'Anual', value: '12' }]" class="w-full" />
            </UFormField>
            <UFormField label="Dias até o vencimento" required>
              <UInput
                v-model="conversionDueInDays"
                type="number"
                min="0"
                max="65535"
                step="1"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Início da cobrança" required>
              <UInput v-model="conversionStartOn" type="date" class="w-full" />
            </UFormField>
            <UFormField label="Fuso horário de cobrança" required>
              <UInput v-model="conversionTimezone" autocomplete="off" class="w-full" />
            </UFormField>
          </div>
          <p class="text-sm text-muted">
            O teste não será cobrado retroativamente. Se estiver encerrado, a conversão exige uma licença ERP disponível no escritório.
          </p>
          <div class="flex flex-wrap gap-3">
            <UButton
              color="primary"
              :loading="command.pending.value"
              :disabled="command.pending.value || !canMaintain"
              @click="confirmTrialConversion"
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
        </div>
      </section>

      <section v-if="record.agreement_status === 'accepted' && record.contract_type !== 'office_trial' && !record.trial" class="border-t border-default pt-4">
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
          <template v-if="!record.pending_revision && !record.cancel_effective_on">
            <h2 class="font-semibold text-highlighted">
              Agendar termos para o próximo ciclo
            </h2>
            <UFormField label="Preço mensal ou do ciclo" hint="Informe reais com até duas casas decimais.">
              <UInput
                v-model="price"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                aria-label="Preço do contrato"
              />
            </UFormField>
            <UFormField label="Intervalo em meses">
              <USelect v-model="intervalMonths" :items="['1', '3', '6', '12']" aria-label="Intervalo do contrato" />
            </UFormField>
            <UFormField label="Dias até o vencimento">
              <UInput
                v-model="dueInDays"
                type="text"
                inputmode="numeric"
                aria-label="Dias até o vencimento"
              />
            </UFormField>
            <UFormField label="Módulos no próximo ciclo" hint="Expandir módulos exige capacidade e direito de nova venda.">
              <USelectMenu
                v-model="selectedModules"
                :items="moduleOptions"
                value-key="value"
                multiple
                aria-label="Módulos do contrato"
              />
            </UFormField>
            <UButton :disabled="!canMaintain || command.pending.value" :loading="command.pending.value" @click="amend">
              Agendar alteração
            </UButton>
          </template>
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
    <UModal :open="pendingAction !== ''" :title="pendingAction === 'suspend' ? 'Suspender licença' : 'Cancelar contrato'" description="A ação será registrada no histórico do contrato.">
      <template #body>
        <div class="space-y-4">
          <UFormField v-if="pendingAction === 'cancel'" label="Quando cancelar">
            <USelect v-model="cancelMode" :items="[{ label: 'No fim do período atual', value: 'period_end' }, { label: 'Imediatamente', value: 'immediate' }]" aria-label="Momento do cancelamento" />
          </UFormField>
          <UFormField label="Motivo" required>
            <UTextarea v-model="actionReason" :rows="3" aria-label="Motivo da alteração" />
          </UFormField>
        </div>
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
          @click="confirmReasonAction"
        >
          {{ pendingAction === 'suspend' ? 'Confirmar suspensão' : 'Confirmar cancelamento' }}
        </UButton>
      </template>
    </UModal>
  </div>
</template>
