<script setup lang="ts">
import type { ClientPortfolioAnalytics, ClientPortfolioSummary } from '~/types/client'
import PortfolioKpis from '~/components/customers/dashboard/PortfolioKpis.vue'
import DeadlineAlertPanels from '~/components/customers/dashboard/DeadlineAlertPanels.vue'
import PortfolioDistribution from '~/components/customers/dashboard/PortfolioDistribution.vue'
import { pageScrollClass } from '~/utils/pageShell'

definePageMeta({ middleware: 'auth' })

const { portfolioSummary, portfolioAnalytics } = useClients()
const toast = useToast()

const summary = ref<ClientPortfolioSummary | null>(null)
const analytics = ref<ClientPortfolioAnalytics | null>(null)
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    const [summaryData, analyticsData] = await Promise.all([
      portfolioSummary({}),
      portfolioAnalytics({})
    ])
    summary.value = summaryData
    analytics.value = analyticsData
  } catch {
    summary.value = null
    analytics.value = null
    toast.add({ title: 'Não foi possível carregar o painel', color: 'error' })
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div :class="pageScrollClass">
    <header class="flex min-w-0 items-center">
      <div class="flex min-w-0 items-center gap-2.5">
        <UIcon name="i-lucide-layout-dashboard" class="size-5 shrink-0 text-primary" />
        <h2 class="truncate text-base font-semibold tracking-tight text-highlighted sm:text-lg">
          Painel da carteira
        </h2>
      </div>
    </header>

    <PortfolioKpis :summary="summary" :loading="loading" />

    <DeadlineAlertPanels
      :certificate="analytics?.attention.certificate ?? []"
      :poa="analytics?.attention.poa ?? []"
      :loading="loading"
    />

    <section class="flex min-w-0 flex-col gap-3 pt-1">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-chart-no-axes-combined" class="size-4 shrink-0 text-muted" />
        <h3 class="text-sm font-semibold text-highlighted">
          Distribuição
        </h3>
      </div>
      <PortfolioDistribution :analytics="analytics" :loading="loading" />
    </section>
  </div>
</template>
