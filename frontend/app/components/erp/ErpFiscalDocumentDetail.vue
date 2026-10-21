<script setup lang="ts">
import type { ErpFiscalDocument } from '#shared/types/erpFiscal'

const props = defineProps<{
  document: ErpFiscalDocument | null
  canTransmit: boolean
  canConsult: boolean
  canQueue: boolean
  canEnableContingency: boolean
  canReconcile: boolean
  canDownloadXml: boolean
  canDownloadPdf: boolean
  busy?: boolean
  artifactError?: string
  releaseMessage?: string
}>()
const emit = defineEmits<{
  refresh: []
  queue: [document: ErpFiscalDocument]
  contingency: [document: ErpFiscalDocument]
  transmit: [document: ErpFiscalDocument]
  consult: [document: ErpFiscalDocument]
  reconcile: [document: ErpFiscalDocument]
  artifact: [document: ErpFiscalDocument, format: 'xml' | 'pdf']
}>()
const statusLabels: Record<string, string> = {
  draft: 'Rascunho', queued: 'Na fila local', pending: 'Pendente', submitting: 'Envio em andamento', indeterminate: 'Resultado incerto',
  authorized: 'Autorizado', rejected: 'Rejeitado', contingency_local: 'Contingência local', attention_required: 'Requer atenção', artifact_pending: 'Artefato pendente', closed: 'Encerrado', canceled: 'Cancelado'
}
const actionLabels: Record<string, string> = { 'document:draft': 'Rascunho', 'document:queue': 'Fila local', 'document:transmit': 'Transmissão', 'document:consult': 'Consulta', 'document:contingency': 'Contingência', 'document:reconcile': 'Reconciliação', 'event:submit': 'Evento fiscal', 'invalidation:submit': 'Inutilização' }
const attemptLabels: Record<string, string> = { accepted: 'Aceita pelo sistema', pending: 'Aguardando processamento', submitting: 'Em processamento', indeterminate: 'Resposta não confirmada', rejected: 'Rejeitada', attention_required: 'Requer revisão', artifact_pending: 'Artefato pendente', superseded: 'Substituída' }
const protocol = (attempt: NonNullable<ErpFiscalDocument['attempt']>) => typeof attempt.safe_result?.protocol === 'string' ? attempt.safe_result.protocol : null
const safeReason = (attempt: NonNullable<ErpFiscalDocument['attempt']>) => {
  const reason = attempt.safe_result?.reason ?? attempt.safe_result?.message
  return typeof reason === 'string' ? reason : null
}
const canShowXml = computed(() => !!props.document && (props.document.artifact_ready || (props.document.model === '65' && props.document.status === 'contingency_local')))
</script>

