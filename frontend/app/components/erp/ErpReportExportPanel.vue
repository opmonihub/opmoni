<script setup lang="ts">
import type { ErpReportExport, ErpReportFilters, ErpReportSnapshot } from '#shared/types/erpReports'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'

const props = defineProps<{ category: string, filters: ErpReportFilters, contextKey: string, canExport: boolean }>()
const { api } = useAccessApi()
const exportRecord = ref<ErpReportExport | null>(null)
const busy = ref(false)
const error = ref('')
const ambiguous = ref(false)
const reference = ref('')
const ready = ref(false)
const intent = ref<{ category: string, filters: ErpReportFilters } | null>(null)
const statusLabels = { pending: 'Pendente', completed: 'Concluída', failed: 'Falhou' }
let generation = 0

function record(value: unknown): ErpReportExport | null {
  if (!value || typeof value !== 'object') return null
  const item = value as ErpReportExport
  return Number.isSafeInteger(item.id) && item.id > 0 && ['pending', 'completed', 'failed'].includes(item.status) && item.filters && typeof item.report_type === 'string' ? item : null
}

function validReport(value: unknown, exported: ErpReportExport): value is ErpReportSnapshot {
  if (!value || typeof value !== 'object') return false
  const snapshot = value as ErpReportSnapshot
  if (snapshot.category !== exported.report_type || !snapshot.filters) return false
  if (Object.entries(exported.filters).some(([key, value]) => snapshot.filters[key as keyof ErpReportFilters] !== value)) return false
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
    exportRecord.value = metadata
    reference.value = String(metadata.id)
  }
  error.value = apiErrorMessage(failure) ?? 'Não foi possível acessar a exportação'
}

async function createExport() {
  if (busy.value || ambiguous.value || exportRecord.value?.status === 'pending' || !props.canExport) return
  const version = ++generation
  busy.value = true
  error.value = ''
  ready.value = false
  exportRecord.value = null
  intent.value = { category: props.category, filters: { ...props.filters } }
  try {
    const response = await api<{ data: ErpReportExport }>(`/erp/reports/${intent.value.category}/exports`, { method: 'POST', body: intent.value.filters })
    if (version !== generation) return
    const metadata = record(response.data)
    if (!metadata) {
      ambiguous.value = true
      throw new Error('A resposta não confirmou a referência da exportação. Consulte a referência antes de solicitar outra.')
    }
    exportRecord.value = metadata
    reference.value = String(metadata.id)
    if (metadata.status === 'failed') error.value = 'A geração falhou. Consulte os detalhes da exportação.'
  } catch (failure) {
    if (version !== generation) return
    acceptFailure(failure)
    const status = apiStatus(failure)
    if (!exportRecord.value && (status === null || status >= 500 || status === 408)) ambiguous.value = true
  } finally {
    if (version === generation) busy.value = false
  }
}

async function inspect(downloadFile = false) {
  if (busy.value || !/^[1-9]\d*$/.test(reference.value)) return
  const version = ++generation
  const id = Number(reference.value)
  if (!Number.isSafeInteger(id)) return
  busy.value = true
  error.value = ''
  ready.value = false
  try {
    const response = await api<{ data: { export: ErpReportExport, report: ErpReportSnapshot } }>(`/erp/reports/exports/${id}`)
    if (version !== generation) return
    const metadata = record(response.data?.export)
    if (!metadata || metadata.id !== id) throw new Error('Resposta da exportação inválida')
    exportRecord.value = metadata
    if (metadata.status !== 'completed' || !metadata.expires_at || Date.parse(metadata.expires_at) <= Date.now() || !Number.isFinite(Date.parse(metadata.expires_at)) || !validReport(response.data.report, metadata)) throw new Error('Exportação incompleta, expirada ou indisponível. Consulte novamente.')
    ready.value = true
    ambiguous.value = false
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
    if (version === generation) busy.value = false
  }
}

async function download() {
  if (exportRecord.value?.status !== 'completed') return
  reference.value = String(exportRecord.value.id)
  await inspect(true)
}

watch(() => props.contextKey, () => {
  generation++
  exportRecord.value = null
  intent.value = null
  ready.value = false
  reference.value = ''
  error.value = ''
  ambiguous.value = false
  busy.value = false
}, { flush: 'sync' })
onScopeDispose(() => {
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
      label="Gerar exportação"
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
      description="A conexão foi interrompida sem confirmar a geração. Não repita a solicitação. Se você recebeu uma referência, consulte-a abaixo."
    />
    <UAlert v-if="error" color="error" :title="error" />
    <div v-if="intent && !exportRecord" class="text-muted space-y-1 text-sm">
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
        <div v-for="(value, key) in exportRecord.filters" :key="key">
          <dt class="inline text-muted">
            {{ { date_from: 'De', date_to: 'Até', erp_establishment_id: 'Estabelecimento', currency: 'Moeda', status: 'Status', direction: 'Direção' }[key] ?? key }}:
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
