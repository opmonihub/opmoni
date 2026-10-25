<script setup lang="ts">
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
const fieldErrors = ref<Record<string, string>>({})
const draftError = ref('')
const preview = ref<PolicyPreview | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
const page = ref(1)
const confirmOpen = ref(false)
const commandBusy = ref(false)
const commandBusyGeneration = ref(0)
const recoveryMessage = ref('')
let readGeneration = 0
let previewGeneration = 0

const capabilities = context.capabilities
const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null,
  capabilities.value?.capability_key ?? null
]))
const recoveryKey = computed(() => command.recoveryKey.value)
const canConfigure = computed(() => capabilities.value?.can_configure === true)
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
  fieldErrors.value = {}
  draftError.value = ''
  recoveryMessage.value = ''
  loaded.value = false
  loading.value = false
  previewLoading.value = false
  confirmOpen.value = false
  settings.mode = 'manual'
  settings.grace_days = 0
  settings.billing_timezone = 'America/Sao_Paulo'
}

function invalidatePreview() {
  previewGeneration++
  preview.value = null
  previewError.value = ''
  page.value = 1
}

async function loadSettings() {
  const request = ++readGeneration
  const scope = identityStamp.value
  let token = context.capture()
  loading.value = true
  loadError.value = ''
  try {
    await context.refresh()
    if (request !== readGeneration || !isCurrent(scope, token)) return
    if (!capabilities.value?.can_read || !capabilities.value.can_configure) {
      throw new Error(capabilities.value
        ? 'O escritório selecionado não tem permissão para configurar a política comercial.'
        : 'Não foi possível confirmar o acesso comercial do escritório.')
    }
    token = context.capture()
    const response = await api<{ data: OfficeErpSettings }>('/erp-commercial/settings')
    if (request !== readGeneration || !isCurrent(scope, token)) return
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
    if (request === readGeneration && isCurrent(scope, token))
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível carregar a política comercial.'
  } finally {
    if (request === readGeneration && isCurrent(scope, token))
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
  let result: OfficeErpCommandResult<OfficeErpSettings>
  try {
    result = await command.recover<OfficeErpSettings>(key)
  } finally {
    finishCommand()
  }
  if (!isCurrent(scope, token)) return
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
}, { flush: 'sync' })
onMounted(loadSettings)
</script>

<template>
  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <header class="space-y-2">
      <p class="text-sm font-medium text-primary">
        Gestão ERP · Escritório
      </p>
      <h1 class="text-2xl font-semibold text-highlighted sm:text-3xl">
        Configurações comerciais
      </h1>
      <p class="max-w-3xl text-sm text-muted">
        Defina como atrasos afetam os contratos ERP do escritório. A política inicial é manual, sem tolerância automática.
      </p>
    </header>

    <UAlert
      v-if="!canConfigure && !loadError"
      color="warning"
      title="Configuração indisponível"
      description="O contexto comercial do escritório ainda não permite alterar a política."
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

    <USkeleton v-if="loading && !loaded" class="h-56 w-full" />

    <template v-else-if="loaded">
      <UAlert
        v-if="recoveryKey"
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

      <UCard>
        <template #header>
          <div class="space-y-1">
            <h2 class="font-semibold text-highlighted">
              Política de inadimplência
            </h2>
            <p class="text-sm text-muted">
              As alterações ficam como rascunho até você salvar.
            </p>
          </div>
        </template>
        <div class="space-y-6">
          <div class="flex flex-col justify-between gap-4 rounded-lg border border-default p-4 sm:flex-row sm:items-center">
            <div class="max-w-2xl space-y-1">
              <p class="font-medium text-highlighted">
                Automatizar suspensão por atraso
              </p>
              <p class="text-sm text-muted">
                No modo automático, contratos com saldo vencido após a tolerância podem ficar suspensos. Suspensões manuais e de segurança não são removidas por esta política.
              </p>
            </div>
            <USwitch
              :model-value="settings.mode === 'automatic'"
              :disabled="!canConfigure || commandBusy || command.pending.value"
              aria-label="Automatizar suspensão por atraso"
              @update:model-value="(enabled: boolean) => { settings.mode = enabled ? 'automatic' : 'manual' }"
            />
          </div>

          <UFormField name="grace_days" label="Dias de tolerância" :error="fieldErrors.grace_days">
            <UInput
              v-model.number="settings.grace_days"
              type="number"
              min="0"
              max="65535"
              step="1"
              inputmode="numeric"
              :disabled="!canConfigure || commandBusy || command.pending.value"
              aria-describedby="grace-help"
              class="max-w-xs"
            />
            <template #help>
              <span id="grace-help">A suspensão automática considera apenas o atraso após este prazo, em dias corridos.</span>
            </template>
          </UFormField>
          <UFormField name="billing_timezone" label="Fuso horário de cobrança" :error="fieldErrors.billing_timezone">
            <UInput
              v-model="settings.billing_timezone"
              autocomplete="off"
              placeholder="America/Sao_Paulo"
              :disabled="!canConfigure || commandBusy || command.pending.value"
              class="max-w-sm"
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
              :loading="commandBusy"
              :disabled="saveDisabled"
              @click="savePolicy(false)"
            >
              Salvar configuração
            </UButton>
          </div>
        </div>
      </UCard>

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

      <UCard v-if="preview">
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
      </UCard>
    </template>

    <UModal v-model:open="confirmOpen" title="Confirmar política automática" description="A suspensão por atraso será aplicada aos contratos que atendam às condições da pré-visualização.">
      <template #body>
        <div class="space-y-3 text-sm">
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
        </div>
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
            color="primary"
            :loading="commandBusy"
            :disabled="!preview || saveDisabled"
            @click="savePolicy(true)"
          >
            Confirmar ativação automática
          </UButton>
        </div>
      </template>
    </UModal>
  </div>
</template>
