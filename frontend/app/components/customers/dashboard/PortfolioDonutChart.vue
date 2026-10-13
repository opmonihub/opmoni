<script setup lang="ts">
import { VisSingleContainer, VisDonut } from '@unovis/vue'
import type { PortfolioBucket } from '~/types/client'
import { formatPtCount } from '~/utils/portfolioLabels'

const props = defineProps<{
  title: string
  items: PortfolioBucket[]
  loading?: boolean
  labelOf?: (key: string) => string
}>()

type Slice = { key: string, label: string, count: number }

const chartColors = [
  'var(--ui-primary)',
  'var(--ui-info)',
  'var(--ui-success)',
  'var(--ui-warning)',
  'var(--ui-error)',
  'var(--ui-secondary)'
]

const slices = computed<Slice[]>(() =>
  props.items.map(item => ({
    key: item.key,
    label: props.labelOf?.(item.key) ?? item.key,
    count: item.count
  }))
)

const value = (d: Slice) => d.count
const color = (_: Slice, i: number) => chartColors[i % chartColors.length]!
const total = computed(() => slices.value.reduce((sum, item) => sum + item.count, 0))

function percentOf(count: number) {
  if (total.value === 0) return '0%'
  return new Intl.NumberFormat('pt-BR', {
    style: 'percent',
    maximumFractionDigits: 0
  }).format(count / total.value)
}
</script>

<template>
  <!--
    UCard (Nuxt UI): root/header/body slots.
    Body usa absolute inset para centralizar de verdade quando o grid estica o card
    até a altura do mapa — flex-1 + justify-center no body não basta se a altura
    definitiva não sobe corretamente no merge das classes padrão (p-4 sm:p-6).
  -->
  <UCard
    :title="title"
    variant="outline"
    class="h-full min-w-0"
    :ui="{
      root: 'flex h-full min-h-0 flex-col',
      header: 'shrink-0 py-2.5 px-3 sm:px-4',
      title: 'text-sm font-semibold text-highlighted',
      body: 'relative min-h-72 flex-1 p-0 sm:p-0'
    }"
  >
    <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 overflow-y-auto p-3 sm:p-4">
      <div v-if="loading" class="flex items-center justify-center">
        <USkeleton class="size-36 rounded-full" />
      </div>

      <p v-else-if="slices.length === 0" class="text-center text-sm text-muted">
        Sem dados
      </p>

      <template v-else>
        <ClientOnly>
          <VisSingleContainer
            :data="slices"
            :width="148"
            :height="148"
            class="shrink-0"
          >
            <VisDonut
              :value="value"
              :color="color"
              :arc-width="18"
              :central-label="formatPtCount(total)"
              central-sub-label="total"
            />
          </VisSingleContainer>
        </ClientOnly>

        <ul class="w-full min-w-0 max-w-full space-y-2">
          <li
            v-for="(item, index) in slices"
            :key="item.key"
            class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-2 text-sm"
          >
            <span
              class="size-2.5 shrink-0 rounded-full"
              :style="{ backgroundColor: chartColors[index % chartColors.length] }"
            />
            <span class="min-w-0 truncate text-highlighted" :title="item.label">
              {{ item.label }}
            </span>
            <span class="shrink-0 text-right tabular-nums text-muted">
              {{ formatPtCount(item.count) }}
              <span class="ms-1 text-dimmed">{{ percentOf(item.count) }}</span>
            </span>
          </li>
        </ul>
      </template>
    </div>
  </UCard>
</template>
