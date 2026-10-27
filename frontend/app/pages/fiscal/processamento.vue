<script setup lang="ts">
import { pageScrollClass, pageHeadingActionsClass } from '~/utils/pageShell'
import type { FiscalProcessingPage, FiscalProcessingRow } from '~/types/fiscal'
import { createLatestRequestRunner } from '~/utils/adminListFilters'
import { formatFiscalDateTime } from '~/utils/fiscalPresentation'

definePageMeta({ middleware: 'auth' })

const readLifecycle = useOfficeReadLifecycle()
const { processingList } = useFiscal()
const { currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const rows = ref<FiscalProcessingPage['data']>([])
const summary = ref<FiscalProcessingPage['summary'] | null>(null)
const meta = ref<FiscalProcessingPage['meta'] | null>(null)
const navigation = useListNavigationState('fiscal-processing', {
  defaults: () => ({ page: 1, state: 'all' }),
  normalize: (value) => {
    const input = value && typeof value === 'object' ? value as Record<string, unknown> : {}
    return {
      page: Number.isSafeInteger(input.page) && Number(input.page) > 0 ? Number(input.page) : 1,
      state: typeof input.state === 'string' && ['all', 'awaiting_science', 'science_queued', 'awaiting_xml', 'recovery_queued', 'deferred', 'attention'].includes(input.state) ? input.state : 'all'
    }
  }
})
const page = listNavigationField(navigation, 'page')
const stateFilter = listNavigationField(navigation, 'state')
const pending = ref(false)
const error = ref<string | null>(null)
const lastUpdated = ref<string | null>(null)
const runLatest = createLatestRequestRunner()
let controller: AbortController | undefined
let mounted = false
let disposed = false
let resetting = false

const stateLabels: Record<string, string> = {
  awaiting_science: 'Aguardando ciência', science_queued: 'Ciência enfileirada',
  awaiting_xml: 'Aguardando XML completo', recovery_queued: 'Recuperação enfileirada',
  deferred: 'Adiado', attention: 'Requer atenção', complete: 'Concluído'
}
const stateItems = [
  { label: 'Todos os estados', value: 'all' },
  ...Object.entries(stateLabels).filter(([value]) => value !== 'complete').map(([value, label]) => ({ value, label }))
]
const reasonLabels: Record<string, string> = {
  gate_disabled: 'Ciência automática desativada', delivery_unverified: 'Entrega autenticada não verificada',
  certificate_missing: 'Certificado ausente ou sem senha', certificate_expired: 'Certificado vencido',
  client_inactive: 'Cliente inativo', account_suspended: 'Conta suspensa',
  science_deadline_expired: 'Prazo de 10 dias para ciência automática encerrado',
  authorization_missing: 'Data de autorização não verificável', authorization_future: 'Data de autorização futura: verificação necessária',
  note_cancelled: 'Nota cancelada', note_denied: 'Nota denegada', self_issued: 'Nota emitida pelo próprio cliente',
  conclusive_event: 'Manifestação conclusiva já registrada', provider_transient: 'Falha temporária na consulta',
  provider_rejected: 'Consulta rejeitada pelo fisco', publication_delay: 'Aguardando publicação do XML pelo fisco',
  budget_cooldown: 'Aguardando janela de consulta', lock_occupied: 'Outra consulta está em andamento',
  attempts_exhausted: 'Limite de tentativas atingido: revisão necessária',
  storage_missing: 'Arquivo XML indisponível', storage_corrupt: 'Integridade do XML divergente',
  artifact_conflict: 'Conflito entre arquivos XML: reconciliação necessária',
  pilot_scope: 'Fora do escopo autorizado para ciência automática', ready: 'XML pronto'
}
const storageLabels: Record<string, string> = {
  missing: 'Arquivo XML indisponível', corrupt: 'Integridade do XML divergente', unverified: 'Integridade do XML ainda não verificada'
}
function processingStateLabel(row: FiscalProcessingRow) {
  if (row.stage === 'document' && storageLabels[row.storage_state ?? '']) return 'Requer atenção'
  return stateLabels[row.processing_state ?? ''] ?? 'Aguardando classificação'
}
function processingReasonLabel(row: FiscalProcessingRow) {
  if (row.processing_reason === 'artifact_conflict') return reasonLabels.artifact_conflict
  if (row.stage === 'document' && storageLabels[row.storage_state ?? '']) return storageLabels[row.storage_state ?? '']
  return reasonLabels[row.processing_reason ?? ''] ?? storageLabels[row.storage_state ?? ''] ?? 'Motivo ainda não informado'
}
function authorizationLabel(authorizedAt: string | null) {
  const timestamp = authorizedAt ? Date.parse(authorizedAt) : Number.NaN
  return Number.isFinite(timestamp) && timestamp <= Date.now() ? formatFiscalDateTime(authorizedAt) : 'não verificável'
}

async function refresh() {
  if (pending.value || disposed || !currentAccount.value?.id) return
  const accountId = currentAccount.value.id
  const session = sessionGeneration.value
  controller = new AbortController()
  const signal = controller.signal
  const isCurrent = () => !disposed && !signal.aborted && currentAccount.value?.id === accountId && sessionGeneration.value === session
  pending.value = true
  error.value = null
  await runLatest(() => processingList({ page: page.value, per_page: 25, state: stateFilter.value === 'all' ? undefined : stateFilter.value }, { signal }), {
    onSuccess(response) {
      if (!isCurrent()) return
      const lastPage = Math.max(1, response.meta.last_page)
      if (page.value > lastPage) {
        page.value = lastPage
        return
      }
      rows.value = response.data
      summary.value = response.summary
      meta.value = response.meta
      lastUpdated.value = new Date().toISOString()
    },
    onError() {
      if (isCurrent()) error.value = 'Não foi possível atualizar o processamento.'
    },
    onSettled() {
      if (isCurrent()) pending.value = false
    }
  })
}

watch([() => currentAccount.value?.id, stateFilter, page, sessionGeneration], ([accountId, state, , session], [previousAccount, previousState, , previousSession]) => {
  if (resetting) return
  controller?.abort()
  resetting = true
  if (accountId !== previousAccount || state !== previousState || session !== previousSession) page.value = 1
  resetting = false
  rows.value = []
  summary.value = null
  meta.value = null
  lastUpdated.value = null
  error.value = null
  pending.value = false
  if (mounted) void refresh()
}, { flush: 'sync' })

readLifecycle.own({
  clear() {
    controller?.abort()
    runLatest.invalidate()
    rows.value = []
    summary.value = null
    meta.value = null
    lastUpdated.value = null
    error.value = null
    pending.value = false
  },
  refresh
})

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  mounted = true
  void refresh()
  timer = setInterval(() => {
    if (document.visibilityState === 'visible') void refresh()
  }, 30_000)
})
onBeforeUnmount(() => {
  disposed = true
  controller?.abort()
  if (timer !== undefined) clearInterval(timer)
})
</script>

