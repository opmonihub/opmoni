<script setup lang="ts">
import type { ErpReportExport, ErpReportFilters, ErpReportSnapshot } from '#shared/types/erpReports'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import { createPendingOperation, parsePendingOperation, pendingOperationLookup, type ErpPendingOperation } from '#shared/utils/erpOperationRecovery'

const props = defineProps<{ category: string, filters: ErpReportFilters, contextKey: string, canExport: boolean }>()
const { api } = useAccessApi()
const { user, currentCompany } = useAuth()
const operationName = 'erp.reports.exports.store'
const operationRoutes = { [operationName]: /^\/erp\/reports\/(sales|purchases|stock|financial|fiscal|overview)\/exports$/ }
const filterLabels = { date_from: 'De', date_to: 'Até', erp_establishment_id: 'Estabelecimento', currency: 'Moeda', status: 'Status', direction: 'Direção' }
const identity = computed(() => `${user?.value?.id ?? ''}:${currentCompany.value?.id ?? ''}`)
const storageKey = computed(() => `opmoni:erp-report-export:v1:${identity.value}`)
const operation = ref<ErpPendingOperation | null>(null)
const previousRecords = ref<ErpReportExport[]>([])
const exportRecord = ref<ErpReportExport | null>(null)
const busy = ref(false)
const error = ref('')
const ambiguous = ref(false)
const reference = ref('')
const ready = ref(false)
const intent = ref<{ category: string, filters: ErpReportFilters } | null>(null)
const statusLabels = { pending: 'Pendente', completed: 'Concluída', failed: 'Falhou' }
let generation = 0
let activeRequest: AbortController | null = null
let mounted = false

function userFilters(filters: ErpReportFilters): ErpReportFilters {
  return Object.fromEntries(Object.entries(filters).filter(([key]) => Object.hasOwn(filterLabels, key)))
}

function save(): boolean {
  try {
    sessionStorage.setItem(storageKey.value, JSON.stringify({
      version: 1, identity: identity.value, operation: operation.value, ambiguous: ambiguous.value,
      exportRecord: exportRecord.value, previousRecords: previousRecords.value
    }))
    return true
  } catch {
    error.value = 'Não foi possível preservar a identificação nesta aba. Habilite o armazenamento antes de gerar uma exportação.'
    return false
  }
}

function restore(includeMetadata = true) {
  if (!mounted || !user?.value?.id || !currentCompany.value?.id) return
  try {
    const raw = sessionStorage.getItem(storageKey.value)
    if (!raw) return
    const saved = JSON.parse(raw)
    if (saved.version !== 1 || saved.identity !== identity.value) return
    const pending = saved.operation ? parsePendingOperation(saved.operation, identity.value, operationRoutes) : null
    if (saved.operation && !pending) {
      ambiguous.value = true
      error.value = 'A identificação preservada exige conciliação. Não gere outra exportação nesta aba.'
      return
    }
    operation.value = pending
    ambiguous.value = !!saved.ambiguous
    exportRecord.value = includeMetadata ? record(saved.exportRecord) : null
    previousRecords.value = includeMetadata && Array.isArray(saved.previousRecords) ? saved.previousRecords.map(record).filter((item: ErpReportExport | null): item is ErpReportExport => !!item) : []
    reference.value = exportRecord.value ? String(exportRecord.value.id) : ''
    if (pending) intent.value = { category: pending.path.split('/')[3]!, filters: userFilters(pending.payload) }
  } catch {
    ambiguous.value = true
    error.value = 'Não foi possível ler a solicitação preservada. Não gere outra exportação antes da conciliação.'
  }
}

function record(value: unknown): ErpReportExport | null {
  if (!value || typeof value !== 'object') return null
  const item = value as ErpReportExport
  return Number.isSafeInteger(item.id) && item.id > 0 && ['pending', 'completed', 'failed'].includes(item.status) && item.filters && typeof item.report_type === 'string' ? item : null
}

