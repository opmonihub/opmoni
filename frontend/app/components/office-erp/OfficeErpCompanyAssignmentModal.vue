<script setup lang="ts">
import { onScopeDispose } from 'vue'
import { focusFormError } from '~/utils/formPresentation'
import type { OfficeErpCommandResult } from '#shared/types/officeErpCommercial'
import { parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'

interface AssignmentPlan { id: number, name: string, module_keys: string[] }
interface AssignmentPlansResponse {
  data: AssignmentPlan[]
  eligibility: { can_provision: boolean, reasons: string[], licence_limit: number | null, used_licences: number, available_module_keys: string[] }
}
interface AssignmentOutcome { company_id: number, contract_id: number, subscription_id: number, allocation_id: number, agreement_status: string, licence_status: string, invoice_id: number | null }
interface AssignmentConfirmation { company_id: number, contract_id: number }

const props = withDefaults(defineProps<{
  companyId: number | null
  companyName: string
  canAssign: boolean
  currentContractId?: number | null
}>(), { currentContractId: null })
const open = defineModel<boolean>('open', { required: true })
const restoreModalFocus = useModalFocusReturn(open)
const emit = defineEmits<{ assigned: [AssignmentConfirmation] }>()
const { api } = useAccessApi()
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const authorityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => null)
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, props.companyId], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const loading = ref(false)
const submitting = ref(false)
const recovering = ref(false)
const plans = ref<AssignmentPlan[]>([])
const planId = ref<number | undefined>()
const price = ref('')
const interval = ref('1')
const dueDays = ref('0')
const startOn = ref('')
const timezone = ref('America/Sao_Paulo')
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
watch(fieldErrors, (errors) => {
  if (open.value && Object.keys(errors).length) void focusFormError(undefined, 'office-erp-assignment')
})
const notice = ref('')
const recoveryKey = ref<string | null>(null)
const activeRecoveryKey = computed(() => recoveryKey.value ?? command.recoveryKey.value)
let generation = 0
let mutationGeneration = 0
let disposed = false

function todayLocalDate() {
  const value = new Date()
  return [value.getFullYear(), String(value.getMonth() + 1).padStart(2, '0'), String(value.getDate()).padStart(2, '0')].join('-')
}
function clearDraft() {
  fieldErrors.value = {}
  plans.value = []
  planId.value = undefined
  price.value = ''
  interval.value = '1'
  dueDays.value = '0'
  startOn.value = ''
  timezone.value = 'America/Sao_Paulo'
}
function clearAll() {
  generation++
  mutationGeneration++
  reads.cancel('prepareAssignment')
  reads.cancel('verifyRecoveredAssignment')
  loading.value = false
  submitting.value = false
  recovering.value = false
  error.value = ''
  notice.value = ''
  recoveryKey.value = null
  clearDraft()
}
function currentTarget(targetCompanyId: number, identity: string, mutation: number) {
  return !disposed && mutation === mutationGeneration && open.value && identity === authorityStamp.value && props.companyId === targetCompanyId && props.canAssign
}