<template>
  <div :class="pageScrollClass">
    <UPageCard title="Processamento fiscal" variant="naked" orientation="horizontal">
      <UButton
        to="/fiscal/documentos"
        label="Documentos prontos"
        color="neutral"
        variant="outline"
        :class="pageHeadingActionsClass"
      />
    </UPageCard>

    <p class="text-sm text-muted">
      A ciência da emissão (210210) e a disponibilidade do XML não confirmam a operação.
      A manifestação conclusiva permanece sob responsabilidade do destinatário em até 90 dias da autorização pelo fisco, conforme as regras aplicáveis.
      O prazo de elegibilidade da ciência automática é de 10 dias da autorização e não substitui esse prazo conclusivo.
    </p>

    <div class="flex flex-wrap items-center gap-3">
      <USelect v-model="stateFilter" :items="stateItems" aria-label="Estado do processamento" />
      <UButton
        label="Atualizar"
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="outline"
        :loading="pending"
        @click="refresh"
      />
    </div>

    <div v-if="error" role="alert" class="space-y-2 text-sm text-error">
      <p>{{ error }}</p>
      <UButton
        label="Tentar novamente"
        color="neutral"
        variant="outline"
        :loading="pending"
        @click="refresh"
      />
    </div>

    <div v-if="summary" class="flex flex-wrap gap-3 text-sm">
      <span class="font-medium">No escritório</span>
      <span>Ativos: {{ summary.active }}</span>
      <span>Adiados: {{ summary.deferred }}</span>
      <span>Atenção: {{ summary.attention }}</span>
    </div>
    <p v-else class="text-sm text-muted">
      Contagens indisponíveis até uma consulta bem-sucedida.
    </p>
    <p v-if="lastUpdated" class="text-xs text-muted">
      Última atualização confirmada: {{ formatFiscalDateTime(lastUpdated) }}{{ error ? ' — dados podem estar desatualizados.' : '' }}
    </p>

    <div v-if="pending && !summary" role="status">
      Carregando…
    </div>
    <ul v-if="summary" class="divide-y divide-default rounded-lg border border-default" :aria-busy="pending">
      <li v-for="row in rows" :key="row.id" class="space-y-1 p-3 text-sm">
        <div class="font-medium">
          {{ row.client_name ?? 'Cliente' }}
        </div>
        <div class="break-all text-muted">
          {{ row.chave_acesso }}
        </div>
        <div>
          {{ processingStateLabel(row) }} — {{ processingReasonLabel(row) }}
        </div>
        <p>Autorização pelo fisco: {{ authorizationLabel(row.authorized_at) }}</p>
        <p>Próxima tentativa elegível: {{ row.next_attempt_at ? formatFiscalDateTime(row.next_attempt_at) : 'não informada' }}</p>
        <p v-if="row.model === 'nfe'">
          Prazo conclusivo: {{ row.conclusive_deadline_at && authorizationLabel(row.authorized_at) !== 'não verificável' ? formatFiscalDateTime(row.conclusive_deadline_at) : 'não verificável' }}
        </p>
      </li>
      <li v-if="rows.length === 0" class="p-3 text-sm text-muted">
        Nenhum item em processamento{{ stateFilter === 'all' ? '.' : ' neste filtro.' }}
      </li>
    </ul>
    <UPagination
      v-if="meta && meta.total > 0"
      v-model:page="page"
      :total="meta.total"
      :items-per-page="meta.per_page"
    />
  </div>
</template>
