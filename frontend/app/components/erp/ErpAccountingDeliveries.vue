<script setup lang="ts">
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'

type FiscalSource = { id: number, model: string, access_key: string | null, status: string, erp_establishment_id: number }
type AccountingDelivery = {
  id: number
  erp_fiscal_document_id: number
  account_id: number
  client_id: number | null
  fiscal_document_id: number | null
  delegation_id: number | null
  mapping_id: number | null
  mapping_revision: number | null
  can_retry: boolean
  status: 'pending' | 'delivered' | 'failed' | 'blocked' | string
  reason_code: string | null
  attempts: number
  delivered_at: string | null
  erp_fiscal_document: { id: number, model: string, erp_establishment_id: number, access_key: string | null, status: string } | null
  approved_mapping?: { id: number, revision: number, erp_establishment_id: number, status: string } | null
}
type Page<T> = { data: T[], meta: { current_page: number, last_page: number, per_page: number, total: number } }
type PendingRetry = { actorId: number, companyId: number, deliveryId: number, key: string, path: string, body: Record<string, never> }

const { user, currentCompany, currentAccount } = useAuth()
const { $api } = useNuxtApp()
const context = useErpAdministrationContext()
const rows = ref<AccountingDelivery[]>([])
const total = ref(0)
const page = ref(1)
const lastPage = ref(1)
const statusFilter = ref('all')
const selectedId = ref<number | null>(null)
const selectedDetail = ref<AccountingDelivery | null>(null)
const authorizedSources = ref<FiscalSource[]>([])
const selectedSourceId = ref(0)
const loading = ref(false)
const sourceLoading = ref(false)
const detailLoading = ref(false)
const retrying = ref(false)
const recovering = ref(false)
const loadState = ref<'initial' | 'ready' | 'failed'>('initial')
const stale = ref(false)
const errorMessage = ref('')
const sourceError = ref('')
const operationMessage = ref('')
const pendingRetry = ref<PendingRetry | null>(null)
let listGeneration = 0
let detailGeneration = 0
let sourceGeneration = 0
let actionGeneration = 0

const canRead = computed(() => erpCompanyHasPermission(currentCompany.value?.permissions, 'erp.accounting-deliveries.index'))
const canShowDetail = computed(() => erpCompanyHasPermission(currentCompany.value?.permissions, 'erp.accounting-deliveries.show'))
const canRetry = computed(() => {
  const company = currentCompany.value
  if (!erpCompanyHasPermission(company?.permissions, 'erp.accounting-deliveries.retry')) return false
  if (company?.access_kind === 'direct') return company.role !== 'viewer'
  return company?.access_kind === 'delegated'
    && currentAccount.value?.status === 'active'
    && currentAccount.value.id === company.delegated_office_id
    && ['admin', 'operador'].includes(currentAccount.value.role)
})
const canOpenOfficeApproval = computed(() => currentAccount.value?.status === 'active' && currentAccount.value.role === 'admin')
const canRepeatOriginal = computed(() => canRetry.value && pendingRetry.value !== null)
const canRequest = computed(() => currentCompany.value?.access_kind === 'direct'
  && currentCompany.value.role === 'owner'
  && erpCompanyHasPermission(currentCompany.value.permissions, 'erp.accounting-deliveries.store'))
const statusItems = [
  { label: 'Todos os estados', value: 'all' },
  { label: 'Aguardando', value: 'pending' },
  { label: 'Entregues', value: 'delivered' },
  { label: 'Com falha', value: 'failed' },
  { label: 'Bloqueadas', value: 'blocked' }
]

function api<T>(path: string, options?: Record<string, unknown>): Promise<T> {
  return $api<T>(path, options as never)
}

function currentToken() {
  return context.capture()
}

function isCurrent(token: ReturnType<typeof currentToken>, generation: number, currentGeneration: number): boolean {
  return context.isCurrent(token) && generation === currentGeneration
}

function storageKey(): string | null {
  const actorId = user.value?.id
  const companyId = currentCompany.value?.id
  return actorId && companyId ? `erp-accounting-retry:${actorId}:${companyId}` : null
}

