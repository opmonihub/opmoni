<script setup lang="ts">
import { focusFormError, settingsFieldRowClass } from '~/utils/formPresentation'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { pageFormMeasureClass } from '~/utils/pageShell'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import type { TableColumn } from '@nuxt/ui'
import type { ContextToken, OfficeErpCommandResult } from '#shared/types/officeErpCommercial'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage, apiFieldErrors } from '~/composables/useApiError'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Configurações comerciais · Opmoni' })

type PolicyMode = 'manual' | 'automatic'
interface OfficeErpSettings {
  mode: PolicyMode
  grace_days: number
  billing_timezone: string
}
interface TrialLinkPlan {
  id: number
  name: string
  module_keys: string[]
}
interface TrialLinkResource {
  configured: boolean
  enabled: boolean
  revision: number | null
  url: string | null
  erp_plan_id: number | null
  plan: { name: string, module_keys: string[] } | null
  plans?: TrialLinkPlan[]
}
interface PolicyPreviewRow {
  contract_id: number
  client_name: string
  company_name: string
  current_status: string
  resulting_status: string
  outstanding: string
  overdue: boolean
}
interface PolicyPreview {
  data: PolicyPreviewRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
  affected_count: number
}

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const toast = useToast()

const settings = reactive<OfficeErpSettings>({ mode: 'manual', grace_days: 0, billing_timezone: 'America/Sao_Paulo' })
const loaded = ref(false)
const loading = ref(false)
const loadError = ref('')
const loadDenied = ref('')
const fieldErrors = ref<Record<string, string>>({})
const draftError = ref('')
const preview = ref<PolicyPreview | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
const page = ref(1)
const confirmOpen = ref(false)
const restorePolicyFocus = useModalFocusReturn(confirmOpen)
const trialLinkFieldErrors = ref<Record<string, string[]>>({})
watch(fieldErrors, () => {
  void focusFormError(undefined, 'office-erp-policy')
})
watch(trialLinkFieldErrors, () => {
  void focusFormError(undefined, 'office-erp-trial-link')
})
const commandBusy = ref(false)
const commandBusyGeneration = ref(0)
const recoveryMessage = ref('')
const recoveryAction = ref<'policy' | 'trial-link'>('policy')
const trialLink = ref<TrialLinkResource | null>(null)
const trialLinkPlanId = ref<number | null>(null)
const trialLinkPlanSelectModel = computed<number | undefined>({
  get: () => trialLinkPlanId.value ?? undefined,
  set: (value) => { trialLinkPlanId.value = value ?? null }
})
const trialLinkEnabled = ref(false)
const trialLinkLoading = ref(false)
const trialLinkBusy = ref(false)
const trialLinkError = ref('')
const trialLinkPlans = ref<TrialLinkPlan[]>([])
let readGeneration = 0
let previewGeneration = 0
let trialLinkGeneration = 0

