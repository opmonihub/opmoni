<script setup lang="ts">
import { apiStatus } from '~/composables/useApiError'
import type { MonitoringOverview } from '~/types/serpro'
import {
  monitoringGroups,
  monitoringIntegrationLinks,
  monitoringListPath,
  monitoringObligationUnserved,
  monitoringObligations,
  type MonitoringObligation
} from '~/utils/monitoringNav'
import { formatMonitoringCount, monitoringCategoryPresentation, monitoringMissingValue } from '~/utils/monitoringPresentation'

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

/**
 * The same exemption the toast applies, applied to the template. Guarding only
 * the toast would leave the page shouting "Não foi possível carregar" over a
 * backend that simply has not shipped the endpoint yet — and `tasks.md` 10.3
 * requires these screens to sit in an empty state in that situation.
 */
const showError = computed(() => !!error.value && apiStatus(error.value) !== 404)

/**
 * The dash, not a zero, for an obligation the provider does not serve. `0` would
 * say "no client needs attention here", which is a claim about the office's
 * clients; the truth is that the integration cannot answer for that obligation
 * at all. The sheet already draws no counter for these — the overview cannot
 * answer a different question than the page it links to, or the same obligation
 * reads as pending on one screen and unserved on the other.
 */
function attentionFor(obligation: MonitoringObligation) {
  if (monitoringObligationUnserved(obligation)) return monitoringMissingValue
  return formatMonitoringCount(data.value.attention[obligation.slug] ?? 0)
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-8 overflow-y-auto p-4 sm:p-6">
    <UAlert
      v-if="showError"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar o monitoramento"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <USkeleton v-else-if="isLoading && !error" class="h-64 w-full" />

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
          <!--
            The default slot, as `HomeStats` uses it, so a `derived` obligation
            can carry its classification on the card. The office has to be able
            to see that a projection is a projection *before* clicking into it —
            the badge is the difference between "Certidões shows no problems"
            and "Certidões is a filter over the SITFIS report". The value lives
            in the slot for every obligation, so the cards stay one row.
          -->
          <MetricCard
            v-for="obligation in group.pages"
            :key="obligation.slug"
            :icon="obligation.icon"
            :title="obligation.label"
            :to="monitoringListPath(obligation)"
          >
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-2xl font-semibold tabular-nums text-highlighted">
                {{ attentionFor(obligation) }}
              </span>
              <UBadge
                v-if="obligation.category === 'derived'"
                size="sm"
                variant="subtle"
                :color="monitoringCategoryPresentation.derived.color"
                :icon="monitoringCategoryPresentation.derived.icon"
                :label="monitoringCategoryPresentation.derived.label"
              />
            </div>
          </MetricCard>
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