function restorePendingRetry() {
  const key = storageKey()
  if (!key || typeof sessionStorage === 'undefined') {
    pendingRetry.value = null
    return
  }
  try {
    const value = sessionStorage.getItem(key)
    const pending = value ? JSON.parse(value) as PendingRetry : null
    pendingRetry.value = pending
      && pending.actorId === user.value?.id
      && pending.companyId === currentCompany.value?.id
      && pending.path === `/erp/accounting-deliveries/${pending.deliveryId}/retry`
      && pending.body && typeof pending.body === 'object' && Object.keys(pending.body).length === 0
      ? pending
      : null
  } catch {
    pendingRetry.value = null
  }
}

function persistPendingRetry(pending: PendingRetry | null) {
  pendingRetry.value = pending
  const key = storageKey()
  if (!key || typeof sessionStorage === 'undefined') return
  if (pending) sessionStorage.setItem(key, JSON.stringify(pending))
  else sessionStorage.removeItem(key)
}

function clearPrivateState() {
  rows.value = []
  total.value = 0
  page.value = 1
  lastPage.value = 1
  selectedId.value = null
  selectedDetail.value = null
  authorizedSources.value = []
  selectedSourceId.value = 0
  stale.value = false
  loadState.value = 'initial'
  errorMessage.value = ''
  sourceError.value = ''
  operationMessage.value = ''
  loading.value = false
  sourceLoading.value = false
  detailLoading.value = false
  retrying.value = false
  recovering.value = false
  listGeneration++
  detailGeneration++
  sourceGeneration++
  actionGeneration++
}

async function load(pageNumber = page.value) {
  if (!canRead.value || !currentCompany.value) {
    clearPrivateState()
    loading.value = false
    return
  }
  const token = currentToken()
  const generation = ++listGeneration
  const query = new URLSearchParams({ page: String(pageNumber), per_page: '15' })
  if (statusFilter.value !== 'all') query.set('status', statusFilter.value)
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await api<Page<AccountingDelivery>>(`/erp/accounting-deliveries?${query}`)
    if (!isCurrent(token, generation, listGeneration)) return
    if (!Array.isArray(response.data) || !response.meta) throw new Error('Resposta de entregas inválida.')
    rows.value = response.data
    total.value = response.meta.total
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
    loadState.value = 'ready'
    stale.value = false
    if (selectedId.value && !rows.value.some(row => row.id === selectedId.value)) {
      selectedId.value = null
      selectedDetail.value = null
    }
  } catch (error) {
    if (!isCurrent(token, generation, listGeneration)) return
    const status = apiStatus(error)
    if ([401, 403, 404, 409].includes(status ?? 0)) {
      clearPrivateState()
      errorMessage.value = apiErrorMessage(error) ?? 'O acesso à empresa mudou. Atualize o contexto para continuar.'
      loadState.value = 'failed'
    } else {
      stale.value = rows.value.length > 0
      loadState.value = rows.value.length > 0 ? 'ready' : 'failed'
      errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível carregar as entregas.'
    }
  } finally {
    if (isCurrent(token, generation, listGeneration)) loading.value = false
  }
}

async function loadSources() {
  if (!canRequest.value) return
  const token = currentToken()
  const generation = ++sourceGeneration
  sourceLoading.value = true
  sourceError.value = ''
  try {
    const response = await api<Page<FiscalSource>>('/erp/fiscal/documents?status=authorized&per_page=100')
    if (!isCurrent(token, generation, sourceGeneration)) return
    authorizedSources.value = response.data.filter(row => row.status === 'authorized' && !!row.access_key)
    if (!authorizedSources.value.some(row => row.id === selectedSourceId.value)) selectedSourceId.value = 0
  } catch (error) {
    if (!isCurrent(token, generation, sourceGeneration)) return
    if ([401, 403, 404, 409].includes(apiStatus(error) ?? 0)) {
      authorizedSources.value = []
      selectedSourceId.value = 0
    }
    sourceError.value = apiErrorMessage(error) ?? 'Não foi possível carregar documentos autorizados.'
  } finally {
    if (isCurrent(token, generation, sourceGeneration)) sourceLoading.value = false
  }
}

async function selectDelivery(id: number) {
  if (!canShowDetail.value) return
  actionGeneration++
  retrying.value = false
  recovering.value = false
  operationMessage.value = ''
  const token = currentToken()
  const generation = ++detailGeneration
  selectedId.value = id
  selectedDetail.value = null
  detailLoading.value = true
  try {
    const response = await api<{ data: AccountingDelivery }>(`/erp/accounting-deliveries/${id}`)
    if (!isCurrent(token, generation, detailGeneration) || selectedId.value !== id) return
    selectedDetail.value = response.data
  } catch (error) {
    if (!isCurrent(token, generation, detailGeneration) || selectedId.value !== id) return
    if ([401, 403, 404, 409].includes(apiStatus(error) ?? 0)) {
      selectedId.value = null
      selectedDetail.value = null
    }
    operationMessage.value = apiErrorMessage(error) ?? 'Não foi possível atualizar os detalhes.'
  } finally {
    if (isCurrent(token, generation, detailGeneration) && selectedId.value === id) detailLoading.value = false
  }
}