function selectRecord(metadata: ErpReportExport) {
  const previous = exportRecord.value
  if (previous && previous.id !== metadata.id && !previousRecords.value.some(item => item.id === previous.id)) previousRecords.value.push(previous)
  previousRecords.value = previousRecords.value.map(item => item.id === metadata.id ? metadata : item)
  exportRecord.value = metadata
  reference.value = String(metadata.id)
}

function validReport(value: unknown, exported: ErpReportExport): value is ErpReportSnapshot {
  if (!value || typeof value !== 'object') return false
  const snapshot = value as ErpReportSnapshot
  if (snapshot.category !== exported.report_type || !snapshot.filters) return false
  if (Object.entries(exported.filters).some(([key, value]) => JSON.stringify(snapshot.filters[key as keyof ErpReportFilters]) !== JSON.stringify(value))) return false
  const required: Record<string, string[]> = {
    sales: ['document_count', 'posted_total'],
    purchases: ['document_count', 'posted_total'],
    stock: ['balance_lines', 'quantity_on_hand', 'inventory_value'],
    financial: ['obligations_amount', 'obligations_open_amount', ...(!snapshot.filters.direction && !snapshot.filters.status ? ['payments_posted_total'] : [])],
    fiscal: ['authorized_count', 'draft_count', 'rejected_count', 'queued_count', 'submitting_count', 'indeterminate_count', 'canceled_count', 'contingency_local_count']
  }
  const groups = snapshot.category === 'overview' ? snapshot.sections : { [snapshot.category]: snapshot.totals }
  const categories = snapshot.category === 'overview' ? Object.keys(required) : [snapshot.category]
  return !!groups && categories.every((category) => {
    const totals = groups[category]
    return !!totals && !!required[category] && required[category].every((metric) => {
      const value = totals[metric]
      if (category === 'stock' && metric === 'inventory_value' && value === null) return true
      return metric.endsWith('_count') || metric === 'balance_lines'
        ? typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
        : typeof value === 'string' && /^-?\d+(?:\.\d+)?$/.test(value)
    })
  })
}

function acceptFailure(failure: unknown) {
  ready.value = false
  const payload = (failure as { data?: { data?: unknown } })?.data
  const metadata = record(payload?.data)
  if (metadata) {
    selectRecord(metadata)
    save()
  }
  error.value = apiStatus(failure) === 410
    ? 'Esta exportação expirou. A referência original continua preservada. Use "Gerar nova exportação" para iniciar uma nova geração, não uma nova tentativa de baixar o arquivo expirado.'
    : apiErrorMessage(failure) ?? 'Não foi possível acessar a exportação'
}

async function createExport() {
  if (busy.value || ambiguous.value || exportRecord.value?.status === 'pending' || !props.canExport || !user?.value?.id || !currentCompany.value?.id) return
  const prior = exportRecord.value
  if (prior && !previousRecords.value.some(item => item.id === prior.id)) previousRecords.value.push(prior)
  const snapshot = { category: props.category, filters: userFilters(props.filters) }
  intent.value = snapshot
  operation.value = createPendingOperation(identity.value, operationName, `/erp/reports/${snapshot.category}/exports`, snapshot.filters as Record<string, unknown>, crypto.randomUUID())
  ambiguous.value = true
  if (!save()) {
    ambiguous.value = false
    operation.value = null
    return
  }
  const version = ++generation
  const controller = new AbortController()
  activeRequest = controller
  busy.value = true
  error.value = ''
  ready.value = false
  const frozen = operation.value
  try {
    const response = await api<{ data: ErpReportExport }>(frozen.path, { method: 'POST', body: frozen.payload, headers: { 'Idempotency-Key': frozen.key }, signal: controller.signal })
    if (version !== generation) return
    const metadata = record(response.data)
    if (!metadata) {
      ambiguous.value = true
      throw new Error('A resposta não confirmou a referência da exportação. Consulte a referência antes de solicitar outra.')
    }
    selectRecord(metadata)
    ambiguous.value = false
    save()
    if (metadata.status === 'failed') error.value = 'A geração falhou. Consulte os detalhes da exportação.'
  } catch (failure) {
    if (version !== generation) return
    const metadata = record((failure as { data?: { data?: unknown } })?.data?.data)
    acceptFailure(failure)
    const status = apiStatus(failure)
    ambiguous.value = !metadata && (status === null || status >= 500 || status === 408 || status === 409)
    save()
  } finally {
    if (activeRequest === controller) activeRequest = null
    if (version === generation) busy.value = false
  }
}

