<script setup lang="ts">
import { apiStatus } from '~/composables/useApiError'
import type { MonitoringOverview } from '~/types/serpro'
import {
  monitoringGroups,
  monitoringIntegrationLinks,
  monitoringListPath,
  monitoringObligations,
  type MonitoringObligation
} from '~/utils/monitoringNav'
import { formatMonitoringCount } from '~/utils/monitoringPresentation'

definePageMeta({ middleware: 'auth' })

const toast = useToast()
const { overview } = useSerpro()

/**
 * `getCachedData: () => undefined` is load-bearing, not a default. Without it
 * Nuxt serves the payload cached in the SSR context, and a synchronization
 * that finished a minute ago would keep showing the counters from before it —
 * the spec scenario "Atualização após sincronização" fails.
 */
const { data, status, error, refresh } = await useAsyncData<MonitoringOverview>(
  'serpro-monitoring-overview',
  () => overview(),
  {
    default: () => ({ portfolio_total: 0, attention: {} }),
    getCachedData: () => undefined
  }
)

const isLoading = computed(() => status.value === 'pending')

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar o painel', color: 'error' })
  }
}

watch(error, (value) => {
  // A 404 means the read API is not there yet, which is the inert state, not
  // a failure worth shouting about. Anything else is.
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar o monitoramento', color: 'error' })
  }
})

function attentionFor(obligation: MonitoringObligation) {
  return formatMonitoringCount(data.value.attention[obligation.slug] ?? 0)
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-8 overflow-y-auto p-4 sm:p-6">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar o monitoramento"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <UPageSkeleton v-else-if="isLoading" :rows="6" />

    <template v-else>
      <section class="flex flex-col gap-4">
        <div>
          <h2 class="text-lg font-semibold text-highlighted">
            Carteira
          </h2>
          <p class="text-sm text-muted">
            Clientes com registros sincronizados. Pessoa física não entra: a integração só age para pessoa jurídica.
          </p>
        </div>

        <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
          <MetricCard
            icon="i-lucide-building-2"
            title="Na carteira"
            :to="monitoringObligations[0] ? monitoringListPath(monitoringObligations[0]) : undefined"
            :value="formatMonitoringCount(data.portfolio_total)"
          />
        </UPageGrid>
      </section>

      <section v-for="group in monitoringGroups" :key="group.label" class="flex flex-col gap-4">
        <div class="flex items-start gap-3">
          <UIcon :name="group.icon" class="mt-0.5 size-5 text-muted" />
          <div>
            <h2 class="text-lg font-semibold text-highlighted">
              {{ group.label }}
            </h2>
            <p class="text-sm text-muted">
              {{ group.description }}
            </p>
          </div>
        </div>

        <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
          <MetricCard
            v-for="obligation in group.pages"
            :key="obligation.slug"
            :icon="obligation.icon"
            :title="obligation.label"
            :to="monitoringListPath(obligation)"
            :value="attentionFor(obligation)"
          />
        </UPageGrid>
      </section>

      <section class="flex flex-col gap-4">
        <div>
          <h2 class="text-lg font-semibold text-highlighted">
            Integração
          </h2>
          <p class="text-sm text-muted">
            Termo do escritório e histórico de sincronizações.
          </p>
        </div>

        <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
          <MetricCard
            v-for="link in monitoringIntegrationLinks"
            :key="link.to"
            :icon="link.icon"
            :title="link.label"
            :to="link.to"
            value="&rarr;"
          />
        </UPageGrid>
      </section>
    </template>
  </div>
</template>
