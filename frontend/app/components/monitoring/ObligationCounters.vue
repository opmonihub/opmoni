<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { sheetToolbarUi } from '~/components/data-table/sheet'
import type { MonitoringCounter, MonitoringObligationSummary, MonitoringSituacao } from '~/types/serpro'
import { monitoringListPath, type MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringCount,
  formatMonitoringProgress,
  monitoringCounterPresentation,
  monitoringProgressPresentation,
  monitoringSituacaoPresentation,
  monitoringTone,
  monitoringTotalIcon,
  monitoringTotalLabel
} from '~/utils/monitoringPresentation'

const props = withDefaults(defineProps<{
  obligation: MonitoringObligation
  summary: MonitoringObligationSummary
  situacao: MonitoringSituacao | null
  /** `toolbar` is the desktop status bar; `chips` is the phone's scrolling line. */
  mode?: 'toolbar' | 'chips'
}>(), {
  mode: 'toolbar'
})

const counters: readonly MonitoringCounter[] = ['em_dia', 'processando', 'pendencias', 'atencao']

/** The unfiltered list. The situation vocabulary has no "all" member. */
const ALL = 'all'

/**
 * Five readings: the total plus the four counters. Each is a link, because the
 * situation is a route segment and a restricted list has to reproduce for
 * whoever follows it. `encerrado` is not one of them — see the footnote.
 */
const readings = computed(() => [
  {
    value: ALL,
    label: monitoringTotalLabel,
    icon: monitoringTotalIcon,
    iconClass: undefined as string | undefined,
    to: monitoringListPath(props.obligation),
    count: props.summary.total
  },
  ...counters.map(counter => ({
    value: counter,
    label: monitoringCounterPresentation[counter].label,
    icon: monitoringCounterPresentation[counter].icon,
    iconClass: monitoringTone[monitoringCounterPresentation[counter].color].icon,
    to: monitoringListPath(props.obligation, counter),
    count: props.summary[counter]
  }))
])

const active = computed(() => props.situacao ?? ALL)

const tabs = computed<NavigationMenuItem[][]>(() => [readings.value.map(reading => ({
  label: reading.label,
  icon: reading.icon,
  iconClass: reading.iconClass,
  to: reading.to,
  exact: true,
  active: active.value === reading.value,
  badge: formatMonitoringCount(reading.count)
}))])

const chips = computed(() => readings.value.map(reading => ({
  label: reading.label,
  value: reading.value,
  to: reading.to,
  count: reading.count
})))

/**
 * The synchronization's own axis, outside the four and never a link: it says
 * how far the transmission got, not which clients need what. Absent while the
 * backend does not report the pair — no reading is not a reading of zero.
 */
const progressSentence = computed(() => formatMonitoringProgress(props.summary.progress))
const progressLabel = computed(() => {
  const progress = props.summary.progress
  if (!progress) return null
  return `${monitoringProgressPresentation.label} ${formatMonitoringCount(progress.transmitted)} de ${formatMonitoringCount(progress.requested)}`
})

/** `encerrado` is a row state, not a fifth counter: folding it in would let a closed obligation inflate a state that requires action. */
const closedLabel = computed(() => `${formatMonitoringCount(props.summary.encerrado)} ${monitoringSituacaoPresentation.encerrado.label}`)
const closedHint = 'Fora dos quatro contadores e do total.'
</script>

<template>
  <UDashboardToolbar
    v-if="mode === 'toolbar'"
    class="hidden min-w-0 md:flex"
    :ui="sheetToolbarUi"
  >
    <template #left>
      <UNavigationMenu
        :items="tabs"
        highlight
        class="-mx-1 min-w-0 flex-1"
        :ui="{ root: 'min-w-0', list: 'min-w-0' }"
      >
        <template #item-leading="{ item }">
          <UIcon
            v-if="item.icon"
            :name="item.icon"
            class="size-5 shrink-0"
            :class="item.iconClass"
          />
        </template>
      </UNavigationMenu>
    </template>

    <template #right>
      <div class="flex shrink-0 items-center gap-1.5">
        <slot name="actions" />
        <UTooltip v-if="progressLabel" :text="progressSentence ?? undefined">
          <UBadge
            size="sm"
            variant="subtle"
            :color="monitoringProgressPresentation.color"
            :icon="monitoringProgressPresentation.icon"
            :label="progressLabel"
            class="tabular-nums"
          />
        </UTooltip>
        <UTooltip :text="closedHint">
          <UBadge
            size="sm"
            variant="subtle"
            :color="monitoringSituacaoPresentation.encerrado.color"
            :icon="monitoringSituacaoPresentation.encerrado.icon"
            :label="closedLabel"
            class="tabular-nums"
          />
        </UTooltip>
      </div>
    </template>
  </UDashboardToolbar>

  <div v-else class="flex min-w-0 flex-col gap-2 md:hidden">
    <div class="flex min-w-0 items-center gap-2">
      <DataTableStatusChips class="min-w-0 flex-1" :items="chips" :active="active" />
      <slot name="actions" />
    </div>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
      <span v-if="progressSentence" class="inline-flex items-center gap-1">
        <UIcon :name="monitoringProgressPresentation.icon" class="size-3.5 shrink-0" />
        {{ progressLabel }}
      </span>
      <span class="inline-flex items-center gap-1">
        <UIcon :name="monitoringSituacaoPresentation.encerrado.icon" class="size-3.5 shrink-0" />
        {{ closedLabel }} · {{ closedHint.toLowerCase() }}
      </span>
    </div>
  </div>
</template>
