<script setup lang="ts">
import type { ClientPortfolioAnalytics, TaxRegime } from '~/types/client'
import { taxRegimeLabel } from '~/utils/portfolioLabels'
import BrazilStateHeatmap from '~/components/customers/dashboard/BrazilStateHeatmap.vue'
import PortfolioDonutChart from '~/components/customers/dashboard/PortfolioDonutChart.vue'
import PortfolioBarList from '~/components/customers/dashboard/PortfolioBarList.vue'
import PortfolioGrowthChart from '~/components/customers/dashboard/PortfolioGrowthChart.vue'

const props = defineProps<{
  analytics: ClientPortfolioAnalytics | null
  loading?: boolean
}>()

function regimeLabel(key: string) {
  return taxRegimeLabel[key as TaxRegime] ?? key
}

function activityLabel(key: string) {
  const match = props.analytics?.by_activity.find(item => item.key === key)
  if (!match?.label) return key
  return `${key} · ${match.label}`
}

const activityItems = computed(() =>
  (props.analytics?.by_activity ?? []).map(item => ({
    key: item.key,
    count: item.count,
    label: item.label
  }))
)
</script>

<template>
  <div class="flex min-w-0 flex-col gap-3">
    <!--
      Geografia em 3 colunas a partir de lg (~1024px):
      mapa (maior) | região | ranking de estados — evita a barra "Por estado" esticada em largura total.
    -->
    <div class="grid min-w-0 grid-cols-1 items-stretch gap-3 lg:grid-cols-12">
      <BrazilStateHeatmap
        class="min-w-0 overflow-hidden ring ring-default lg:col-span-5"
        :items="analytics?.by_state ?? []"
        :loading="loading"
      />
      <PortfolioDonutChart
        class="min-w-0 overflow-hidden ring ring-default lg:col-span-3"
        title="Por região"
        :items="analytics?.by_region ?? []"
        :loading="loading"
      />
      <PortfolioBarList
        class="min-w-0 overflow-hidden ring ring-default lg:col-span-4"
        title="Por estado"
        :items="(analytics?.by_state ?? []).slice(0, 10)"
        :loading="loading"
        scrollable
      />
    </div>

    <div class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
      <PortfolioBarList
        class="min-w-0 overflow-hidden ring ring-default"
        title="Por cidade"
        :items="analytics?.by_city ?? []"
        :loading="loading"
        scrollable
      />
      <PortfolioDonutChart
        class="min-w-0 overflow-hidden ring ring-default"
        title="Regime tributário"
        :items="analytics?.by_tax_regime ?? []"
        :loading="loading"
        :label-of="regimeLabel"
      />
      <PortfolioBarList
        class="min-w-0 overflow-hidden ring ring-default"
        title="Natureza jurídica"
        :items="analytics?.by_legal_nature ?? []"
        :loading="loading"
        scrollable
      />
      <PortfolioBarList
        class="min-w-0 overflow-hidden ring ring-default"
        title="Atividade econômica"
        :items="activityItems"
        :loading="loading"
        :label-of="activityLabel"
        scrollable
      />
    </div>

    <PortfolioGrowthChart
      class="min-w-0 overflow-hidden ring ring-default"
      :items="analytics?.growth_by_month ?? []"
      :loading="loading"
    />
  </div>
</template>