<template>
  <section v-if="document" class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-sm text-muted">
          {{ document.model }} · {{ document.environment === 'production' ? 'Produção' : 'Homologação' }} · Série {{ document.series ?? '—' }} · Número {{ document.number ?? '—' }}
        </p><h2 class="mt-1 text-xl font-semibold">
          Documento fiscal #{{ document.id }}
        </h2><p v-if="document.access_key" class="mt-1 break-all font-mono text-xs text-muted">
          {{ document.access_key }}
        </p>
      </div>
      <UBadge :color="document.status === 'authorized' ? 'success' : ['rejected', 'attention_required'].includes(document.status) ? 'error' : 'warning'" variant="subtle">
        {{ statusLabels[document.status] ?? 'Estado não reconhecido pela interface' }}
      </UBadge>
    </header>
    <div v-if="document.contingency" class="rounded-lg border border-warning bg-warning/5 p-4 text-sm">
      <p class="font-medium">
        Contingência local registrada
      </p><p class="mt-1 text-muted">
        O XML local não representa autorização do provedor. A reconciliação só estará disponível quando os portões do serviço fiscal permitirem.
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <UButton
        v-if="['draft', 'rejected'].includes(document.status) && canQueue"
        variant="outline"
        :disabled="busy"
        @click="emit('queue', document)"
      >
        Colocar na fila local
      </UButton>
      <UButton
        v-if="['queued', 'indeterminate', 'submitting', 'contingency_local'].includes(document.status) && canTransmit"
        color="primary"
        :disabled="busy"
        @click="emit('transmit', document)"
      >
        Transmitir documento
      </UButton>
      <UButton
        v-if="['authorized', 'indeterminate', 'submitting'].includes(document.status) && canConsult"
        variant="outline"
        :disabled="busy"
        @click="emit('consult', document)"
      >
        Consultar provedor
      </UButton>
      <UButton
        v-if="document.model === '65' && ['draft', 'queued'].includes(document.status) && canEnableContingency"
        variant="outline"
        :disabled="busy"
        @click="emit('contingency', document)"
      >
        Registrar contingência local NFC-e
      </UButton>
      <UButton
        v-if="document.status === 'contingency_local' && canReconcile"
        variant="outline"
        :disabled="busy"
        @click="emit('reconcile', document)"
      >
        Reconciliar contingência
      </UButton>
      <UButton variant="ghost" :disabled="busy" @click="emit('refresh')">
        Atualizar detalhes
      </UButton>
      <UButton
        v-if="canShowXml && canDownloadXml"
        variant="outline"
        :disabled="busy"
        @click="emit('artifact', document, 'xml')"
      >
        Baixar XML verificado
      </UButton>
      <UButton
        v-if="document.artifact_ready && canDownloadPdf"
        variant="outline"
        :disabled="busy"
        @click="emit('artifact', document, 'pdf')"
      >
        Solicitar PDF
      </UButton>
    </div>
    <UAlert
      v-if="releaseMessage"
      color="warning"
      variant="subtle"
      title="Transmissão bloqueada"
      :description="releaseMessage"
    />
    <UAlert
      v-if="artifactError"
      color="error"
      variant="subtle"
      title="Download indisponível"
      :description="artifactError"
    />
    <div v-if="!document.artifact_ready" class="rounded-lg border border-default p-4 text-sm text-muted">
      {{ document.model === '65' && document.status === 'contingency_local' ? 'O XML local de contingência será disponibilizado após verificação do artefato.' : 'Não há artefato fiscal confirmado para download.' }} PDF depende de um renderizador disponível.
    </div>
    <section class="space-y-3">
      <div class="flex items-center justify-between">
        <h3 class="font-semibold">
          Histórico seguro de tentativas
        </h3><span class="text-xs text-muted">{{ document.attempts?.length ?? (document.attempt ? 1 : 0) }} registro(s)</span>
      </div>
      <ol v-if="document.attempts?.length || document.attempt" class="space-y-3">
        <li v-for="attempt in (document.attempts?.length ? document.attempts : document.attempt ? [document.attempt] : [])" :key="attempt.id" class="rounded-lg border border-default p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium">{{ actionLabels[attempt.action] ?? 'Operação fiscal' }}</span><UBadge variant="subtle" :color="['rejected', 'attention_required'].includes(attempt.status) ? 'error' : 'neutral'">
              {{ attemptLabels[attempt.status] ?? 'Estado não reconhecido' }}
            </UBadge>
          </div>
          <p class="mt-1 text-xs text-muted">
            Tentativa #{{ attempt.id }} · {{ attempt.model ?? document.model }}<template v-if="attempt.series !== undefined">
              · Série {{ attempt.series }}
            </template><template v-if="attempt.number !== undefined">
              · Número {{ attempt.number }}
            </template>
          </p>
          <p v-if="protocol(attempt)" class="mt-2 text-sm">
            Protocolo: <span class="font-mono">{{ protocol(attempt) }}</span>
          </p>
          <p v-if="safeReason(attempt)" class="mt-2 text-sm text-muted">
            {{ safeReason(attempt) }}
          </p>
        </li>
      </ol>
      <p v-else class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-muted">
        Nenhuma tentativa fiscal registrada.
      </p>
    </section>
    <UAlert
      v-if="document.legacy_attention_required"
      color="warning"
      variant="subtle"
      title="Reconciliação necessária"
      description="Este registro legado precisa de revisão antes de novas operações fiscais."
    />
  </section>
  <div v-else class="rounded-lg border border-dashed border-default p-8 text-center text-sm text-muted">
    Selecione um documento fiscal para consultar detalhes, estado e tentativas.
  </div>
</template>