async function prepare() {
  const targetCompanyId = props.companyId
  if (!open.value || !props.canAssign || !Number.isSafeInteger(targetCompanyId) || !targetCompanyId || loading.value) return
  const read = reads.begin('prepareAssignment')
  const identity = identityStamp.value
  const request = ++generation
  loading.value = true
  error.value = ''
  if (!recoveryKey.value) notice.value = ''
  try {
    await context.refresh()
    if (!read.current() || request !== generation || identity !== identityStamp.value || props.companyId !== targetCompanyId || !props.canAssign) return
    const token = context.capture()
    const [planResponse, settings] = await Promise.all([
      api<AssignmentPlansResponse>('/erp-commercial/enrollment-plans', { signal: read.signal }),
      api<{ data: { billing_timezone: string } }>('/erp-commercial/settings', { signal: read.signal })
    ])
    if (!read.current() || request !== generation || identity !== identityStamp.value || !context.isCurrent(token) || props.companyId !== targetCompanyId) return
    if (!Array.isArray(planResponse.data) || typeof settings.data?.billing_timezone !== 'string') throw new Error('Não foi possível carregar os planos e o fuso do escritório.')
    if (!planResponse.eligibility?.can_provision || planResponse.data.length === 0) throw new Error('O escritório não tem capacidade ou planos elegíveis para iniciar uma assinatura.')
    plans.value = planResponse.data.filter(plan => Number.isSafeInteger(plan.id) && typeof plan.name === 'string' && Array.isArray(plan.module_keys))
    if (!plans.value.length) throw new Error('Não há planos elegíveis para iniciar uma assinatura.')
    planId.value = plans.value[0]?.id
    if (!startOn.value) startOn.value = todayLocalDate()
    if (!timezone.value || timezone.value === 'America/Sao_Paulo') timezone.value = settings.data.billing_timezone
  } catch (caught) {
    if (read.current() && request === generation && identity === identityStamp.value) error.value = apiErrorMessage(caught) ?? (caught instanceof Error ? caught.message : 'Não foi possível preparar a assinatura.')
  } finally {
    if (read.current() && request === generation && identity === identityStamp.value) loading.value = false
  }
}

function confirmedOutcome(value: unknown, targetCompanyId: number): AssignmentConfirmation | null {
  if (!value || typeof value !== 'object') return null
  const outcome = value as Partial<AssignmentOutcome>
  if (outcome.company_id !== targetCompanyId || !Number.isSafeInteger(outcome.contract_id) || (outcome.contract_id ?? 0) < 1) return null
  return { company_id: targetCompanyId, contract_id: outcome.contract_id as number }
}
function publishAssignment(result: AssignmentConfirmation) {
  recoveryKey.value = null
  notice.value = ''
  error.value = ''
  emit('assigned', result)
  open.value = false
}
async function verifyRecoveredAssignment(targetCompanyId: number, identity: string, mutation: number) {
  const read = reads.begin('verifyRecoveredAssignment')
  try {
    const token = context.capture()
    const response = await api<{ data: { id: number, current_contract_id: number | null } }>('/erp-commercial/companies/' + targetCompanyId, { signal: read.signal })
    if (!read.current() || !currentTarget(targetCompanyId, identity, mutation) || !context.isCurrent(token)) return
    const currentContractId = response.data?.current_contract_id
    if (response.data?.id === targetCompanyId && Number.isSafeInteger(currentContractId) && currentContractId && currentContractId !== props.currentContractId) {
      publishAssignment({ company_id: targetCompanyId, contract_id: currentContractId })
    } else {
      recoveryKey.value = null
      error.value = 'A operação foi confirmada, mas não há uma nova assinatura vinculada a esta empresa. Atualize os dados antes de continuar.'
    }
  } catch (caught) {
    if (read.current() && currentTarget(targetCompanyId, identity, mutation)) error.value = apiErrorMessage(caught) ?? 'A operação foi confirmada. Não foi possível verificar a assinatura desta empresa; consulte os dados atuais.'
  }
}

