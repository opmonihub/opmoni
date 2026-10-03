<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import DataTableIdentity from '~/components/data-table/Identity.vue'
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { sheetTableUi } from '~/components/data-table/sheet'
import { apiMessage, apiStatus } from '~/composables/useApiError'
import type { SerproSyncRunDetail, SerproSyncRunItem } from '~/types/serpro'
import { monitoringObligations } from '~/utils/monitoringNav'
import {
  formatMonitoringCount,
  formatMonitoringDate,
  serproRunItemStatePresentation,
  serproRunStatePresentation
} from '~/utils/monitoringPresentation'
import { pageDetailClass, pageRecordScrollClass } from '~/utils/pageShell'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const toast = useToast()
const { canManageClients } = useAuth()
const { showSyncRun, resyncRun } = useSerpro()

/**
 * `Number.isInteger`, not `Number.isNaN`: `Number('abc')` is `NaN` but
 * `Number('1.5')` is `1.5` and `Number('')` is `0`, so anything weaker would put
 * a wrong id — or `NaN` — in the request path. A rejected id renders the
 * not-found state and issues no call at all.
 */
const runId = computed(() => {
  const raw = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
})

const { data, status, error, refresh: reload } = await useAsyncData<SerproSyncRunDetail | null>(
  computed(() => (runId.value === null ? 'serpro-sync-run' : `serpro-sync-run-${runId.value}`)),
  async () => {
    if (runId.value === null) return null
    try {
      return await showSyncRun(runId.value)
    } catch (e) {
      // A run of another Account answers 404 by design, so a 404 is the
      // not-found state and never an error shown to a member.
      if (apiStatus(e) === 404) return null
      throw e
    }
  },
  { default: () => null, getCachedData: () => undefined }
)

/**
 * `ignoreStatus: 404` — a run of another Account answers 404 by design, so the
 * loader above turns it into the not-found state and nothing here is ever a
 * 404; the exemption is what keeps that from becoming a member-facing error.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar a execução',
  refreshErrorTitle: 'Não foi possível atualizar a execução',
  ignoreStatus: 404
})

const run = computed(() => data.value)
const items = computed(() => run.value?.items ?? [])
const runState = computed(() => (run.value ? serproRunStatePresentation[run.value.state] : null))

const resyncing = ref(false)

/**
 * Offered only to a role the backend accepts, so a member is never shown a
 * button that would answer 403 — and never shown a 403 that was never theirs
 * to earn. A `403` that still arrives is the integration not being enabled for
 * the Account: a warning the office can act on, and by design never a redirect
 * (`app/plugins/api.ts` redirects on 401/419 alone).
 */
async function onResync() {
  if (!run.value || resyncing.value) return
  resyncing.value = true
  try {
    const started = await resyncRun(run.value.id)
    toast.add({
      title: 'Re-sincronização enfileirada',
      description: `Execução #${started.id}`,
      color: 'success'
    })
    // A re-sync is a new run. Staying on this one would leave the member
    // watching items that will never move.
    if (started.id === run.value.id) {
      await refresh()
    } else {
      await navigateTo(`/monitoring/execucoes/${started.id}`)
    }
  } catch (e) {
    if (apiStatus(e) === 403) {
      toast.add({
        title: 'A integração não está habilitada para este escritório',
        description: apiMessage(e),
        color: 'warning'
      })
    } else {
      toast.add({
        title: 'Não foi possível reenviar a sincronização',
        description: apiMessage(e),
        color: 'error'
      })
    }
  } finally {
    resyncing.value = false
  }
}

/** The item vocabulary is the run's own; the obligation label is not. */
function obligationLabel(item: SerproSyncRunItem) {
  if (!item.obligation) return '—'
  return monitoringObligations.find(obligation => obligation.slug === item.obligation)?.label ?? item.obligation
}

/**
 * Only these two outcomes carry a reason. `nao_processado` has not happened
 * yet and `sincronizado` succeeded, so neither is shown a motive it never had.
 */
function reasonFor(item: SerproSyncRunItem) {
  if (item.state !== 'ignorado' && item.state !== 'falhou') return '—'
  return item.reason ?? '—'
}

const itemColumns: TableColumn<SerproSyncRunItem>[] = [
  { id: 'client', header: 'Cliente', meta: { class: { th: 'min-w-56 whitespace-nowrap', td: 'max-w-0' } } },
  { id: 'state', header: 'Estado', meta: { class: { th: 'min-w-32 whitespace-nowrap', td: '' } } },
  { id: 'obligation', header: 'Obrigação', meta: { class: { th: 'min-w-32 whitespace-nowrap', td: '' } } },
  { id: 'reason', header: 'Motivo', meta: { class: { th: 'min-w-40 whitespace-nowrap', td: '' } } },
  { id: 'updated_at', header: 'Atualizado em', meta: { class: { th: 'whitespace-nowrap', td: 'tabular-nums' } } }
]

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = [
  { id: 'client', label: 'Cliente' },
  { id: 'state', label: 'Estado' },
  { id: 'obligation', label: 'Obrigação' },
  { id: 'reason', label: 'Motivo' },
  { id: 'updated_at', label: 'Atualizado em' }
]

