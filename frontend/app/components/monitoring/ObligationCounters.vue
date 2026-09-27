<script setup lang="ts">
import { useAuth } from '~/composables/useAuth'
import type { MonitoringCounter, MonitoringObligationSummary, MonitoringSituacao } from '~/types/serpro'
import { monitoringListPath, type MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringCount,
  formatMonitoringProgress,
  monitoringCounterPresentation,
  monitoringProgressPresentation,
  monitoringSituacaoPresentation,
  monitoringTotalLabel
} from '~/utils/monitoringPresentation'

defineProps<{
  obligation: MonitoringObligation
  summary: MonitoringObligationSummary
  situacao: MonitoringSituacao | null
}>()

const emit = defineEmits<{ associate: [] }>()

/** Associating is an Account-level act — admin or operador, never a plain user. */
const { canManageClients } = useAuth()

const counters: readonly MonitoringCounter[] = ['em_dia', 'processando', 'pendencias', 'atencao']
</script>

<template>
  <div class="flex flex-col gap-2">
    <div class="flex items-center justify-between gap-3">
      <UPageGrid class="grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5 flex-1">
        <UButton
          :to="monitoringListPath(obligation)"
          :color="situacao === null ? 'primary' : 'neutral'"
          :variant="situacao === null ? 'soft' : 'outline'"
          :ui="{ base: 'h-auto w-full items-center justify-between gap-2 p-3' }"
        >
          <span class="flex min-w-0 items-center gap-1.5 text-sm">
            <UIcon name="i-lucide-users" class="shrink-0" />
            <span class="truncate">{{ monitoringTotalLabel }}</span>
          </span>
          <UKbd class="shrink-0">
            {{ formatMonitoringCount(summary.total) }}
          </UKbd>
        </UButton>

        <UButton
          v-for="counter in counters"
          :key="counter"
          :to="monitoringListPath(obligation, counter)"
          :color="situacao === counter ? 'primary' : 'neutral'"
          :variant="situacao === counter ? 'soft' : 'outline'"
          :ui="{ base: 'h-auto w-full items-center justify-between gap-2 p-3' }"
        >
          <span class="flex min-w-0 items-center gap-1.5 text-sm">
            <UIcon :name="monitoringCounterPresentation[counter].icon" class="shrink-0" />
            <span class="truncate">{{ monitoringCounterPresentation[counter].label }}</span>
          </span>
          <UKbd class="shrink-0">
            {{ formatMonitoringCount(summary[counter]) }}
          </UKbd>
        </UButton>
      </UPageGrid>

      <UButton
        v-if="canManageClients"
        icon="i-lucide-user-plus"
        color="neutral"
        variant="outline"
        label="Adicionar clientes"
        class="shrink-0 self-center"
        @click="emit('associate')"
      />
    </div>

    <!-- `encerrado` is a row state, not a fifth counter: folding it in would
         let a closed obligation inflate a state that requires action. -->
    <p class="flex items-center gap-1.5 text-xs text-muted">
      <UBadge
        size="sm"
        variant="subtle"
        :color="monitoringSituacaoPresentation.encerrado.color"
        :icon="monitoringSituacaoPresentation.encerrado.icon"
        :label="`${formatMonitoringCount(summary.encerrado)} ${monitoringSituacaoPresentation.encerrado.label}`"
      />
      <span>fora dos quatro contadores acima.</span>
    </p>

    <!--
      The synchronization's own axis, beside the counters and outside them. Not
      a sixth reading of a client: it says how far the transmission got, so it
      is plain text, it is not a link into a filtered list, and it renders only
      when the backend reports the pair — an absent reading is not a reading of
      zero transmitted.
    -->
    <p
      v-if="formatMonitoringProgress(summary.progress)"
      class="flex items-center gap-1.5 text-xs text-muted"
    >
      <UIcon
        :name="monitoringProgressPresentation.icon"
        class="shrink-0"
      />
      <span>{{ formatMonitoringProgress(summary.progress) }}</span>
    </p>
  </div>
</template>