async function submit() {
  const targetCompanyId = props.companyId
  if (!props.canAssign || !Number.isSafeInteger(targetCompanyId) || !targetCompanyId || !planId.value || submitting.value || recovering.value || activeRecoveryKey.value || command.pending.value) return
  const identity = authorityStamp.value
  const mutation = ++mutationGeneration
  fieldErrors.value = {}
  let canonicalPrice: string
  try {
    canonicalPrice = parseOfficeErpMoney(price.value.trim())
  } catch {
    fieldErrors.value = { price: ['Informe um preço em reais com até duas casas decimais.'] }
    error.value = fieldErrors.value.price![0]!
    return
  }
  const intervalMonths = Number(interval.value)
  const dueInDays = Number(dueDays.value)
  if (![1, 3, 6, 12].includes(intervalMonths) || !Number.isInteger(dueInDays) || dueInDays < 0 || dueInDays > 65535 || !/^\d{4}-\d{2}-\d{2}$/.test(startOn.value) || !timezone.value.trim()) {
    fieldErrors.value = {
      ...(![1, 3, 6, 12].includes(intervalMonths) ? { interval_months: ['Selecione um ciclo válido.'] } : {}),
      ...(!Number.isInteger(dueInDays) || dueInDays < 0 || dueInDays > 65535 ? { due_in_days: ['Informe de 0 a 65.535 dias.'] } : {}),
      ...(!/^\d{4}-\d{2}-\d{2}$/.test(startOn.value) ? { start_on: ['Informe a data de início.'] } : {}),
      ...(!timezone.value.trim() ? { billing_timezone: ['Informe o fuso horário.'] } : {})
    }
    error.value = 'Confira o ciclo, prazo de vencimento, data de início e fuso horário.'
    return
  }
  submitting.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await command.execute<AssignmentOutcome>('subscription.assign', '/erp-commercial/companies/' + targetCompanyId + '/assignments', {
      erp_plan_id: planId.value,
      price: canonicalPrice,
      currency: 'BRL',
      interval_months: intervalMonths,
      due_in_days: dueInDays,
      start_on: startOn.value,
      billing_timezone: timezone.value.trim()
    }) as OfficeErpCommandResult<AssignmentOutcome>
    if (!currentTarget(targetCompanyId, identity, mutation)) return
    if (result.status === 'confirmed') {
      const outcome = confirmedOutcome(result.data, targetCompanyId)
      if (outcome) publishAssignment(outcome)
      else error.value = 'A operação foi confirmada, mas a resposta não identifica uma assinatura desta empresa. Atualize os dados antes de continuar.'
    } else if (result.status === 'unknown') {
      recoveryKey.value = result.key
      error.value = 'O resultado ainda não foi confirmado. Consulte a operação ' + result.key + ' antes de tentar novamente.'
    } else if (result.status === 'failed') {
      fieldErrors.value = result.failure.errors ?? {}
      error.value = result.failure.message || 'Não foi possível iniciar a assinatura.'
    } else error.value = 'O contexto do escritório mudou. Confira a conta selecionada.'
  } finally {
    if (mutation === mutationGeneration) submitting.value = false
  }
}

async function recover() {
  const key = activeRecoveryKey.value
  const targetCompanyId = props.companyId
  if (!key || !props.canAssign || !targetCompanyId || submitting.value || recovering.value) return
  const identity = authorityStamp.value
  const mutation = ++mutationGeneration
  recovering.value = true
  error.value = ''
  try {
    const result = await command.recover<unknown>(key)
    if (!currentTarget(targetCompanyId, identity, mutation)) return
    if (result.status === 'confirmed') {
      notice.value = 'A operação foi confirmada. Conferindo o contrato vinculado a esta empresa…'
      await verifyRecoveredAssignment(targetCompanyId, identity, mutation)
    } else if (result.status === 'unknown') error.value = 'O resultado da operação ' + key + ' ainda é incerto. Não reenvie a assinatura; consulte novamente.'
    else error.value = result.status === 'failed' ? result.failure.message : 'A operação pertence a outro contexto comercial.'
  } finally {
    if (mutation === mutationGeneration) recovering.value = false
  }
}

watch([open, () => props.companyId, () => props.canAssign], ([isOpen], previous) => {
  const [, previousCompanyId, previousCanAssign] = previous ?? []
  if (previous && (previousCompanyId !== props.companyId || previousCanAssign !== props.canAssign)) clearAll()
  if (isOpen) void prepare()
  else {
    generation++
    reads.cancel('prepareAssignment')
    loading.value = false
    submitting.value = false
    recovering.value = false
    if (!recoveryKey.value) {
      error.value = ''
      notice.value = ''
      clearDraft()
    }
  }
}, { immediate: true, flush: 'sync' })
watch(authorityStamp, () => {
  clearAll()
  open.value = false
}, { flush: 'sync' })
watch(identityStamp, () => {
  generation++
  reads.cancel('prepareAssignment')
  if (!open.value || disposed || submitting.value || recovering.value) return
  loading.value = false
  void prepare()
}, { flush: 'sync' })
onScopeDispose(() => {
  disposed = true
  clearAll()
})
</script>

