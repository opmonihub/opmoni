<script setup lang="ts">
import type { PortfolioBucket } from '~/types/client'
import { formatPtCount } from '~/utils/portfolioLabels'

const props = withDefaults(defineProps<{
  title: string
  items: PortfolioBucket[]
  loading?: boolean
  labelOf?: (key: string) => string
  /** Cap list height so ranking cards stay proportional next to the map. */
  scrollable?: boolean
}>(), {
  scrollable: false
})

const max = computed(() => Math.max(1, ...props.items.map(item => item.count)))

function label(key: string) {
  return props.labelOf?.(key) ?? key
}
</script>

<template>
  <UCard
    class="min-w-0"
    :ui="{
      root: 'flex h-full min-w-0 flex-col',
      header: 'px-3 py-2.5 sm:px-4',
      body: 'flex min-h-0 flex-1 flex-col px-3 py-3 sm:px-4 sm:py-3'
    }"
  >
    <template #header>
      <h3 class="truncate text-sm font-semibold text-highlighted">
        {{ title }}
      </h3>
    </template>

    <div v-if="loading" class="space-y-2">
      <USkeleton v-for="n in 5" :key="n" class="h-5 w-full" />
    </div>

    <p v-else-if="items.length === 0" class="py-4 text-center text-sm text-muted">
      Sem dados
    </p>

    <ul
      v-else
      class="min-h-0 space-y-2.5"
      :class="scrollable ? 'max-h-80 overflow-y-auto lg:max-h-none lg:flex-1' : undefined"
    >
      <li v-for="item in items" :key="item.key" class="min-w-0 space-y-1">
        <div class="flex items-baseline justify-between gap-3 text-sm">
          <span class="min-w-0 break-words text-highlighted leading-snug" :title="label(item.key)">
            {{ label(item.key) }}
          </span>
          <span class="shrink-0 tabular-nums text-muted">
            {{ formatPtCount(item.count) }}
          </span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-elevated">
          <div
            class="h-full rounded-full bg-primary"
            :style="{ width: `${(item.count / max) * 100}%` }"
          />
        </div>
      </li>
    </ul>
  </UCard>
</template>