/**
 * The three facts the mobile card carries, in the `dl` shape the desktop table
 * shows as columns. `Motivo` is dropped rather than blanked: only these two
 * outcomes carry one, and an em dash would read as a stated reason.
 */
function itemFacts(item: SerproSyncRunItem): MetaListItem[] {
  return [
    { label: 'Obrigação', value: obligationLabel(item), truncate: true },
    { label: 'Motivo', value: reasonFor(item), when: reasonFor(item) !== '—' },
    { label: 'Atualizado em', value: formatMonitoringDate(item.updated_at), mono: true }
  ]
}

useMonitoringActions({ refresh, loading: isLoading })

/** The navbar title, set by this page because the shell does not know the id. */
const detailTitle = useState<string | null>('monitoring-detail-title', () => null)
watch(run, (value) => {
  detailTitle.value = value ? `Execução #${value.id}` : null
}, { immediate: true })

const runCounts = computed(() => {
  if (!run.value) return []
  return [
    { label: 'Total', value: run.value.total, class: 'text-highlighted' },
    { label: 'Sincronizados', value: run.value.synchronized, class: 'text-success' },
    { label: 'Ignorados', value: run.value.skipped, class: 'text-highlighted' },
    { label: 'Falhos', value: run.value.failed, class: run.value.failed > 0 ? 'text-error' : 'text-highlighted' },
    // Indeterminate is an answer the provider never gave: beside `failed`,
    // never inside it.
    { label: 'Indeterminados', value: run.value.indeterminate, class: run.value.indeterminate > 0 ? 'text-warning' : 'text-highlighted' },
    { label: 'Pendentes', value: run.value.not_processed, class: 'text-highlighted' }
  ]
})

/** Share of the run already answered, for the same bar the client detail draws. */
const donePct = computed(() => {
  if (!run.value || run.value.total === 0) return 0
  // Indeterminate counts as answered: the call went out and the provider's
  // response identifier is on the item — what is missing is certainty, not
  // an attempt.
  const done = run.value.synchronized + run.value.skipped + run.value.failed + run.value.indeterminate
  return Math.min(100, Math.round((done / run.value.total) * 100))
})

const barClass: Record<string, string> = {
  neutral: 'bg-accented',
  info: 'bg-info',
  success: 'bg-success',
  warning: 'bg-warning',
  error: 'bg-error'
}

const progressBarClass = computed(() => barClass[runState.value?.color ?? 'neutral'])
</script>