<template>
  <UModal
    v-model:open="open"
    :title="'Iniciar assinatura · ' + (companyName || 'empresa')"
    description="A atribuição reserva uma licença; a emissão da primeira fatura acontece na ativação."
    :ui="{ content: 'sm:max-w-3xl', header: 'shrink-0', body: 'min-h-0 overflow-y-auto', footer: 'shrink-0 flex-wrap justify-end' }"
    @after:leave="restoreModalFocus"
  >
    <template #body>
      <div class="space-y-4">
        <USkeleton v-if="loading && !plans.length" class="h-24 w-full" />
        <UAlert
          v-if="error || activeRecoveryKey"
          color="error"
          title="Não foi possível iniciar a assinatura"
          :description="error || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de iniciar outra assinatura.'"
        >
          <template #actions>
            <UButton
              v-if="activeRecoveryKey"
              color="neutral"
              variant="outline"
              :loading="recovering"
              :disabled="submitting || recovering"
              @click="recover"
            >
              Consultar resultado
            </UButton>
          </template>
        </UAlert>
        <UAlert
          v-if="notice"
          color="warning"
          title="Estado da operação"
          :description="notice"
        />
        <UForm
          id="office-erp-assignment"
          :state="{ erp_plan_id: planId, price, interval_months: interval, due_in_days: dueDays, start_on: startOn, billing_timezone: timezone }"
          :disabled="loading || submitting || recovering || !!activeRecoveryKey"
          class="grid gap-4 sm:grid-cols-2"
          @submit="submit"
          @error="event => focusFormError(event, 'office-erp-assignment')"
        >
          <UFormField
            name="erp_plan_id"
            :error="fieldErrors.erp_plan_id?.[0]"
            label="Plano ERP"
            required
          >
            <USelect
              v-model="planId"
              :items="plans.map(plan => ({ label: plan.name, value: plan.id }))"
              placeholder="Selecione um plano elegível"
              class="w-full"
              :disabled="loading || !!recoveryKey"
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
              v-model="price"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="!!recoveryKey"
            />
          </UFormField>
          <UFormField
            name="interval_months"
            :error="fieldErrors.interval_months?.[0]"
            label="Ciclo de cobrança"
            required
          >
            <USelect
              v-model="interval"
              :items="[{ label: 'Mensal', value: '1' }, { label: 'A cada 3 meses', value: '3' }, { label: 'A cada 6 meses', value: '6' }, { label: 'Anual', value: '12' }]"
              class="w-full"
              :disabled="!!recoveryKey"
            />
          </UFormField>
          <UFormField
            name="due_in_days"
            :error="fieldErrors.due_in_days?.[0]"
            label="Dias até o vencimento"
            required
          >
            <UInput
              v-model="dueDays"
              type="number"
              min="0"
              max="65535"
              step="1"
              class="w-full"
              :disabled="!!recoveryKey"
            />
          </UFormField>
          <UFormField
            name="start_on"
            :error="fieldErrors.start_on?.[0]"
            label="Início do serviço"
            required
          >
            <UInput
              v-model="startOn"
              type="date"
              class="w-full"
              :disabled="!!recoveryKey"
            />
          </UFormField>
          <UFormField
            name="billing_timezone"
            :error="fieldErrors.billing_timezone?.[0]"
            label="Fuso horário"
            required
          >
            <UInput
              v-model="timezone"
              autocomplete="off"
              class="w-full"
              :disabled="!!recoveryKey"
            />
          </UFormField>
        </UForm>
        <p v-if="plans.find(plan => plan.id === planId)" class="text-sm text-muted">
          Módulos do plano: {{ plans.find(plan => plan.id === planId)?.module_keys.join(', ') || 'Nenhum' }}.
        </p>
      </div>
    </template>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          :disabled="submitting || recovering"
          @click="open = false"
        >
          Cancelar
        </UButton>
        <UButton
          type="submit"
          form="office-erp-assignment"
          :loading="submitting"
          :disabled="loading || submitting || recovering || !!activeRecoveryKey || command.pending.value || !planId"
        >
          Confirmar assinatura
        </UButton>
      </div>
    </template>
  </UModal>
</template>
