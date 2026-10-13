<script setup lang="ts">
import { VisXYContainer, VisLine, VisAxis, VisArea, VisCrosshair, VisTooltip } from '@unovis/vue'
import type { PortfolioBucket } from '~/types/client'
import { formatPtCount } from '~/utils/portfolioLabels'

const props = defineProps<{
  items: PortfolioBucket[]
  loading?: boolean
}>()

const cardRef = useTemplateRef<HTMLElement | null>('cardRef')
const { width } = useElementSize(cardRef)

type Point = { key: string, count: number, index: number }

const data = computed<Point[]>(() =>
  props.items.map((item, index) => ({
    key: item.key,
    count: item.count,
    index
  }))
)

const x = (d: Point) => d.index
const y = (d: Point) => d.count
const total = computed(() => data.value.reduce((sum, item) => sum + item.count, 0))

const monthLabel = new Intl.DateTimeFormat('pt-BR', { month: 'short', year: '2-digit' })

function formatMonth(key: string) {
  const [year, month] = key.split('-').map(Number)
  if (!year || !month) return key
  return monthLabel.format(new Date(year, month - 1, 1))
}

const xTicks = (i: number) => {
  const point = data.value[i]
  if (!point) return ''
  if (i === 0 || i === data.value.length - 1 || i % 2 === 0) {
    return formatMonth(point.key)
  }
  return ''
}

const template = (d: Point) => `${formatMonth(d.key)}: ${formatPtCount(d.count)}`
</script>

<template>
  <UCard
    ref="cardRef"
    :ui="{
      header: 'px-3 py-2.5 sm:px-4',
      body: 'px-0! pt-0! pb-2!'
    }"
  >
    <template #header>
      <div class="flex min-w-0 items-baseline justify-between gap-3">
        <h3 class="truncate text-sm font-semibold text-highlighted">
          Crescimento
        </h3>
        <span class="shrink-0 text-xs tabular-nums text-muted">
          {{ formatPtCount(total) }} em 12 meses
        </span>
      </div>
    </template>

    <div v-if="loading" class="px-3 pb-3">
      <USkeleton class="h-48 w-full" />
    </div>

    <p v-else-if="data.length === 0" class="px-3 py-6 text-center text-sm text-muted">
      Sem dados
    </p>

    <ClientOnly v-else>
      <VisXYContainer
        :data="data"
        :padding="{ top: 16 }"
        :margin="{ left: -5, right: -5 }"
        class="h-48"
        :width="width"
      >
        <VisLine
          :x="x"
          :y="y"
          color="var(--ui-primary)"
        />
        <VisArea
          :x="x"
          :y="y"
          color="var(--ui-primary)"
          :opacity="0.12"
        />
        <VisAxis
          type="x"
          :x="x"
          :tick-format="xTicks"
        />
        <VisCrosshair
          :x="x"
          :y="y"
          color="var(--ui-primary)"
          :template="template"
        />
        <VisTooltip />
      </VisXYContainer>
    </ClientOnly>
  </UCard>
</template>

<style scoped>
.unovis-xy-container {
  --vis-crosshair-line-stroke-color: var(--ui-primary);
  --vis-crosshair-circle-stroke-color: var(--ui-bg);
  --vis-axis-grid-color: var(--ui-border);
  --vis-axis-tick-color: var(--ui-border);
  --vis-axis-tick-label-color: var(--ui-text-dimmed);
  --vis-tooltip-background-color: var(--ui-bg);
  --vis-tooltip-border-color: var(--ui-border);
  --vis-tooltip-text-color: var(--ui-text-highlighted);
}
</style>