const capabilities = context.capabilities
const identityStamp = useOfficeErpReadIdentity(() => [
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null
], () => capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const recoveryKey = computed(() => command.recoveryKey.value)
const canConfigure = computed(() => capabilities.value?.can_configure === true)
const configureRestriction = computed(() => {
  if (canConfigure.value) return ''
  const reasons = capabilities.value?.capability_reasons.can_configure ?? []
  const messages = reasons.map((reason) => {
    switch (reason) {
      case 'account_suspended': return 'O escritório selecionado está suspenso.'
      case 'account_inactive': return 'O escritório selecionado está inativo.'
      case 'office_admin_required':
      case 'membership_role_not_allowed': return 'Somente administradores do escritório podem alterar esta configuração.'
      default: return ''
    }
  }).filter(Boolean)
  return [...new Set(messages)].join(' ') || 'O acesso do escritório não permite alterar esta configuração.'
})
const canManageTrialLink = computed(() => capabilities.value?.can_manage_trial_link === true)
const saveDisabled = computed(() => !loaded.value || !canConfigure.value || loading.value || commandBusy.value || command.pending.value || !isValidDraft())
const previewRows = computed(() => preview.value?.data ?? [])
const totalAffected = computed(() => preview.value?.affected_count ?? null)
const previewMeta = computed(() => preview.value?.meta ?? null)
const columns: TableColumn<PolicyPreviewRow>[] = [
  { accessorKey: 'client_name', header: 'Cliente do escritório' },
  { accessorKey: 'company_name', header: 'Empresa' },
  { accessorKey: 'resulting_status', header: 'Resultado previsto' },
  { accessorKey: 'outstanding', header: 'Saldo em aberto' },
  { accessorKey: 'overdue', header: 'Em atraso após tolerância' }
]

function isValidDraft(): boolean {
  return Number.isInteger(settings.grace_days)
    && settings.grace_days >= 0
    && settings.grace_days <= 65535
    && settings.billing_timezone.trim().length > 0
}

function draftPayload() {
  return {
    mode: settings.mode,
    grace_days: settings.grace_days,
    billing_timezone: settings.billing_timezone.trim()
  }
}

function isCurrent(scope: string, token: ContextToken): boolean {
  return scope === identityStamp.value && context.isCurrent(token)
}

function clearProtectedState() {
  readGeneration++
  previewGeneration++
  preview.value = null
  previewError.value = ''
  loadError.value = ''
  loadDenied.value = ''
  fieldErrors.value = {}
  draftError.value = ''
  recoveryMessage.value = ''
  recoveryAction.value = 'policy'
  loaded.value = false
  loading.value = false
  previewLoading.value = false
  confirmOpen.value = false
  settings.mode = 'manual'
  settings.grace_days = 0
  settings.billing_timezone = 'America/Sao_Paulo'
  trialLinkGeneration++
  trialLink.value = null
  trialLinkPlans.value = []
  trialLinkPlanId.value = null
  trialLinkEnabled.value = false
  trialLinkError.value = ''
  trialLinkFieldErrors.value = {}
  trialLinkLoading.value = false
  trialLinkBusy.value = false
}

async function loadTrialLink() {
  const read = reads.begin('loadTrialLink')
  const request = ++trialLinkGeneration
  const scope = identityStamp.value
  let token = context.capture()
  trialLinkLoading.value = true
  trialLinkError.value = ''
  trialLink.value = null
  trialLinkPlans.value = []
  trialLinkPlanId.value = null
  try {
    await context.refresh()
    if (!read.current() || request !== trialLinkGeneration || scope !== identityStamp.value) return
    token = context.capture()
    if (!canManageTrialLink.value) return
    const response = await api<{ data: TrialLinkResource }>('/erp-commercial/trial-link', { signal: read.signal })
    if (!read.current() || request !== trialLinkGeneration || !isCurrent(scope, token)) return
    const data = response?.data
    if (!data || typeof data.configured !== 'boolean' || typeof data.enabled !== 'boolean'
      || !Array.isArray(data.plans) || (data.url !== null && typeof data.url !== 'string')
      || (data.erp_plan_id !== null && !Number.isSafeInteger(data.erp_plan_id))) {
      throw new Error('O link de teste retornou dados incompletos.')
    }
    trialLink.value = data
    trialLinkPlans.value = data.plans.filter(plan => Number.isSafeInteger(plan.id) && typeof plan.name === 'string' && Array.isArray(plan.module_keys))
    trialLinkPlanId.value = data.erp_plan_id
    trialLinkEnabled.value = data.enabled
  } catch (error) {
    if (read.current() && request === trialLinkGeneration && isCurrent(scope, token))
      trialLinkError.value = apiErrorMessage(error) ?? (error instanceof Error ? error.message : 'Não foi possível carregar o link de teste.')
  } finally {
    if (read.current() && request === trialLinkGeneration && isCurrent(scope, token))
      trialLinkLoading.value = false
  }
}

function applyTrialLinkResult(result: OfficeErpCommandResult<TrialLinkResource>, scope: string, token: ContextToken) {
  if (!isCurrent(scope, token)) return false
  if (result.status === 'confirmed') {
    trialLinkError.value = ''
    recoveryMessage.value = ''
    recoveryAction.value = 'policy'
    const plans = Array.isArray(result.data.plans) ? result.data.plans : trialLinkPlans.value
    trialLink.value = { ...result.data, plans }
    trialLinkPlanId.value = result.data.erp_plan_id
    trialLinkEnabled.value = result.data.enabled
    trialLinkPlans.value = plans
    return true
  }
  if (result.status === 'unknown') {
    recoveryAction.value = 'trial-link'
    recoveryMessage.value = 'O link não foi atualizado com confirmação. Consulte a operação original antes de tentar novamente.'
  } else if (result.status === 'failed') {
    trialLinkError.value = result.failure.message
    trialLinkFieldErrors.value = result.failure.errors ?? {}
  }
  return false
}

async function saveTrialLink() {
  if (!canManageTrialLink.value || !trialLinkPlanId.value || commandBusy.value || command.pending.value) return
  const scope = identityStamp.value
  const token = context.capture()
  const finishCommand = beginCommand()
  trialLinkBusy.value = true
  trialLinkError.value = ''
  try {
    const result = await command.execute<TrialLinkResource>('trial.link.configure', '/erp-commercial/trial-link', {
      erp_plan_id: trialLinkPlanId.value,
      enabled: trialLinkEnabled.value
    }, 'PUT')
    if (applyTrialLinkResult(result, scope, token))
      toast.add({ title: 'Link de teste atualizado', color: 'success' })
  } finally {
    finishCommand()
    trialLinkBusy.value = false
  }
}

async function rotateTrialLink() {
  if (!canManageTrialLink.value || !trialLink.value?.configured || commandBusy.value || command.pending.value) return
  const scope = identityStamp.value
  const token = context.capture()
  const finishCommand = beginCommand()
  trialLinkBusy.value = true
  trialLinkError.value = ''
  try {
    const result = await command.execute<TrialLinkResource>('trial.link.rotate', '/erp-commercial/trial-link/rotate', {}, 'POST')
    if (applyTrialLinkResult(result, scope, token))
      toast.add({ title: 'Link de teste renovado', color: 'success' })
  } finally {
    finishCommand()
    trialLinkBusy.value = false
  }
}

async function copyTrialLink() {
  const url = trialLink.value?.url
  if (!url) return
  try {
    await navigator.clipboard.writeText(url)
    toast.add({ title: 'Link copiado', color: 'success' })
  } catch {
    trialLinkError.value = 'Não foi possível copiar automaticamente. Selecione o endereço e copie manualmente.'
  }
}

function invalidatePreview() {
  previewGeneration++
  previewLoading.value = false
  preview.value = null
  previewError.value = ''
  page.value = 1
}

async function loadSettings() {
  const read = reads.begin('loadSettings')
  const request = ++readGeneration
  const scope = identityStamp.value
  let token = context.capture()
  loading.value = true
  loadError.value = ''
  loadDenied.value = ''
  try {
    if (currentAccount.value?.status !== 'active') {
      loadDenied.value = currentAccount.value?.status === 'suspended'
        ? 'O escritório selecionado está suspenso. Selecione um escritório ativo para continuar.'
        : 'O escritório selecionado está inativo. Selecione um escritório ativo para continuar.'
      return
    }
    if (currentAccount.value.role !== 'admin') {
      loadDenied.value = 'Somente administradores do escritório podem acessar estas configurações.'
      return
    }
    await context.refresh()
    if (!read.current() || request !== readGeneration || scope !== identityStamp.value) return
    token = context.capture()
    if (!capabilities.value) {
      throw new Error('Não foi possível confirmar o acesso comercial do escritório.')
    }
    if (!capabilities.value.can_read) {
      loadDenied.value = 'O contexto comercial atual não permite consultar estas configurações.'
      return
    }
    token = context.capture()
    const response = await api<{ data: OfficeErpSettings }>('/erp-commercial/settings', { signal: read.signal })
    if (!read.current() || request !== readGeneration || !isCurrent(scope, token)) return
    if (!response?.data || !['manual', 'automatic'].includes(response.data.mode)
      || !Number.isInteger(response.data.grace_days)
      || typeof response.data.billing_timezone !== 'string') {
      throw new Error('A configuração retornada está incompleta.')
    }
    settings.mode = response.data.mode
    settings.grace_days = response.data.grace_days
    settings.billing_timezone = response.data.billing_timezone
    loaded.value = true
    invalidatePreview()
  } catch (error) {
    if (read.current() && request === readGeneration && isCurrent(scope, token))
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível carregar a política comercial.'
  } finally {
    if (read.current() && request === readGeneration && isCurrent(scope, token))
      loading.value = false
  }
}

async function previewConsequences() {
  if (!loaded.value || !canConfigure.value || previewLoading.value || commandBusy.value || command.pending.value || !isValidDraft()) return
  const request = ++previewGeneration
  const scope = identityStamp.value
  let token = context.capture()
  const requestedDraft = JSON.stringify(draftPayload())
  previewLoading.value = true
  previewError.value = ''
  try {
    await context.refresh()
    if (request !== previewGeneration || !isCurrent(scope, token) || requestedDraft !== JSON.stringify(draftPayload())) return
    token = context.capture()
    const response = await api<PolicyPreview>('/erp-commercial/settings/preview', {
      method: 'POST',
      body: { ...draftPayload(), page: page.value }
    })
    if (request !== previewGeneration || !isCurrent(scope, token) || requestedDraft !== JSON.stringify(draftPayload())) return
    if (!response || !Array.isArray(response.data) || !response.meta
      || !Number.isSafeInteger(response.affected_count) || response.affected_count < 0
      || !Number.isSafeInteger(response.meta.current_page) || !Number.isSafeInteger(response.meta.last_page)
      || !Number.isSafeInteger(response.meta.per_page) || !Number.isSafeInteger(response.meta.total)) {
      throw new Error('A pré-visualização retornou dados incompletos.')
    }
    preview.value = response
  } catch (error) {
    if (request === previewGeneration && isCurrent(scope, token))
      previewError.value = apiErrorMessage(error) ?? 'Não foi possível pré-visualizar as consequências agora.'
  } finally {
    if (request === previewGeneration && isCurrent(scope, token))
      previewLoading.value = false
  }
}

function beginCommand() {
  const owner = ++commandBusyGeneration.value
  commandBusy.value = true
  return () => {
    if (owner === commandBusyGeneration.value)
      commandBusy.value = false
  }
}

function applyCommandResult<T>(result: OfficeErpCommandResult<T>, scope: string): boolean {
  if (scope !== identityStamp.value) return false
  if (result.status === 'confirmed') {
    fieldErrors.value = {}
    draftError.value = ''
    recoveryMessage.value = ''
    return true
  }
  if (result.status === 'unknown') {
    recoveryMessage.value = 'O resultado não foi confirmado. Verifique a operação original antes de tentar novamente.'
    return false
  }
  if (result.status === 'failed') {
    fieldErrors.value = apiFieldErrors({ data: { errors: result.failure.errors ?? {} } })
    draftError.value = result.failure.message
  }
  return false
}

async function savePolicy(confirmAutomatic: boolean) {
  if (!loaded.value || !canConfigure.value || saveDisabled.value || !isValidDraft()) return
  if (settings.mode === 'automatic' && !confirmAutomatic) {
    confirmOpen.value = true
    return
  }
  if (settings.mode === 'automatic' && confirmAutomatic && !preview.value) return
  confirmOpen.value = false
  const scope = identityStamp.value
  const token = context.capture()
  const finishCommand = beginCommand()
  fieldErrors.value = {}
  draftError.value = ''
  let result: OfficeErpCommandResult<OfficeErpSettings>
  try {
    result = await command.execute<OfficeErpSettings>('policy.configure', '/erp-commercial/settings', {
      ...draftPayload(),
      confirm_automatic: confirmAutomatic
    }, 'PATCH')
  } finally {
    finishCommand()
  }
  if (!isCurrent(scope, token)) return
  if (applyCommandResult(result, scope)) {
    toast.add({ title: 'Política comercial salva', color: 'success' })
    await loadSettings()
  }
}

async function recoverOperation() {
  const key = recoveryKey.value
  if (!key || commandBusy.value) return
  const scope = identityStamp.value
  const token = context.capture()
  const finishCommand = beginCommand()
  let result: OfficeErpCommandResult<unknown>
  try {
    result = await command.recover<unknown>(key)
  } finally {
    finishCommand()
  }
  if (!isCurrent(scope, token)) return
  if (recoveryAction.value === 'trial-link') {
    if (result.status === 'confirmed') {
      recoveryAction.value = 'policy'
      recoveryMessage.value = ''
      await loadTrialLink()
    } else if (result.status === 'unknown') {
      recoveryMessage.value = 'Ainda não foi possível confirmar a atualização do link. Nenhuma nova operação foi enviada.'
    } else if (result.status === 'failed') {
      trialLinkError.value = result.failure.message
    }
    return
  }
  if (applyCommandResult(result, scope)) {
    toast.add({ title: 'Operação confirmada', color: 'success' })
    await loadSettings()
  } else if (result.status === 'unknown') {
    recoveryMessage.value = 'Ainda não foi possível confirmar o resultado. A identificação original foi mantida; nenhuma nova alteração foi enviada.'
  }
}

watch(() => JSON.stringify(draftPayload()), () => {
  if (loaded.value) invalidatePreview()
}, { flush: 'sync' })
watch(page, () => {
  if (preview.value && loaded.value)
    void previewConsequences()
})
watch(identityStamp, () => {
  commandBusyGeneration.value++
  commandBusy.value = false
  clearProtectedState()
  void loadSettings()
  void loadTrialLink()
}, { flush: 'sync' })
onMounted(() => {
  void loadSettings()
  void loadTrialLink()
})
</script>

<template>
  <div :class="[pageFormMeasureClass, 'flex flex-col gap-6']">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-base font-semibold text-highlighted">
        Configurações comerciais
      </h2>
      <UButton
        to="/gestao-erp/planos"
        color="neutral"
        variant="outline"
        icon="i-lucide-tags"
        size="sm"
      >
        Gerenciar planos
      </UButton>
    </header>

    <UAlert
      v-if="!canConfigure && !loadError && !loadDenied"
      color="warning"
      title="Configuração indisponível"
      :description="configureRestriction"
    />
    <UAlert
      v-if="loadDenied"
      color="warning"
      title="Acesso às configurações indisponível"
      :description="loadDenied"
    />
    <UAlert
      v-if="loadError"
      color="error"
      title="Não foi possível carregar as configurações"
    >
      <template #description>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span>{{ loadError }}</span>
          <UButton
            color="neutral"
            variant="outline"
            :loading="loading"
            @click="loadSettings"
          >
            Tentar novamente
          </UButton>
        </div>
      </template>
    </UAlert>

    <UPageCard v-if="canManageTrialLink || trialLinkLoading" variant="subtle">
      <template #header>
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-semibold text-highlighted">
            Link de teste ERP
          </h2>
          <UPopover :content="{ align: 'end' }">
            <UButton
              color="neutral"
              variant="ghost"
              icon="i-lucide-info"
              aria-label="Sobre o link de teste"
              class="size-11 min-w-11 shrink-0"
            />
            <template #content>
              <p class="max-w-xs p-3 text-sm text-muted">
                O teste fica atribuído a este escritório e não concede acesso operacional à conta.
              </p>
            </template>
          </UPopover>
        </div>
      </template>
      <USkeleton v-if="trialLinkLoading" class="h-36 w-full" />
      <UForm
        v-else
        id="office-erp-trial-link"
        :state="{ erp_plan_id: trialLinkPlanId, enabled: trialLinkEnabled }"
        :disabled="trialLinkBusy || commandBusy || command.pending.value"
        class="space-y-4"
        @submit="saveTrialLink"
        @error="event => focusFormError(event, 'office-erp-trial-link')"
      >
        <UAlert
          v-if="trialLinkError"
          color="error"
          title="Não foi possível atualizar o link"
          :description="trialLinkError"
        >
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              :loading="trialLinkLoading"
              @click="loadTrialLink"
            >
              Tentar novamente
            </UButton>
          </template>
        </UAlert>
        <div v-if="trialLink?.url" class="space-y-2">
          <UFormField label="Endereço público do teste">
            <div class="flex flex-col gap-2 sm:flex-row">
              <UInput
                :model-value="trialLink.url"
                readonly
                class="min-w-0 flex-1"
                aria-label="Endereço do link de teste"
              />
              <UButton
                color="neutral"
                variant="outline"
                :disabled="!trialLink.enabled || trialLinkBusy"
                @click="copyTrialLink"
              >
                Copiar link
              </UButton>
            </div>
          </UFormField>
          <p v-if="!trialLink.enabled" class="text-sm text-warning">
            O link está desativado e não inicia novos testes.
          </p>
          <p v-else class="text-sm text-muted">
            Plano atual: {{ trialLink.plan?.name ?? 'Plano técnico selecionado' }} · revisão {{ trialLink.revision ?? '—' }}
          </p>
        </div>
        <UAlert
          v-if="!trialLinkPlans.length"
          color="warning"
          title="Nenhum plano disponível"
          description="O escritório precisa de capacidade ERP e módulos liberados pela plataforma para configurar um link de teste."
        />
        <div v-else class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
          <UFormField
            name="erp_plan_id"
            :error="trialLinkFieldErrors.erp_plan_id?.[0]"
            label="Plano ERP do teste"
            required
          >
            <USelect
              v-model="trialLinkPlanSelectModel"
              :items="trialLinkPlans.map(plan => ({ label: plan.name, value: plan.id }))"
              placeholder="Selecione um plano"
              class="w-full"
            />
          </UFormField>
          <UFormField name="enabled" :error="trialLinkFieldErrors.enabled?.[0]">
            <USwitch v-model="trialLinkEnabled" label="Link ativo" :disabled="trialLinkBusy || commandBusy || command.pending.value" />
          </UFormField>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-default pt-5">
          <UButton type="submit" :loading="trialLinkBusy" :disabled="!trialLinkPlanId || !trialLinkPlans.length || trialLinkBusy || commandBusy || command.pending.value">
            {{ trialLink?.configured ? 'Salvar link' : 'Criar link' }}
          </UButton>
          <UButton
            v-if="trialLink?.configured"
            color="warning"
            variant="outline"
            :loading="trialLinkBusy"
            :disabled="trialLinkBusy || commandBusy || command.pending.value"
            @click="rotateTrialLink"
          >
            Renovar endereço do link
          </UButton>
        </div>
      </UForm>
    </UPageCard>
    <UAlert
      v-if="recoveryKey && recoveryAction === 'trial-link'"
      color="warning"
      title="A atualização do link precisa ser verificada"
      :description="recoveryMessage"
    >
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          :loading="commandBusy"
          :disabled="commandBusy"
          @click="recoverOperation"
        >
          Consultar operação original
        </UButton>
      </template>
    </UAlert>

    <USkeleton v-if="loading && !loaded" class="h-56 w-full" />

    <template v-else-if="loaded">
      <UAlert
        v-if="recoveryKey && recoveryAction === 'policy'"
        color="warning"
        title="Há uma alteração sem confirmação"
        description="A alteração não será reenviada automaticamente. Consulte o resultado original antes de iniciar outra."
      >
        <template #description>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <span>{{ recoveryMessage || 'A identificação original está preservada para consulta.' }}</span>
            <UButton
              color="neutral"
              variant="outline"
              :loading="commandBusy"
              :disabled="commandBusy"
              @click="recoverOperation"
            >
              Verificar resultado
            </UButton>
          </div>
        </template>
      </UAlert>

      <UAlert
        v-if="draftError"
        color="error"
        title="Configuração não salva"
        :description="draftError"
        role="alert"
      />

      <UPageCard variant="subtle">
        <template #header>
          <div class="flex items-center justify-between gap-2">
            <h2 class="font-semibold text-highlighted">
              Política de inadimplência
            </h2>
            <UPopover :content="{ align: 'end' }">
              <UButton
                color="neutral"
                variant="ghost"
                icon="i-lucide-info"
                aria-label="Sobre a suspensão por atraso"
                class="size-11 min-w-11 shrink-0"
              />
              <template #content>
                <p class="max-w-xs p-3 text-sm text-muted">
                  Suspende contratos com saldo vencido após o prazo informado. Suspensões manuais ou de segurança permanecem.
                </p>
              </template>
            </UPopover>
          </div>
        </template>
        <UForm
          id="office-erp-policy"
          :state="settings"
          :disabled="!canConfigure || commandBusy || command.pending.value"
          class="space-y-4"
          @submit="savePolicy(false)"
          @error="event => focusFormError(event, 'office-erp-policy')"
        >
          <UFormField name="mode" :error="fieldErrors.mode" class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div class="max-w-2xl space-y-1">
              <p class="font-medium text-highlighted">
                Automatizar suspensão por atraso
              </p>
            </div>
            <USwitch
              :model-value="settings.mode === 'automatic'"
              :disabled="!canConfigure || commandBusy || command.pending.value"
              aria-label="Automatizar suspensão por atraso"
              @update:model-value="(enabled: boolean) => { settings.mode = enabled ? 'automatic' : 'manual' }"
            />
          </UFormField>

          <UFormField
            name="grace_days"
            label="Dias de tolerância"
            :class="settingsFieldRowClass"
            :error="fieldErrors.grace_days"
          >
            <UInput
              v-model.number="settings.grace_days"
              type="number"
              min="0"
              max="65535"
              step="1"
              inputmode="numeric"
              :disabled="!canConfigure || commandBusy || command.pending.value"
              aria-describedby="grace-help"
              class="w-full sm:max-w-xs"
            />
            <template #help>
              <span id="grace-help">Prazo contado em dias corridos após o vencimento.</span>
            </template>
          </UFormField>
          <UFormField
            name="billing_timezone"
            label="Fuso horário de cobrança"
            :class="settingsFieldRowClass"
            :error="fieldErrors.billing_timezone"
          >
            <UInput
              v-model="settings.billing_timezone"
              autocomplete="off"
              placeholder="America/Sao_Paulo"
              :disabled="!canConfigure || commandBusy || command.pending.value"
              class="w-full sm:max-w-sm"
            />
          </UFormField>

          <div class="flex flex-wrap items-center gap-3 border-t border-default pt-5">
            <UButton
              color="neutral"
              variant="outline"
              :loading="previewLoading"
              :disabled="!canConfigure || !isValidDraft() || previewLoading || commandBusy || command.pending.value"
              @click="previewConsequences"
            >
              Pré-visualizar consequências
            </UButton>
            <UButton
              id="save-policy"
              type="submit"
              :loading="commandBusy"
              :disabled="saveDisabled"
            >
              Salvar configuração
            </UButton>
          </div>
        </UForm>
      </UPageCard>

      <UAlert
        v-if="previewError"
        color="error"
        title="Pré-visualização indisponível"
        :description="previewError"
      >
        <template #description>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <span>{{ previewError }}</span>
            <UButton
              color="neutral"
              variant="outline"
              :loading="previewLoading"
              @click="previewConsequences"
            >
              Tentar novamente
            </UButton>
          </div>
        </template>
      </UAlert>

      <UPageCard v-if="preview" variant="subtle">
        <template #header>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
              <h2 class="font-semibold text-highlighted">
                Contratos afetados
              </h2>
              <p class="text-sm text-muted">
                Resultado estimado para o rascunho atual. A prévia não salva alterações.
              </p>
            </div>
            <UBadge color="warning" variant="subtle">
              {{ totalAffected }} contratos afetados
            </UBadge>
          </div>
        </template>
        <div :class="panelBodyClass">
          <div class="overflow-x-auto">
            <UTable
              :columns="columns"
              :data="previewRows"
              :loading="previewLoading"
              :ui="panelTableUi"
              tabindex="0"
              aria-label="Tabela de contratos afetados"
            >
              <template #resulting_status-cell="{ row }">
                {{ row.original.resulting_status === 'past_due' ? 'Suspenso por atraso' : row.original.resulting_status }}
              </template>
              <template #outstanding-cell="{ row }">
                {{ formatOfficeErpMoney(row.original.outstanding) }}
              </template>
              <template #overdue-cell="{ row }">
                {{ row.original.overdue ? 'Sim' : 'Não' }}
              </template>
              <template #empty>
                <div class="py-8 text-center text-sm text-muted">
                  Nenhum contrato será suspenso com esta configuração.
                </div>
              </template>
            </UTable>
          </div>
          <footer v-if="previewMeta" :class="panelFooterClass">
            <span :class="panelFooterCountClass">{{ totalAffected }} contratos afetados</span>
            <div :class="panelPaginationClass">
              <UPagination
                v-model:page="page"
                :total="previewMeta.total"
                :items-per-page="previewMeta.per_page"
                :disabled="previewLoading"
                aria-label="Páginas dos contratos afetados"
              />
            </div>
          </footer>
        </div>
      </UPageCard>
    </template>

    <UModal
      v-model:open="confirmOpen"
      title="Confirmar política automática"
      description="A suspensão por atraso será aplicada aos contratos que atendam às condições da pré-visualização."
      :ui="{ footer: 'shrink-0 flex-wrap justify-end' }"
      @after:leave="restorePolicyFocus"
    >
      <template #body>
        <UForm
          id="office-erp-confirm-policy"
          :state="settings"
          class="space-y-3 text-sm"
          @submit="savePolicy(true)"
          @error="event => focusFormError(event, 'office-erp-confirm-policy')"
        >
          <p>Confirme somente depois de revisar a quantidade e a lista de contratos afetados.</p>
          <p class="text-muted">
            A confirmação salva a configuração. O controle de rascunho, por si só, não altera contratos.
          </p>
          <p v-if="!preview" class="font-medium text-warning">
            Pré-visualize as consequências antes de confirmar a política automática.
          </p>
          <UAlert
            v-if="draftError"
            color="error"
            title="Não foi possível salvar"
            :description="draftError"
          />
        </UForm>
      </template>
      <template #footer>
        <div class="flex w-full flex-wrap justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            :disabled="commandBusy"
            @click="confirmOpen = false"
          >
            Cancelar
          </UButton>
          <UButton
            color="neutral"
            :loading="commandBusy"
            :disabled="!preview || saveDisabled"
            type="submit"
            form="office-erp-confirm-policy"
          >
            Confirmar ativação automática
          </UButton>
        </div>
      </template>
    </UModal>
  </div>
</template>
