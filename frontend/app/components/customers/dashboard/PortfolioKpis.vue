<script setup lang="ts">
import type { ClientPortfolioSummary } from '~/types/client'
import { customerListPath } from '~/utils/customerRoutes'
import { formatPtCount } from '~/utils/portfolioLabels'

const props = defineProps<{
  summary: ClientPortfolioSummary | null
  loading?: boolean
}>()

function attention(document: 'certificate' | 'poa') {
  if (!props.summary) return 0
  return props.summary[document].expired + props.summary[document].expiring
}

function attentionPath(document: 'certificate' | 'poa') {
  const summary = props.summary
  if (!summary) return customerListPath(document, 'expiring')
  // Preferência pelo vencido: mais urgente quando ambos existem no KPI combinado.
  if (summary[document].expired > 0) return customerListPath(document, 'expired')
  return customerListPath(document, 'expiring')
}
</script>

<template>
  <UPageGrid class="gap-3 sm:gap-3 lg:grid-cols-4 lg:gap-px">
    <MetricCard
      icon="i-lucide-building-2"
      title="Total"
      to="/customers/certificados"
      :loading="loading"
      :value="formatPtCount(summary?.total ?? 0)"
    />
    <MetricCard
      icon="i-lucide-circle-check"
      title="Ativos"
      to="/customers/certificados"
      :loading="loading"
      :value="formatPtCount(summary?.active ?? 0)"
      value-class="text-success"
    />
    <MetricCard
      icon="i-lucide-key-round"
      title="Certificados"
      :to="attentionPath('certificate')"
      :loading="loading"
      :value="formatPtCount(attention('certificate'))"
      :value-class="attention('certificate') > 0 ? 'text-warning' : 'text-highlighted'"
    />
    <MetricCard
      icon="i-lucide-file-key-2"
      title="Procurações"
      :to="attentionPath('poa')"
      :loading="loading"
      :value="formatPtCount(attention('poa'))"
      :value-class="attention('poa') > 0 ? 'text-warning' : 'text-highlighted'"
    />
  </UPageGrid>
</template>