async function refreshDetail(id: number, token: ReturnType<typeof currentToken>, generation: number) {
  const response = await api<{ data: AccountingDelivery }>(`/erp/accounting-deliveries/${id}`)
  if (!context.isCurrent(token) || generation !== actionGeneration || selectedId.value !== id) return false
  selectedDetail.value = response.data
  rows.value = rows.value.map(row => row.id === id ? { ...row, ...response.data } : row)
  return true
}

async function retryDelivery(id: number, replayOriginal = false) {
  if (!canRetry.value || retrying.value || recovering.value || (pendingRetry.value && pendingRetry.value.deliveryId !== id)) return
  const row = rows.value.find(item => item.id === id)
  const persistedReplay = replayOriginal && pendingRetry.value?.deliveryId === id
  if (!row?.can_retry && !persistedReplay) return
  const token = currentToken()
  const generation = ++actionGeneration
  const pending = pendingRetry.value?.deliveryId === id
    ? pendingRetry.value
    : {
        actorId: user.value!.id,
        companyId: currentCompany.value!.id,
        deliveryId: id,
        key: crypto.randomUUID(),
        path: `/erp/accounting-deliveries/${id}/retry`,
        body: {} as Record<string, never>
      }
  persistPendingRetry(pending)
  retrying.value = true
  operationMessage.value = ''
  try {
    await api(pending.path, { method: 'POST', headers: { 'Idempotency-Key': pending.key, 'Content-Type': 'application/json' }, body: pending.body })
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    if (selectedId.value !== id) selectedId.value = id
    await refreshDetail(id, token, generation)
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    persistPendingRetry(null)
    operationMessage.value = 'A solicitação original foi confirmada. O status atual foi atualizado.'
    await load(page.value)
  } catch (error) {
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    if ([401, 403, 404, 409].includes(apiStatus(error) ?? 0)) clearPrivateState()
    operationMessage.value = apiErrorMessage(error) ?? 'Não foi possível confirmar a solicitação original. Consulte o resultado antes de agir novamente.'
  } finally {
    if (context.isCurrent(token) && generation === actionGeneration) retrying.value = false
  }
}

async function recoverRetry() {
  const pending = pendingRetry.value
  if (!pending || recovering.value || retrying.value || pending.actorId !== user.value?.id || pending.companyId !== currentCompany.value?.id) return
  const token = currentToken()
  const generation = ++actionGeneration
  selectedId.value = pending.deliveryId
  recovering.value = true
  operationMessage.value = ''
  try {
    const query = new URLSearchParams({ operation: 'erp.accounting-deliveries.retry' })
    const response = await api<{ data: { state: string, result?: AccountingDelivery } }>(`/erp/operations/lookup?${query}`, {
      headers: { 'Idempotency-Key': pending.key }
    })
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    if (response.data.state !== 'completed') throw new Error('A solicitação original ainda não tem confirmação registrada.')
    if (selectedId.value !== pending.deliveryId) selectedId.value = pending.deliveryId
    await refreshDetail(pending.deliveryId, token, generation)
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    persistPendingRetry(null)
    operationMessage.value = 'Resultado original localizado. Os detalhes foram consultados novamente.'
    await load(page.value)
  } catch (error) {
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    if (apiStatus(error) === 404) {
      operationMessage.value = 'Ainda não há recibo visível. A chave original foi preservada; atualize a entrega para conferir o estado atual.'
    } else {
      if ([401, 403, 409].includes(apiStatus(error) ?? 0)) clearPrivateState()
      operationMessage.value = apiErrorMessage(error) ?? 'Não foi possível consultar o resultado original.'
    }
  } finally {
    if (context.isCurrent(token) && generation === actionGeneration) recovering.value = false
  }
}