<template>
  <div :class="pageRecordScrollClass">
    <div :class="pageDetailClass">
      <ErrorRetryAlert
        v-if="showError"
        title="Não foi possível carregar a execução"
        @retry="retry"
      />

      <template v-else-if="isLoading">
        <USkeleton class="h-28 w-full rounded-xl" />
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <USkeleton v-for="index in 6" :key="index" class="h-20 w-full rounded-xl" />
        </div>
        <USkeleton class="h-64 w-full rounded-xl" />
      </template>

      <UEmpty
        v-else-if="!run"
        icon="i-lucide-file-question"
        title="Execução não encontrada"
        description="A execução pode ter sido removida ou o link está incorreto."
        variant="naked"
        :actions="[{ label: 'Ver execuções', icon: 'i-lucide-arrow-left', to: '/monitoring/execucoes' }]"
      />

      <template v-else>
        <UCard :ui="{ body: 'p-3 sm:p-4' }">
          <div class="flex items-start gap-3">
            <span
              aria-hidden="true"
              class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-inset ring-primary/20"
            >
              <UIcon name="i-lucide-refresh-cw" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
              <h1 class="truncate text-lg font-semibold tracking-tight text-highlighted">
                Execução #{{ run.id }}
              </h1>
              <div v-if="runState" class="mt-2 flex flex-wrap items-center gap-1.5">
                <UBadge
                  :color="runState.color"
                  :icon="runState.icon"
                  variant="subtle"
                  size="sm"
                  :label="runState.label"
                />
              </div>
              <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                <span class="inline-flex items-center gap-1 tabular-nums">
                  <UIcon name="i-lucide-play" class="size-3.5 shrink-0" />
                  Iniciada em {{ formatMonitoringDate(run.started_at) }}
                </span>
                <span class="inline-flex items-center gap-1 tabular-nums">
                  <UIcon name="i-lucide-flag" class="size-3.5 shrink-0" />
                  Concluída em {{ formatMonitoringDate(run.finished_at) }}
                </span>
              </div>
            </div>
            <div v-if="canManageClients" class="flex shrink-0 items-center gap-1.5">
              <UButton
                icon="i-lucide-rotate-cw"
                color="neutral"
                variant="outline"
                size="xs"
                aria-label="Reenviar sincronização"
                class="sm:hidden"
                :loading="resyncing"
                @click="onResync"
              />
              <UButton
                label="Reenviar sincronização"
                icon="i-lucide-rotate-cw"
                color="neutral"
                variant="outline"
                size="xs"
                class="hidden sm:inline-flex"
                :loading="resyncing"
                @click="onResync"
              />
            </div>
          </div>

          <USeparator class="my-3" />

          <div class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between gap-3 text-xs">
              <span class="text-muted">Clientes respondidos</span>
              <span class="tabular-nums text-default">{{ donePct }}%</span>
            </div>
            <div
              class="h-1.5 overflow-hidden rounded-full bg-muted"
              role="progressbar"
              :aria-valuenow="donePct"
              aria-valuemin="0"
              aria-valuemax="100"
              aria-label="Clientes respondidos nesta execução"
            >
              <div
                class="h-full rounded-full"
                :class="progressBarClass"
                :style="{ width: `${donePct}%` }"
              />
            </div>
          </div>
        </UCard>

        <UPageGrid class="gap-3 sm:gap-3 lg:grid-cols-6 lg:gap-px">
          <UPageCard
            v-for="count in runCounts"
            :key="count.label"
            :title="count.label"
            variant="subtle"
            :ui="{ container: 'gap-y-1.5', title: 'font-normal text-muted text-sm' }"
            class="lg:rounded-none first:rounded-l-lg last:rounded-r-lg"
          >
            <span class="text-2xl font-semibold tabular-nums" :class="count.class">
              {{ formatMonitoringCount(count.value) }}
            </span>
          </UPageCard>
        </UPageGrid>

        <UCard :ui="{ header: 'px-3 py-2.5 sm:px-4', body: 'p-0 sm:p-0' }">
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-users" class="size-4 shrink-0 text-muted" />
              <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-highlighted">
                Clientes da execução
              </h2>
              <UBadge
                color="neutral"
                variant="subtle"
                size="sm"
                class="tabular-nums"
                :label="formatMonitoringCount(items.length)"
              />
              <DataTableColumnMenu
                v-model="columnVisibility"
                :columns="hideableColumns"
                class="hidden shrink-0 md:flex"
              />
            </div>
          </template>

          <UEmpty
            v-if="items.length === 0"
            icon="i-lucide-inbox"
            title="Nenhum cliente nesta execução"
            description="A execução não registrou nenhum item."
            variant="naked"
            class="py-8"
          />

          <template v-else>
            <ul class="divide-y divide-default md:hidden">
              <li
                v-for="item in items"
                :key="`${item.client_id}-${item.obligation ?? ''}`"
                class="flex flex-col gap-2 px-3 py-3"
              >
                <div class="flex items-start justify-between gap-3">
                  <DataTableIdentity :title="item.name" :meta="item.tax_id ?? ''" />
                  <UBadge
                    class="shrink-0"
                    :color="serproRunItemStatePresentation[item.state].color"
                    :icon="serproRunItemStatePresentation[item.state].icon"
                    variant="subtle"
                    size="sm"
                    :label="serproRunItemStatePresentation[item.state].label"
                  />
                </div>
                <DataTableMetaList layout="stack" :items="itemFacts(item)" />
              </li>
            </ul>

            <UTable
              v-model:column-visibility="columnVisibility"
              :data="items"
              :columns="itemColumns"
              class="hidden p-3 md:block"
              :ui="sheetTableUi"
            >
              <template #client-cell="{ row }">
                <DataTableIdentity :title="row.original.name" :meta="row.original.tax_id ?? ''" />
              </template>

              <template #state-cell="{ row }">
                <UBadge
                  :color="serproRunItemStatePresentation[row.original.state].color"
                  :icon="serproRunItemStatePresentation[row.original.state].icon"
                  variant="subtle"
                  :label="serproRunItemStatePresentation[row.original.state].label"
                />
              </template>

              <template #obligation-cell="{ row }">
                {{ obligationLabel(row.original) }}
              </template>

              <template #reason-cell="{ row }">
                <span class="text-muted">{{ reasonFor(row.original) }}</span>
              </template>

              <template #updated_at-cell="{ row }">
                {{ formatMonitoringDate(row.original.updated_at) }}
              </template>
            </UTable>
          </template>
        </UCard>
      </template>
    </div>
  </div>
</template>
