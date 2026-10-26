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
interface ContractDetail {
  id: number
  erp_plan_id?: number | null
  erp_plan?: { id: number, name: string } | null
  client: { id: number, name: string }
  company: { id: number, name: string, owner_setup_status?: 'unclaimed' | 'claimed' } | null
  trial?: { id: number, started_at: string, ends_at: string, converted_at: string | null, expired_at: string | null, situation: 'trialing' | 'expired' | 'converted' } | null
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
let loadGeneration = 0

const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null, currentAccount.value?.role ?? null,
  context.capabilities.value?.capability_key ?? null
]))
const recoveryKey = computed(() => command.recoveryKey.value)
const canMaintain = computed(() => !!context.capabilities.value?.can_maintain && !!record.value)
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
  record.value = null
  mutationError.value = ''
  loadError.value = ''
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

      <section v-if="record.agreement_status === 'accepted'" class="border-t border-default pt-4">
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