async function requestDelivery() {
  const source = authorizedSources.value.find(row => row.id === selectedSourceId.value)
  if (!canRequest.value || !source || retrying.value || recovering.value) return
  const token = currentToken()
  const generation = ++actionGeneration
  retrying.value = true
  operationMessage.value = ''
  try {
    await api('/erp/accounting-deliveries', { method: 'POST', body: { erp_fiscal_document_id: source.id } })
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    selectedSourceId.value = 0
    await load(1)
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    await loadSources()
    if (!context.isCurrent(token) || generation !== actionGeneration) return
    operationMessage.value = 'A solicitação de entrega foi registrada.'
  } catch (error) {
    if (context.isCurrent(token) && generation === actionGeneration) {
      if ([401, 403, 404, 409].includes(apiStatus(error) ?? 0)) clearPrivateState()
      operationMessage.value = apiErrorMessage(error) ?? 'Não foi possível registrar a entrega.'
    }
  } finally {
    if (context.isCurrent(token) && generation === actionGeneration) retrying.value = false
  }
}

function reasonLabel(reason: string | null): string {
  const labels: Record<string, string> = {
    client_mapping_missing: 'O escritório ainda precisa aprovar o estabelecimento e o cliente.',
    mapping_pending_approval: 'O mapeamento do escritório aguarda aprovação.',
    fiscal_document_not_authorized: 'O documento fiscal de origem ainda não está autorizado.',
    company_inactive: 'A empresa está inativa.',
    office_inactive: 'O escritório está inativo.',
    intent_delegation_unverified: 'A delegação vinculada à entrega não foi confirmada.',
    delegation_revoked: 'A delegação foi revogada.',
    mapping_revoked: 'O mapeamento aprovado foi revogado ou substituído.',
    client_inactive: 'O cliente vinculado está inativo.',
    establishment_inactive: 'O estabelecimento está inativo.',
    max_attempts_exceeded: 'O limite de tentativas foi atingido.',
    artifact_incomplete: 'O artefato fiscal está incompleto.',
    storage_unavailable: 'O artefato fiscal está temporariamente indisponível.',
    artifact_integrity_failed: 'A integridade do artefato fiscal não foi confirmada.',
    accounting_worker_not_configured: 'O processamento local ainda não está disponível.',
    publication_failed: 'A publicação no histórico do escritório falhou.',
    artifact_conflict: 'Já existe um documento com conteúdo divergente.'
  }
  return reason ? labels[reason] ?? 'A entrega está bloqueada por uma condição operacional.' : 'Sem bloqueio registrado.'
}

function statusLabel(status: string): string {
  return ({ pending: 'Aguardando', delivered: 'Entregue', failed: 'Com falha', blocked: 'Bloqueada' } as Record<string, string>)[status] ?? status
}

watch(() => context.key.value, () => {
  clearPrivateState()
  restorePendingRetry()
  if (canRead.value) void load(1)
  if (canRequest.value) void loadSources()
}, { immediate: true, flush: 'sync' })

watch([statusFilter], () => {
  page.value = 1
  void load(1)
}, { flush: 'sync' })

onScopeDispose(() => {
  clearPrivateState()
})
</script>