async function recover() {
  const pending = operation.value
  if (!pending || pending.identity !== identity.value || busy.value || !ambiguous.value) return
  const version = ++generation
  const controller = new AbortController()
  activeRequest = controller
  busy.value = true
  error.value = ''
  try {
    const lookup = pendingOperationLookup(pending)
    const result = await api<{ data: { state: string, result: unknown } }>(lookup.path, { headers: lookup.headers, signal: controller.signal })
    if (version !== generation) return
    const metadata = record(result.data?.result)
    if (result.data?.state !== 'completed' || !metadata || metadata.report_type !== pending.path.split('/')[3]) throw new Error('A solicitação ainda não foi conciliada.')
    selectRecord(metadata)
    ambiguous.value = false
    ready.value = false
    save()
  } catch (failure) {
    if (version !== generation) return
    error.value = apiStatus(failure) === 404
      ? 'Operação ainda não registrada. A solicitação e sua chave continuam preservadas; consulte novamente sem gerar outra.'
      : apiErrorMessage(failure) ?? 'Não foi possível conciliar a solicitação preservada.'
  } finally {
    if (activeRequest === controller) activeRequest = null
    if (version === generation) busy.value = false
  }
}

async function inspect(downloadFile = false) {
  if (busy.value || !/^[1-9]\d*$/.test(reference.value)) return
  const version = ++generation
  const id = Number(reference.value)
  if (!Number.isSafeInteger(id)) return
  const controller = new AbortController()
  activeRequest = controller
  busy.value = true
  error.value = ''
  ready.value = false
  try {
    const response = await api<{ data: { export: ErpReportExport, report: ErpReportSnapshot } }>(`/erp/reports/exports/${id}`, { signal: controller.signal })
    if (version !== generation) return
    const metadata = record(response.data?.export)
    if (!metadata || metadata.id !== id) throw new Error('Resposta da exportação inválida')
    selectRecord(metadata)
    save()
    if (metadata.status !== 'completed' || !metadata.expires_at || Date.parse(metadata.expires_at) <= Date.now() || !Number.isFinite(Date.parse(metadata.expires_at)) || !validReport(response.data.report, metadata)) throw new Error('Exportação incompleta, expirada ou indisponível. Consulte novamente.')
    ready.value = true
    if (downloadFile) {
      const url = URL.createObjectURL(new Blob([JSON.stringify(response.data.report, null, 2)], { type: 'application/json' }))
      try {
        const link = document.createElement('a')
        link.href = url
        link.download = `relatorio-${metadata.report_type}-${metadata.id}.json`
        link.click()
      } finally { URL.revokeObjectURL(url) }
    }
  } catch (failure) {
    if (version === generation) acceptFailure(failure)
  } finally {
    if (activeRequest === controller) activeRequest = null
    if (version === generation) busy.value = false
  }
}

async function download() {
  if (exportRecord.value?.status !== 'completed') return
  reference.value = String(exportRecord.value.id)
  await inspect(true)
}

watch([() => props.contextKey, identity], ([, nextIdentity], [, previousIdentity]) => {
  activeRequest?.abort()
  activeRequest = null
  generation++
  exportRecord.value = null
  intent.value = null
  ready.value = false
  reference.value = ''
  error.value = ''
  ambiguous.value = false
  busy.value = false
  operation.value = null
  previousRecords.value = []
  restore(nextIdentity !== previousIdentity)
}, { flush: 'sync' })
onMounted(() => {
  mounted = true
  restore()
})
onScopeDispose(() => {
  activeRequest?.abort()
  activeRequest = null
  generation++
})
</script>

