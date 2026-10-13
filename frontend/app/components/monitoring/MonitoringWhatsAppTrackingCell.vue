<script setup lang="ts">
import type { MonitoringClient } from '~/types/serpro'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import {
  whatsappDispatchStatusPresentation,
  whatsappSheetGuideDownloadTarget
} from '~/utils/whatsappDispatch'

/**
 * A célula Rastreio de envio: o ícone do último disparo (abre o painel
 * Rastreio de Envio), a seta de download da guia corrente e a lupa do
 * detalhe de declaração. O que não existe fica desabilitado, nunca some —
 * a spec nomeia "inativos aparecem desabilitados" e a referência mostra os
 * três glifos sempre no lugar.
 */
const props = defineProps<{
  obligation: MonitoringObligation
  row: MonitoringClient
  /** Lupa: `monitoringObligationHasDeclarationDetail` da folha. */
  hasDeclarationDetail: boolean
}>()

const emit = defineEmits<{
  /** Ícone de status — abre o DispatchTrackingPanel. */
  'open-tracking': [row: MonitoringClient]
  /** Seta — baixa a guia corrente (endpoint PGDAS de artefato). */
  'download-guide': [row: MonitoringClient]
  /** Lupa — abre o DeclarationDetailModal. */
  'open-declaration': [row: MonitoringClient]
}>()

const summary = computed(() => props.row.whatsapp ?? null)
const presentation = computed(() =>
  summary.value ? whatsappDispatchStatusPresentation[summary.value.latest_status] : null
)

const canDownload = computed(() => whatsappSheetGuideDownloadTarget(props.row.periods) !== null)

const noDispatchTooltip = 'Nenhum disparo por WhatsApp para este cliente nesta obrigação.'
const noGuideTooltip = 'Não há guia disponível para download nesta obrigação.'
const noDetailTooltip = 'Esta obrigação não tem detalhe de declaração.'
</script>

<template>
  <div class="flex items-center gap-0.5">
    <UTooltip
      :text="presentation
        ? `Rastreio de envio — ${presentation.label} (${summary!.count} ${summary!.count === 1 ? 'disparo' : 'disparos'})`
        : noDispatchTooltip"
    >
      <UButton
        :icon="presentation?.icon ?? 'i-lucide-minus'"
        :color="presentation?.color ?? 'neutral'"
        :variant="presentation ? 'soft' : 'ghost'"
        size="xs"
        :disabled="!presentation"
        :aria-label="`Rastreio de envio de ${row.name}`"
        @click="emit('open-tracking', row)"
      />
    </UTooltip>

    <UTooltip :text="canDownload ? 'Baixar a guia corrente' : noGuideTooltip">
      <UButton
        icon="i-lucide-arrow-down-to-line"
        color="neutral"
        variant="ghost"
        size="xs"
        :disabled="!canDownload"
        :aria-label="`Baixar guia de ${row.name}`"
        @click="emit('download-guide', row)"
      />
    </UTooltip>

    <UTooltip :text="hasDeclarationDetail ? 'Ver detalhe da declaração' : noDetailTooltip">
      <UButton
        icon="i-lucide-search"
        color="neutral"
        variant="ghost"
        size="xs"
        :disabled="!hasDeclarationDetail"
        :aria-label="`Detalhe da declaração de ${row.name}`"
        @click="emit('open-declaration', row)"
      />
    </UTooltip>
  </div>
</template>