<template>
  <div class="flex w-full flex-col gap-5">
    <UAlert
      v-if="!canRead"
      color="warning"
      variant="subtle"
      icon="i-lucide-shield-alert"
      title="Sem permissão para consultar entregas"
      description="Peça ao responsável pela empresa acesso de leitura a este histórico."
    />

    <template v-else>
      <section v-if="canRequest" class="flex flex-col gap-3 rounded-lg border border-default p-4 sm:flex-row sm:items-end">
        <UFormField class="min-w-0 flex-1" label="Documento fiscal autorizado" name="accounting_source">
          <USelect
            v-model="selectedSourceId"
            :items="[{ label: 'Selecione um documento autorizado', value: 0 }, ...authorizedSources.map(source => ({ label: `Modelo ${source.model} · documento ${source.id} · ${source.access_key ?? 'chave indisponível'}`, value: source.id }))]"
            :loading="sourceLoading"
            :disabled="sourceLoading || authorizedSources.length === 0 || retrying"
            class="w-full"
          />
        </UFormField>
        <UButton
          label="Solicitar entrega"
          icon="i-lucide-send"
          :disabled="!selectedSourceId || retrying"
          :loading="retrying"
          @click="requestDelivery"
        />
      </section>
      <p v-if="sourceError" role="alert" class="text-sm text-error">
        {{ sourceError }}
      </p>

      <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <UFormField label="Estado" name="delivery_status">
          <USelect v-model="statusFilter" :items="statusItems" class="w-full sm:w-56" />
        </UFormField>
        <UButton
          label="Atualizar"
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="outline"
          :loading="loading"
          @click="load(page)"
        />
      </div>

      <UAlert
        v-if="errorMessage"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        :title="errorMessage"
      />
      <UAlert
        v-if="stale"
        color="warning"
        variant="subtle"
        icon="i-lucide-clock-3"
        title="Exibindo o último resultado confirmado"
        description="A atualização falhou. Os dados anteriores foram mantidos até uma nova consulta."
        :actions="[{ label: 'Tentar novamente', onClick: () => load(page) }]"
      />
      <UAlert
        v-if="pendingRetry"
        color="warning"
        variant="subtle"
        icon="i-lucide-shield-question"
        title="Existe uma solicitação original sem confirmação local"
        description="Consulte o recibo usando a mesma chave antes de iniciar outra operação."
      >
        <template #actions>
          <UButton
            label="Consultar resultado original"
            color="neutral"
            variant="outline"
            :loading="recovering"
            @click="recoverRetry"
          />
          <UButton
            v-if="canRepeatOriginal"
            label="Repetir a solicitação original"
            color="neutral"
            variant="outline"
            :loading="retrying"
            @click="retryDelivery(pendingRetry!.deliveryId, true)"
          />
        </template>
      </UAlert>
      <p v-if="operationMessage" role="status" class="text-sm text-muted">
        {{ operationMessage }}
      </p>

      <div
        v-if="loading && loadState === 'initial'"
        class="space-y-2"
        aria-label="Carregando entregas"
        aria-live="polite"
      >
        <USkeleton v-for="item in 4" :key="item" class="h-16 w-full" />
      </div>
      <UEmpty
        v-else-if="loadState === 'failed' && rows.length === 0"
        icon="i-lucide-cloud-off"
        title="Não foi possível consultar as entregas"
        description="O histórico não foi tratado como vazio. Tente atualizar quando a conexão estiver disponível."
      >
        <template #actions>
          <UButton label="Tentar novamente" @click="load(1)" />
        </template>
      </UEmpty>
      <UEmpty
        v-else-if="loadState === 'ready' && rows.length === 0"
        icon="i-lucide-send"
        title="Nenhuma entrega encontrada"
        description="Entregas aparecem após uma solicitação autorizada e a confirmação do escritório."
      />

      <div v-else class="overflow-x-auto rounded-lg border border-default">
        <table class="w-full min-w-[44rem] text-left text-sm">
          <thead class="bg-elevated text-muted">
            <tr>
              <th scope="col" class="px-4 py-3 font-medium">
                Documento
              </th><th scope="col" class="px-4 py-3 font-medium">
                Estado
              </th><th scope="col" class="px-4 py-3 font-medium">
                Tentativas
              </th><th scope="col" class="px-4 py-3 font-medium">
                Destino registrado
              </th><th scope="col" class="px-4 py-3 font-medium">
                <span class="sr-only">Ações</span>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-default">
            <tr v-for="row in rows" :key="row.id" :class="selectedId === row.id ? 'bg-elevated' : ''">
              <td class="px-4 py-3 align-top">
                <button
                  v-if="canShowDetail"
                  type="button"
                  class="font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                  @click="selectDelivery(row.id)"
                >
                  Modelo {{ row.erp_fiscal_document?.model ?? '—' }} · documento {{ row.erp_fiscal_document_id }}
                </button>
                <span v-else class="font-medium">Modelo {{ row.erp_fiscal_document?.model ?? '—' }} · documento {{ row.erp_fiscal_document_id }}</span>
                <p class="mt-1 max-w-[24rem] break-all text-xs text-muted">
                  Chave fiscal: {{ row.erp_fiscal_document?.access_key ?? 'indisponível' }}
                </p>
              </td>
              <td class="px-4 py-3 align-top">
                <UBadge :label="statusLabel(row.status)" :color="row.status === 'delivered' ? 'success' : row.status === 'failed' ? 'error' : row.status === 'blocked' ? 'warning' : 'neutral'" variant="subtle" />
              </td>
              <td class="px-4 py-3 align-top tabular-nums">
                {{ row.attempts }}
              </td>
              <td class="px-4 py-3 align-top text-muted">
                {{ row.account_id }} · cliente {{ row.client_id ?? 'pendente' }}
              </td>
              <td class="px-4 py-3 align-top text-right">
                <UButton
                  v-if="canRetry && row.can_retry"
                  :label="row.status === 'pending' ? 'Solicitar envio' : 'Tentar novamente'"
                  size="sm"
                  color="neutral"
                  variant="outline"
                  :disabled="!!pendingRetry && pendingRetry.deliveryId !== row.id"
                  :loading="retrying && selectedId === row.id"
                  @click="retryDelivery(row.id)"
                />
                <UButton
                  v-else-if="canOpenOfficeApproval && row.status === 'blocked' && ['client_mapping_missing', 'mapping_pending_approval'].includes(row.reason_code ?? '')"
                  label="Abrir aprovação do escritório"
                  size="sm"
                  color="neutral"
                  variant="outline"
                  to="/settings/integracoes-erp"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="total > 15" class="flex items-center justify-between gap-3">
        <p class="text-sm text-muted">
          {{ total }} entregas · página {{ page }} de {{ lastPage }}
        </p>
        <div class="flex gap-2">
          <UButton
            label="Anterior"
            color="neutral"
            variant="outline"
            :disabled="page <= 1 || loading"
            @click="load(page - 1)"
          />
          <UButton
            label="Próxima"
            color="neutral"
            variant="outline"
            :disabled="page >= lastPage || loading"
            @click="load(page + 1)"
          />
        </div>
      </div>

      <section v-if="selectedId" class="flex flex-col gap-3 rounded-lg border border-default p-4" aria-live="polite">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold">
              Detalhes da entrega {{ selectedId }}
            </h2><p class="mt-1 text-sm text-muted">
              Somente identificadores e estado necessários para acompanhar a operação.
            </p>
          </div>
          <UButton
            label="Atualizar detalhes"
            color="neutral"
            variant="outline"
            :loading="detailLoading"
            @click="selectDelivery(selectedId)"
          />
        </div>
        <USkeleton v-if="detailLoading && !selectedDetail" class="h-24 w-full" />
        <template v-else-if="selectedDetail">
          <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <dt class="text-muted">
                Estado atual
              </dt><dd class="mt-1 font-medium">
                {{ statusLabel(selectedDetail.status) }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Motivo
              </dt><dd class="mt-1">
                {{ reasonLabel(selectedDetail.reason_code) }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Estabelecimento de origem
              </dt><dd class="mt-1 tabular-nums">
                {{ selectedDetail.erp_fiscal_document?.erp_establishment_id ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Mapeamento aprovado
              </dt><dd class="mt-1">
                {{ selectedDetail.mapping_id ? `#${selectedDetail.mapping_id} · revisão ${selectedDetail.mapping_revision ?? '—'}` : 'Aguardando aprovação do escritório' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Conta de destino
              </dt><dd class="mt-1 tabular-nums">
                {{ selectedDetail.account_id }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Cliente de destino
              </dt><dd class="mt-1 tabular-nums">
                {{ selectedDetail.client_id ?? 'Ainda não vinculado' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Documento no escritório
              </dt><dd class="mt-1 tabular-nums">
                {{ selectedDetail.fiscal_document_id ?? 'Ainda não publicado' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Entregue em
              </dt><dd class="mt-1">
                {{ selectedDetail.delivered_at ?? '—' }}
              </dd>
            </div>
          </dl>
          <UAlert
            v-if="selectedDetail.reason_code === 'client_mapping_missing' || selectedDetail.reason_code === 'mapping_pending_approval'"
            color="warning"
            variant="subtle"
            icon="i-lucide-building-2"
            title="A aprovação pertence ao escritório"
            description="Um administrador do escritório precisa aprovar o vínculo para este estabelecimento e cliente. A empresa não escolhe o cliente nem concede acesso."
          >
            <template #actions>
              <UButton
                v-if="canOpenOfficeApproval"
                label="Abrir configurações de integração"
                color="neutral"
                variant="outline"
                to="/settings/integracoes-erp"
              /><p v-else class="text-sm">
                Peça a um administrador do escritório que aprove o vínculo nesta empresa.
              </p>
            </template>
          </UAlert>
          <UButton
            v-if="selectedDetail.status === 'delivered'"
            label="Abrir busca fiscal do escritório"
            color="neutral"
            variant="outline"
            to="/fiscal/documentos"
          />
        </template>
      </section>
    </template>
  </div>
</template>
