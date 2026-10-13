<script setup lang="ts">
import type { DeadlineStatus } from '~/types/client'

defineProps<{
  statusCards: Array<{
    label: string
    value: DeadlineStatus | 'all'
    icon: string
    iconClass?: string
    to: string
    count?: number
    selected: boolean
  }>
  loading?: boolean
  unavailable?: boolean
}>()
</script>

<template>
  <nav class="w-full min-w-0 shrink-0" aria-label="Filtrar clientes por situação" :aria-busy="loading">
    <div class="grid min-w-0 auto-cols-[minmax(9.5rem,1fr)] grid-flow-col gap-2 overflow-x-auto overscroll-x-contain p-1 md:auto-cols-auto md:grid-flow-row md:grid-cols-5">
      <MetricCard
        v-for="item in statusCards"
        :key="item.value"
        compact
        :icon="item.icon"
        :icon-class="item.iconClass"
        :title="item.label"
        :to="item.to"
        :value="item.count"
        :selected="item.selected"
        :loading="loading"
        :unavailable="unavailable"
      />
    </div>
  </nav>
</template>