<template>
  <section class="border-muted space-y-3 border-t pt-4" aria-label="Exportação privada">
    <h2 class="text-highlighted font-semibold">
      Exportação privada
    </h2>
    <p class="text-muted text-sm">
      O arquivo JSON preserva os filtros e os valores confirmados do relatório. O acesso é verificado em cada consulta e download.
    </p>
    <UButton
      v-if="canExport"
      :label="exportRecord ? 'Gerar nova exportação' : 'Gerar exportação'"
      icon="i-lucide-file-down"
      :loading="busy"
      :disabled="busy || ambiguous || exportRecord?.status === 'pending'"
      @click="createExport"
    />
    <p v-else class="text-muted text-sm">
      Seu acesso permite consultar exportações próprias já existentes. A geração exige permissão de exportação e acesso direto com edição.
    </p>
    <UAlert
      v-if="ambiguous"
      color="warning"
      title="Resultado da solicitação ainda não confirmado"
      description="A solicitação e sua chave estão preservadas nesta aba, inclusive após recarga. Consulte a operação sem repetir a geração. Uma referência manual não concilia esta solicitação."
    />
    <UButton
      v-if="ambiguous && operation"
      label="Consultar solicitação preservada"
      variant="outline"
      :disabled="busy"
      @click="recover"
    />
    <UAlert v-if="error" color="error" :title="error" />
    <div v-if="intent && (!exportRecord || ambiguous)" class="text-muted space-y-1 text-sm">
      <p>Solicitação preservada: {{ intent.category }}</p>
      <p>
        De {{ intent.filters.date_from }} até {{ intent.filters.date_to }} · Estabelecimento {{ intent.filters.erp_establishment_id ?? 'Todos' }}<template v-if="intent.filters.currency">
          · {{ intent.filters.currency }}
        </template><template v-if="intent.filters.status">
          · {{ intent.filters.status }}
        </template><template v-if="intent.filters.direction">
          · {{ intent.filters.direction }}
        </template>
      </p>
    </div>
    <div v-if="exportRecord" class="space-y-2 text-sm">
      <p>Exportação #{{ exportRecord.id }} · {{ statusLabels[exportRecord.status] }} · {{ exportRecord.report_type }}</p>
      <p v-if="exportRecord.expires_at" class="text-muted">
        Expira em: {{ exportRecord.expires_at }}
      </p>
      <p v-if="exportRecord.failure_code" class="text-muted">
        Motivo: {{ exportRecord.failure_code }}
      </p>
      <dl class="flex flex-wrap gap-x-5 gap-y-2">
        <div v-for="(value, key) in userFilters(exportRecord.filters)" :key="key">
          <dt class="inline text-muted">
            {{ filterLabels[key as keyof typeof filterLabels] }}:
          </dt><dd class="inline">
            {{ value ?? 'Todos' }}
          </dd>
        </div>
      </dl>
      <UButton
        v-if="exportRecord.status === 'completed'"
        label="Verificar e baixar JSON"
        variant="soft"
        icon="i-lucide-download"
        :disabled="busy"
        :loading="busy"
        @click="download"
      />
      <p v-if="ready" class="text-muted" role="status">
        Arquivo completo verificado. O download revalida o acesso.
      </p>
    </div>
    <div v-if="previousRecords.length" class="space-y-2 text-sm" aria-label="Referências anteriores preservadas">
      <p class="text-muted">
        Nova geração usa outra chave e não repete nem corrige o arquivo anterior.
      </p>
      <div v-for="prior in previousRecords" :key="prior.id" class="flex flex-wrap items-center gap-2">
        <span>Exportação anterior #{{ prior.id }} · {{ statusLabels[prior.status] }} · {{ prior.failure_code ?? prior.report_type }}</span>
        <UButton
          label="Consultar referência anterior"
          size="sm"
          variant="ghost"
          :disabled="busy"
          @click="reference = String(prior.id); inspect()"
        />
      </div>
    </div>
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="inspect()">
      <UFormField label="Referência da exportação" name="export_reference">
        <UInput
          v-model="reference"
          inputmode="numeric"
          pattern="[1-9][0-9]*"
          placeholder="Número da exportação"
          required
        />
      </UFormField>
      <UButton
        type="submit"
        label="Consultar estado"
        variant="outline"
        :loading="busy"
        :disabled="busy"
      />
    </form>
  </section>
</template>
