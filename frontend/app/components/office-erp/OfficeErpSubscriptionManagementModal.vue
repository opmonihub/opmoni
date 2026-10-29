<script setup lang="ts">
import { computed, reactive, ref, toRef, watch } from 'vue'
import { formatOfficeErpMoney, officeErpModuleOptions, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'

interface Revision {
  id?: number
  revision_number?: number
  price: string
  interval_months: number
  due_in_days: number
  module_keys: string[]
  service_period_start?: string | null
  service_period_end?: string | null
}

interface Contract {
  id: number
  agreement_status: string
  current_revision: Revision | null
  pending_revision: Revision | null
  manual_hold: boolean
  cancel_effective_on: string | null
  cancellation_reason?: string | null
  next_billing_on?: string | null
}

interface Capabilities {
  can_manage_lifecycle: boolean
  can_amend_terms: boolean
  can_cancel: boolean
  can_suspend: boolean
  can_resume: boolean
}

interface AmendmentBody {
  price: string
  interval_months: 1 | 3 | 6 | 12
  due_in_days: number
  module_keys: string[]
  expected_pending_revision_id: number | null
}

type CommandIntent
  = | { contractId: number, companyId: number | null, action: 'contract.amend', body: AmendmentBody }
    | { contractId: number, companyId: number | null, action: 'contract.renew', body: Record<string, never> }
    | { contractId: number, companyId: number | null, action: 'contract.suspend', body: { reason: string } }
    | { contractId: number, companyId: number | null, action: 'contract.resume', body: Record<string, never> }
    | { contractId: number, companyId: number | null, action: 'contract.cancel', body: { mode: 'immediate' | 'period_end', reason: string } }

const props = withDefaults(defineProps<{
  open: boolean
  companyName: string
  companyId?: number | null
  planName?: string
  contract: Contract | null
  subscriptionStatus: string | null
  trial: { ends_at: string, situation: string } | null
  balance: { outstanding: string, invoice_count: number } | null
  capabilities: Capabilities
  pending?: boolean
  error?: string | null
  recoveryKey?: string | null
}>(), {
  pending: false,
  companyId: null,
  planName: 'Plano ERP',
  error: null,
  recoveryKey: null
})

const emit = defineEmits<{
  'update:open': [open: boolean]
  'manage-modules': []
  'command': [intent: CommandIntent]
  'recover': [key: string]
}>()

const restoreModalFocus = useModalFocusReturn(toRef(props, 'open'))
const baseRevision = computed(() => props.contract?.pending_revision ?? props.contract?.current_revision ?? null)
const hasScheduledCancellation = computed(() => !!props.contract?.cancel_effective_on)
const canAmend = computed(() => !!props.contract
  && props.contract.agreement_status === 'effective'
  && props.capabilities.can_amend_terms
  && !hasScheduledCancellation.value)
const canRenew = computed(() => !!props.contract
  && props.contract.agreement_status === 'effective'
  && props.subscriptionStatus === 'active'
  && props.capabilities.can_manage_lifecycle
  && !props.contract.pending_revision
  && !hasScheduledCancellation.value)
const intervals = [
  { label: 'Mensal', value: '1' },
  { label: 'Trimestral', value: '3' },
  { label: 'Semestral', value: '6' },
  { label: 'Anual', value: '12' }
]
const draft = reactive({ price: '', intervalMonths: '1', dueInDays: '' })
const cancellationMode = ref<'immediate' | 'period_end'>('period_end')
const action = ref<'suspend' | 'cancel' | ''>('')
const actionReason = ref('')
const localError = ref('')
const discardOpen = ref(false)
const initialDraft = ref('')
const moduleNames = (keys: string[]) => keys.map(key => officeErpModuleOptions.find(option => option.value === key)?.label ?? key).join(', ') || 'Nenhum módulo habilitado'
const money = (value: string) => {
  try {
    return formatOfficeErpMoney(value)
  } catch {
    return 'Valor indisponível'
  }
}
const intervalName = (value: number) => intervals.find(option => option.value === String(value))?.label ?? 'Periodicidade indisponível'
const trialSituationName = (value: string) => ({
  trialing: 'Em período de teste',
  expired: 'Período de teste encerrado',
  converted: 'Convertido em assinatura'
} as Record<string, string>)[value] ?? 'Situação indisponível'
const date = (value?: string | null) => {
  if (!value) return 'Aguardando definição'
  const parsed = /^\d{4}-\d{2}-\d{2}$/.test(value)
    ? new Date(`${value}T12:00:00`)
    : new Date(value)
  return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(parsed) : 'Data indisponível'
}
const currentStatus = computed(() => {
  if (props.contract?.agreement_status === 'canceled') return 'Cancelada'
  if (props.contract?.manual_hold) return 'Suspensa'
  if (props.contract?.agreement_status === 'effective') return props.subscriptionStatus === 'past_due' ? 'Em atraso' : 'Ativa'
  if (props.contract?.agreement_status === 'accepted') return 'Aguardando ativação'
  return 'Situação indisponível'
})
const dirty = computed(() => JSON.stringify(draft) !== initialDraft.value)
const working = computed(() => props.pending)
const blocked = computed(() => working.value || !!props.recoveryKey)
const statusTone = computed(() => currentStatus.value === 'Ativa' ? 'success' : currentStatus.value === 'Cancelada' ? 'error' : 'warning')

watch(() => props.open, (open) => {
  if (!open) {
    discardOpen.value = false
    action.value = ''
    actionReason.value = ''
    return
  }
  const revision = baseRevision.value
  draft.price = revision ? revision.price.replace('.', ',') : ''
  draft.intervalMonths = String(revision?.interval_months ?? 1)
  draft.dueInDays = revision ? String(revision.due_in_days) : ''
  initialDraft.value = JSON.stringify(draft)
  localError.value = ''
  action.value = ''
  actionReason.value = ''
  cancellationMode.value = 'period_end'
  discardOpen.value = false
}, { immediate: true })

watch(() => [props.companyId, props.contract?.id] as const, (current, previous) => {
  if (props.open && (current[0] !== previous[0] || current[1] !== previous[1]))
    emit('update:open', false)
})

function requestClose() {
  if (working.value) return
  if (props.recoveryKey) {
    emit('update:open', false)
    return
  }
  if (dirty.value || action.value) discardOpen.value = true
  else emit('update:open', false)
}

function confirmClose() {
  discardOpen.value = false
  emit('update:open', false)
}

function scheduleTerms() {
  if (!canAmend.value || blocked.value || !props.contract || !baseRevision.value) return
  localError.value = ''
  let price: string
  try {
    price = parseOfficeErpMoney(draft.price.trim())
  } catch {
    localError.value = 'Informe o valor em reais com até duas casas decimais.'
    return
  }
  const interval = Number(draft.intervalMonths)
  if (![1, 3, 6, 12].includes(interval)) {
    localError.value = 'Selecione uma periodicidade válida.'
    return
  }
  if (!/^\d{1,5}$/.test(draft.dueInDays) || Number(draft.dueInDays) > 65535) {
    localError.value = 'Informe de 0 a 65.535 dias até o vencimento.'
    return
  }
  emit('command', {
    contractId: props.contract.id,
    companyId: props.companyId,
    action: 'contract.amend',
    body: {
      price,
      interval_months: interval as AmendmentBody['interval_months'],
      due_in_days: Number(draft.dueInDays),
      module_keys: [...baseRevision.value.module_keys].sort(),
      expected_pending_revision_id: props.contract.pending_revision?.id ?? null
    }
  })
}

function confirmAction() {
  if (!props.contract || blocked.value) return
  const reason = actionReason.value.trim()
  if (!reason) {
    localError.value = 'Informe o motivo para continuar.'
    return
  }
  if (reason.length > 2000) {
    localError.value = 'O motivo deve ter até 2.000 caracteres.'
    return
  }
  localError.value = ''
  if (action.value === 'suspend' && props.capabilities.can_suspend) {
    emit('command', { contractId: props.contract.id, companyId: props.companyId, action: 'contract.suspend', body: { reason } })
  } else if (action.value === 'cancel' && props.capabilities.can_cancel) {
    emit('command', { contractId: props.contract.id, companyId: props.companyId, action: 'contract.cancel', body: { mode: cancellationMode.value, reason } })
  }
}

function runLifecycle(command: 'contract.renew' | 'contract.resume') {
  if (!props.contract || blocked.value) return
  if (command === 'contract.renew' && canRenew.value)
    emit('command', { contractId: props.contract.id, companyId: props.companyId, action: command, body: {} })
  if (command === 'contract.resume' && props.capabilities.can_resume && props.contract.agreement_status === 'effective' && props.contract.manual_hold)
    emit('command', { contractId: props.contract.id, companyId: props.companyId, action: command, body: {} })
}

function openReasonAction(kind: 'suspend' | 'cancel') {
  if (blocked.value) return
  if (kind === 'suspend' && !props.capabilities.can_suspend) return
  if (kind === 'cancel' && !props.capabilities.can_cancel) return
  action.value = kind
  actionReason.value = ''
  localError.value = ''
}
</script>

<template>
  <UModal
    :open="open"
    title="Gerenciar assinatura"
    :description="companyName"
    :dismissible="!pending"
    :close="!pending"
    scrollable
    :ui="{
      content: 'flex w-[calc(100%-1rem)] max-h-[calc(100dvh-2rem)] max-w-2xl flex-col overflow-hidden sm:w-full',
      header: 'shrink-0',
      body: 'min-h-0 flex-1 overflow-y-auto',
      footer: canAmend && baseRevision && !action ? 'grid shrink-0 grid-cols-2 gap-2' : 'shrink-0 justify-end'
    }"
    @update:open="value => { if (!value) requestClose() }"
    @after:leave="restoreModalFocus"
  >
    <template #body>
      <div class="space-y-5">
        <UAlert
          v-if="error"
          color="error"
          title="Não foi possível atualizar a assinatura"
          :description="error"
        />
        <UAlert
          v-if="recoveryKey"
          color="warning"
          variant="subtle"
          title="O resultado da operação ainda não foi confirmado"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>Consulte o resultado pelo identificador original antes de enviar outra alteração.</span>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                :loading="pending"
                :disabled="pending"
                @click="emit('recover', recoveryKey!)"
              >
                Verificar resultado
              </UButton>
            </div>
          </template>
        </UAlert>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-default pb-4">
          <div>
            <p class="text-xs text-muted">
              Plano contratado
            </p>
            <p class="mt-1 text-sm font-medium text-highlighted">
              {{ planName }}
            </p>
          </div>
          <UBadge :color="statusTone" variant="subtle">
            {{ currentStatus }}
          </UBadge>
        </div>

        <UAlert
          v-if="trial"
          color="warning"
          variant="subtle"
          title="Período de teste"
          :description="`Término em ${date(trial.ends_at)} · ${trialSituationName(trial.situation)}`"
        />

        <section v-if="contract?.current_revision" aria-labelledby="subscription-current-terms" class="space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="subscription-current-terms" class="text-sm font-semibold text-highlighted">
              Termos vigentes
            </h3>
            <span class="text-xs text-muted">Revisão {{ contract.current_revision.revision_number ?? '—' }}</span>
          </div>
          <dl class="grid grid-cols-1 gap-x-6 gap-y-3 rounded-md border border-default p-3 sm:grid-cols-2">
            <div>
              <dt class="text-xs text-muted">
                Valor por período
              </dt><dd class="mt-1 text-sm font-medium">
                {{ money(contract.current_revision.price) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-muted">
                Periodicidade
              </dt><dd class="mt-1 text-sm font-medium">
                {{ intervalName(contract.current_revision.interval_months) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-muted">
                Dias até o vencimento
              </dt><dd class="mt-1 text-sm font-medium">
                {{ contract.current_revision.due_in_days }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-muted">
                Próximo vencimento
              </dt><dd class="mt-1 text-sm font-medium">
                {{ date(contract.next_billing_on) }}
              </dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-xs text-muted">
                Módulos vigentes
              </dt><dd class="mt-1 text-sm">
                {{ moduleNames(contract.current_revision.module_keys) }}
              </dd>
            </div>
          </dl>
        </section>

        <UAlert
          v-if="contract?.pending_revision"
          color="warning"
          variant="subtle"
          title="Alteração agendada para o próximo ciclo"
        >
          <template #description>
            <div class="space-y-1.5">
              <p>Revisão {{ contract.pending_revision.revision_number ?? '—' }} · aplicação em {{ date(contract.pending_revision.service_period_start) }}.</p>
              <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <dt class="text-xs text-muted">
                    Valor por período
                  </dt><dd class="text-sm">
                    {{ money(contract.pending_revision.price) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">
                    Periodicidade
                  </dt><dd class="text-sm">
                    {{ intervalName(contract.pending_revision.interval_months) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">
                    Dias até o vencimento
                  </dt><dd class="text-sm">
                    {{ contract.pending_revision.due_in_days }}
                  </dd>
                </div>
                <div class="sm:col-span-2">
                  <dt class="text-xs text-muted">
                    Módulos selecionados
                  </dt><dd class="text-sm">
                    {{ moduleNames(contract.pending_revision.module_keys) }}
                  </dd>
                </div>
              </dl>
              <p class="text-xs text-muted">
                Os termos vigentes continuam valendo até a data de aplicação.
              </p>
            </div>
          </template>
        </UAlert>

        <section v-if="canAmend && baseRevision" class="space-y-3 border-t border-default pt-4">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold text-highlighted">
                {{ contract?.pending_revision ? 'Revisar termos agendados' : 'Agendar termos para o próximo ciclo' }}
              </h3>
              <p class="mt-1 text-xs text-muted">
                Plano e data de início seguem o contrato e o calendário de cobrança.
              </p>
            </div>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              :disabled="blocked"
              @click="emit('manage-modules')"
            >
              Gerenciar módulos
            </UButton>
          </div>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <UFormField label="Valor por período (R$)" required>
              <UInput
                v-model="draft.price"
                aria-label="Valor por período"
                inputmode="decimal"
                autocomplete="off"
                class="w-full"
                :disabled="blocked"
              />
            </UFormField>
            <UFormField label="Periodicidade" required>
              <USelect
                v-model="draft.intervalMonths"
                aria-label="Periodicidade"
                :items="intervals"
                value-key="value"
                class="w-full"
                :disabled="blocked"
              />
            </UFormField>
            <UFormField label="Dias até o vencimento" required>
              <UInput
                v-model="draft.dueInDays"
                aria-label="Dias até o vencimento"
                inputmode="numeric"
                class="w-full"
                :disabled="blocked"
              />
            </UFormField>
          </div>
          <p class="text-xs text-muted">
            A edição conserva a seleção atual de módulos. Use “Gerenciar módulos” para alterar apenas esses acessos.
          </p>
        </section>

        <section v-if="balance" class="grid grid-cols-1 gap-3 border-t border-default pt-4 sm:grid-cols-2">
          <div>
            <p class="text-xs text-muted">
              Saldo em aberto
            </p><p class="mt-1 text-sm font-medium">
              {{ money(balance.outstanding) }}
            </p>
          </div>
          <div>
            <p class="text-xs text-muted">
              Faturas
            </p><p class="mt-1 text-sm font-medium">
              {{ balance.invoice_count }}
            </p>
          </div>
        </section>

        <section v-if="contract" class="space-y-3 border-t border-default pt-4">
          <h3 class="text-sm font-semibold text-highlighted">
            Ações da assinatura
          </h3>
          <p v-if="contract.agreement_status === 'canceled'" class="text-sm text-muted">
            Assinatura cancelada. O cadastro, as faturas e o histórico foram preservados.
          </p>
          <p v-else-if="contract.cancel_effective_on" class="text-sm text-muted">
            Cancelamento agendado para {{ date(contract.cancel_effective_on) }}. A licença continua até a data informada.
          </p>
          <div v-if="!contract.cancel_effective_on && contract.agreement_status !== 'canceled'" class="flex flex-wrap gap-2">
            <UButton
              v-if="canRenew"
              color="neutral"
              variant="outline"
              :disabled="blocked"
              @click="runLifecycle('contract.renew')"
            >
              Renovar assinatura
            </UButton>
            <UButton
              v-if="contract.agreement_status === 'effective' && capabilities.can_suspend && !contract.manual_hold"
              color="warning"
              variant="outline"
              :disabled="blocked"
              @click="openReasonAction('suspend')"
            >
              Suspender assinatura
            </UButton>
            <UButton
              v-if="contract.agreement_status === 'effective' && capabilities.can_resume && contract.manual_hold"
              color="success"
              variant="outline"
              :disabled="blocked"
              @click="runLifecycle('contract.resume')"
            >
              Retomar assinatura
            </UButton>
            <UButton
              v-if="capabilities.can_cancel"
              color="error"
              variant="outline"
              :disabled="blocked"
              @click="openReasonAction('cancel')"
            >
              Cancelar assinatura
            </UButton>
          </div>
          <div v-if="action" class="space-y-3 rounded-md border border-default p-3">
            <h4 class="text-sm font-medium text-highlighted">
              {{ action === 'cancel' ? 'Confirmar cancelamento' : 'Confirmar suspensão' }}
            </h4>
            <UFormField v-if="action === 'cancel'" label="Quando cancelar" required>
              <USelect
                v-model="cancellationMode"
                :items="[{ label: 'No fim do período atual', value: 'period_end' }, { label: 'Imediatamente', value: 'immediate' }]"
                aria-label="Quando cancelar"
                class="w-full"
                :disabled="blocked"
              />
            </UFormField>
            <UFormField label="Motivo" required>
              <UTextarea
                v-model="actionReason"
                :rows="3"
                maxlength="2000"
                aria-label="Motivo da alteração"
                class="w-full"
                :disabled="blocked"
              />
            </UFormField>
            <div class="flex flex-wrap justify-end gap-2">
              <UButton
                color="neutral"
                variant="outline"
                :disabled="working"
                @click="action = ''; localError = ''"
              >
                Voltar
              </UButton>
              <UButton :color="action === 'cancel' ? 'error' : 'warning'" :disabled="blocked" @click="confirmAction">
                {{ action === 'cancel' ? 'Confirmar cancelamento' : 'Confirmar suspensão' }}
              </UButton>
            </div>
          </div>
        </section>

        <UAlert
          v-if="localError"
          color="error"
          title="Revise os dados"
          :description="localError"
        />
      </div>
    </template>
    <template #footer>
      <UButton
        color="neutral"
        variant="outline"
        :disabled="working"
        class="min-h-11 w-full px-1 text-xs sm:text-sm"
        @click="requestClose"
      >
        Fechar
      </UButton>
      <UButton
        v-if="canAmend && baseRevision && !action"
        color="neutral"
        :loading="pending"
        :disabled="blocked || !dirty"
        class="min-h-11 w-full px-1 text-xs sm:text-sm"
        @click="scheduleTerms"
      >
        Agendar alteração
      </UButton>
    </template>
  </UModal>

  <UModal
    v-model:open="discardOpen"
    title="Descartar alterações?"
    description="Os termos não enviados serão perdidos."
    :ui="{ content: 'w-[calc(100%-1rem)] max-w-md sm:w-full', footer: 'justify-end' }"
  >
    <template #footer>
      <UButton color="neutral" variant="outline" @click="discardOpen = false">
        Continuar editando
      </UButton>
      <UButton color="error" @click="confirmClose">
        Descartar alterações
      </UButton>
    </template>
  </UModal>
</template>
