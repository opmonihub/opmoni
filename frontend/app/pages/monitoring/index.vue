<script setup lang="ts">
import type { MonitoringOverview } from '~/types/serpro'
import {
  monitoringGroups,
  monitoringListPath,
  monitoringObligationUnserved,
  monitoringObligations,
  type MonitoringObligation
} from '~/utils/monitoringNav'
import { formatMonitoringCount, monitoringCategoryPresentation, monitoringMissingValue } from '~/utils/monitoringPresentation'
import { pageScrollClass } from '~/utils/pageShell'

definePageMeta({ middleware: 'auth' })

const { overview } = useSerpro()

/**
 * `getCachedData: () => undefined` is load-bearing, not a default. Without it
 * Nuxt serves the payload cached in the SSR context, and a synchronization
 * that finished a minute ago would keep showing the counters from before it —
 * the spec scenario "Atualização após sincronização" fails.
 */
const { data, status, error, refresh: reload } = await useAsyncData<MonitoringOverview>(
  'serpro-monitoring-overview',
  () => overview(),
  {
    default: () => ({ portfolio_total: 0, attention: {} }),
    getCachedData: () => undefined
  }
)

/**
 * `ignoreStatus: 404` — a 404 means the read API is not there yet, which is the
 * inert state, not a failure worth shouting about. Anything else is.
 *
 * The same exemption the toast applies is applied to the template, because the
 * composable derives both from one check. Guarding only the toast would leave the
 * page shouting "Não foi possível carregar" over a backend that simply has not
 * shipped the endpoint yet — and `tasks.md` 10.3 requires these screens to sit in
 * an empty state in that situation.
 *
 * The `refresh` below is the composable's, not `reload`'s: the navbar button
 * answers through it so a failed manual refresh toasts instead of going quiet.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o monitoramento',
  refreshErrorTitle: 'Não foi possível atualizar o painel',
  ignoreStatus: 404
})

useMonitoringActions({ refresh, loading: isLoading })

/** How many obligations have at least one client needing attention. */
const obligationsWithAttention = computed(() =>
  monitoringObligations.filter(obligation => !monitoringObligationUnserved(obligation) && (data.value.attention[obligation.slug] ?? 0) > 0).length
)

const attentionTotal = computed(() =>
  monitoringObligations.reduce((sum, obligation) => monitoringObligationUnserved(obligation) ? sum : sum + (data.value.attention[obligation.slug] ?? 0), 0)
)

const servedCount = computed(() => monitoringObligations.filter(obligation => !monitoringObligationUnserved(obligation)).length)

function attentionClass(obligation: MonitoringObligation) {
  if (monitoringObligationUnserved(obligation)) return 'text-dimmed'
  return (data.value.attention[obligation.slug] ?? 0) > 0 ? 'text-warning' : 'text-highlighted'
}

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
  <div :class="pageScrollClass">
    <header class="flex min-w-0 items-center">
      <div class="flex min-w-0 items-center gap-2.5">
        <UIcon name="i-lucide-activity" class="size-5 shrink-0 text-primary" />
        <h2 class="truncate text-base font-semibold tracking-tight text-highlighted sm:text-lg">
          Painel do monitoramento
        </h2>
      </div>
    </header>

    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar o monitoramento"
      @retry="retry"
    />

    <template v-else>
      <UPageGrid class="gap-3 sm:gap-3 lg:grid-cols-4 lg:gap-px">
        <MetricCard
          icon="i-lucide-building-2"
          title="Na carteira"
          :to="monitoringObligations[0] ? monitoringListPath(monitoringObligations[0]) : undefined"
          :loading="isLoading"
          :value="formatMonitoringCount(data.portfolio_total)"
        />
        <MetricCard
          icon="i-lucide-circle-alert"
          title="Clientes em atenção"
          :loading="isLoading"
          :value="formatMonitoringCount(attentionTotal)"
          :value-class="attentionTotal > 0 ? 'text-warning' : 'text-highlighted'"
        />
        <MetricCard
          icon="i-lucide-list-checks"
          title="Obrigações com atenção"
          :loading="isLoading"
          :value="formatMonitoringCount(obligationsWithAttention)"
          :value-class="obligationsWithAttention > 0 ? 'text-warning' : 'text-highlighted'"
        />
        <MetricCard
          icon="i-lucide-plug"
          title="Obrigações servidas"
          :loading="isLoading"
          :value="`${formatMonitoringCount(servedCount)} de ${formatMonitoringCount(monitoringObligations.length)}`"
        />
      </UPageGrid>

      <p class="-mt-1 text-xs text-muted sm:-mt-2">
        Clientes com registros sincronizados. Pessoa física não entra: a integração só age para pessoa jurídica.
      </p>

      <section
        v-for="group in monitoringGroups"
        :key="group.label"
        class="flex min-w-0 flex-col gap-3 pt-1"
      >
        <div class="flex min-w-0 items-center gap-2">
          <UIcon :name="group.icon" class="size-4 shrink-0 text-muted" />
          <h3 class="text-sm font-semibold text-highlighted">
            {{ group.label }}
          </h3>
          <span class="hidden truncate text-xs text-muted sm:inline">
            {{ group.description }}
          </span>
        </div>

        <UPageGrid class="gap-3 sm:gap-3 lg:grid-cols-4 lg:gap-px">
          <!--
            The default slot, so a `derived` obligation can carry its
            classification on the card. The office has to be able to see that a
            projection is a projection *before* clicking into it — the badge is
            the difference between "Certidões shows no problems" and "Certidões
            is a filter over the SITFIS report".
          -->
          <MetricCard
            v-for="obligation in group.pages"
            :key="obligation.slug"
            :icon="obligation.icon"
            :title="obligation.label"
            :to="monitoringListPath(obligation)"
          >
            <div class="flex flex-wrap items-center gap-2">
              <USkeleton v-if="isLoading" class="h-8 w-12" />
              <span
                v-else
                class="text-2xl font-semibold tabular-nums"
                :class="attentionClass(obligation)"
              >
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
    </template>
  </div>
</template>
