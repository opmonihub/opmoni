<script setup lang="ts">
import { useInfiniteScroll } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { ComponentPublicInstance } from 'vue'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { sheetBodyClass, sheetTableUi } from '~/components/data-table/sheet'
import { apiStatus } from '~/composables/useApiError'
import type { SerproSyncRun } from '~/types/serpro'
import {
  formatMonitoringCount,
  formatMonitoringDate,
  serproRunStatePresentation
} from '~/utils/monitoringPresentation'

definePageMeta({ middleware: 'auth' })

type RunsPage = Awaited<ReturnType<ReturnType<typeof useSerpro>['syncRuns']>>

const toast = useToast()
const { syncRuns } = useSerpro()

/**
 * `getCachedData: () => undefined` is load-bearing, not a default. A run that
 * finished a minute ago would otherwise keep showing the history as it stood
 * before it, which is the one thing this screen exists to report.
 */
const { data, status, error, refresh: reload } = await useAsyncData<RunsPage>(
  'serpro-sync-runs',
  async () => {
    try {
      return await syncRuns()
    } catch (e) {
      // A history that has never been written answers 404 as surely as one
      // with nothing in it. Both are the inert state, never a failure.
      if (apiStatus(e) === 404) return { data: [] as SerproSyncRun[] }
      throw e
    }
  },
  { default: () => ({ data: [] as SerproSyncRun[] }), getCachedData: () => undefined }
)

/**
 * `ignoreStatus: 404` — the loader above already answers a 404 with an empty
 * history, so nothing reaches here as one; the exemption keeps the alert on the
 * same footing as the loader rather than re-deriving the status at the call site.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar as execuções',
  refreshErrorTitle: 'Não foi possível atualizar as execuções',
  ignoreStatus: 404
})

const runs = ref<SerproSyncRun[]>([])
const page = ref(1)
const lastPage = ref(1)
const loadingMore = ref(false)
let generation = 0

watch(data, (value) => {
  generation += 1
  runs.value = value?.data ?? []
  page.value = value?.meta?.current_page ?? 1
  lastPage.value = value?.meta?.last_page ?? 1
}, { immediate: true })

const canLoadMore = computed(() => !isLoading.value && !loadingMore.value && page.value < lastPage.value)

async function loadMore() {
  if (!canLoadMore.value) return
  const seen = generation
  const nextPage = page.value + 1
  loadingMore.value = true
  try {
    const response = await syncRuns({ page: nextPage })
    if (seen !== generation) return
    const known = new Set(runs.value.map(run => run.id))
    runs.value = [...runs.value, ...response.data.filter(run => !known.has(run.id))]
    page.value = response.meta?.current_page ?? nextPage
    lastPage.value = response.meta?.last_page ?? page.value
  } catch {
    toast.add({ title: 'Não foi possível carregar mais execuções', color: 'error' })
  } finally {
    loadingMore.value = false
  }
}

/**
 * The six counts of one run, in the order the table declares them.
 * `indeterminate` and `not_processed` only appear when nonzero: both are
 * events, not everyday outcomes, and a permanent pair of zeroes would
 * teach the reader to stop seeing them.
 */
function runCounters(run: SerproSyncRun): MetaListItem[] {
  return [
    { label: 'Total', value: formatMonitoringCount(run.total), mono: true },
    { label: 'Sincr.', value: formatMonitoringCount(run.synchronized), mono: true },
    { label: 'Ignor.', value: formatMonitoringCount(run.skipped), mono: true },
    { label: 'Falhos', value: formatMonitoringCount(run.failed), mono: true, tone: run.failed > 0 ? 'error' : 'default' },
    { label: 'Indet.', value: formatMonitoringCount(run.indeterminate), mono: true, tone: run.indeterminate > 0 ? 'warning' : 'default', when: run.indeterminate > 0 },
    { label: 'Pendentes', value: formatMonitoringCount(run.not_processed), mono: true, when: run.not_processed > 0 }
  ]
}

useMonitoringActions({ refresh, loading: isLoading })

const countClass = { th: 'whitespace-nowrap text-right', td: 'text-right tabular-nums' }

const columns: TableColumn<SerproSyncRun>[] = [
  { id: 'id', header: 'Execução', meta: { class: { th: 'w-28 whitespace-nowrap', td: '' } } },
  { id: 'state', header: 'Estado', meta: { class: { th: 'min-w-32 whitespace-nowrap', td: '' } } },
  { id: 'total', header: 'Total', meta: { class: countClass } },
  { id: 'synchronized', header: 'Sincronizados', meta: { class: countClass } },
  { id: 'skipped', header: 'Ignorados', meta: { class: countClass } },
  { id: 'failed', header: 'Falhos', meta: { class: countClass } },
  { id: 'indeterminate', header: 'Indeterminados', meta: { class: countClass } },
  { id: 'not_processed', header: 'Pendentes', meta: { class: countClass } },
  { id: 'started_at', header: 'Iniciada em', meta: { class: { th: 'whitespace-nowrap', td: 'tabular-nums' } } },
  { id: 'finished_at', header: 'Concluída em', meta: { class: { th: 'whitespace-nowrap', td: 'tabular-nums' } } },
  { id: 'actions', meta: { class: { th: 'w-12', td: 'w-12' } } }
]

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = [
  { id: 'id', label: 'Execução' },
  { id: 'state', label: 'Estado' },
  { id: 'total', label: 'Total' },
  { id: 'synchronized', label: 'Sincronizados' },
  { id: 'skipped', label: 'Ignorados' },
  { id: 'failed', label: 'Falhos' },
  { id: 'indeterminate', label: 'Indeterminados' },
  { id: 'not_processed', label: 'Pendentes' },
  { id: 'started_at', label: 'Iniciada em' },
  { id: 'finished_at', label: 'Concluída em' }
]

function runPath(run: SerproSyncRun) {
  return `/monitoring/execucoes/${run.id}`
}

const table = useTemplateRef<ComponentPublicInstance>('table')
const mobileList = useTemplateRef<HTMLElement>('mobileList')

onMounted(() => {
  for (const target of [computed(() => table.value?.$el ?? null), mobileList]) {
    useInfiniteScroll(target, () => loadMore(), {
      distance: 200,
      canLoadMore: () => canLoadMore.value
    })
  }
})
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div :class="sheetBodyClass">
      <ErrorRetryAlert
        v-if="showError"
        title="Não foi possível carregar as execuções"
        @retry="retry"
      />

      <UEmpty
        v-else-if="!isLoading && runs.length === 0"
        icon="i-lucide-refresh-cw"
        title="Nenhuma execução registrada"
        description="Nenhuma sincronização foi disparada para este escritório."
        variant="naked"
      />

      <template v-else>
        <!-- Mobile -->
        <div
          v-if="isLoading && runs.length === 0"
          class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto md:hidden"
        >
          <USkeleton v-for="index in 4" :key="index" class="h-32 w-full rounded-lg" />
        </div>

        <div
          v-else
          ref="mobileList"
          class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto md:hidden"
        >
          <UCard
            v-for="run in runs"
            :key="run.id"
            variant="subtle"
            :ui="{ body: 'p-0 sm:p-0' }"
          >
            <NuxtLink :to="runPath(run)" class="block p-4">
              <div class="flex items-start justify-between gap-3">
                <DataTableIdentity
                  :title="`Execução #${run.id}`"
                  :meta="`Concluída em ${formatMonitoringDate(run.finished_at)}`"
                />
                <UBadge
                  class="shrink-0"
                  variant="subtle"
                  :color="serproRunStatePresentation[run.state].color"
                  :icon="serproRunStatePresentation[run.state].icon"
                  :label="serproRunStatePresentation[run.state].label"
                />
              </div>
              <USeparator class="my-3" />
              <DataTableMetaList :items="runCounters(run)" columns="grid-cols-4 gap-x-3 text-xs" />
            </NuxtLink>
          </UCard>

          <div v-if="loadingMore" class="flex justify-center py-2">
            <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
          </div>
        </div>

        <!-- Desktop -->
        <div class="hidden min-h-0 min-w-0 flex-1 flex-col gap-3 md:flex">
          <div class="flex shrink-0 justify-end">
            <DataTableColumnMenu
              v-model="columnVisibility"
              :columns="hideableColumns"
              class="shrink-0"
            />
          </div>
          <UTable
            ref="table"
            v-model:column-visibility="columnVisibility"
            sticky
            :data="runs"
            :columns="columns"
            :loading="isLoading || loadingMore"
            class="h-full min-h-0 w-full flex-1"
            :ui="sheetTableUi"
          >
            <template #id-cell="{ row }">
              <NuxtLink
                :to="runPath(row.original)"
                class="font-medium text-highlighted tabular-nums hover:text-primary"
              >
                #{{ row.original.id }}
              </NuxtLink>
            </template>

            <template #state-cell="{ row }">
              <UBadge
                class="max-w-full"
                :color="serproRunStatePresentation[row.original.state].color"
                :icon="serproRunStatePresentation[row.original.state].icon"
                variant="subtle"
                :label="serproRunStatePresentation[row.original.state].label"
                :ui="{ base: 'max-w-full', label: 'truncate' }"
              />
            </template>

            <template #total-cell="{ row }">
              {{ formatMonitoringCount(row.original.total) }}
            </template>

            <template #synchronized-cell="{ row }">
              {{ formatMonitoringCount(row.original.synchronized) }}
            </template>

            <template #skipped-cell="{ row }">
              {{ formatMonitoringCount(row.original.skipped) }}
            </template>

            <template #failed-cell="{ row }">
              <span :class="row.original.failed > 0 ? 'text-error' : undefined">
                {{ formatMonitoringCount(row.original.failed) }}
              </span>
            </template>

            <template #indeterminate-cell="{ row }">
              <span :class="row.original.indeterminate > 0 ? 'text-warning' : undefined">
                {{ formatMonitoringCount(row.original.indeterminate) }}
              </span>
            </template>

            <template #not_processed-cell="{ row }">
              {{ formatMonitoringCount(row.original.not_processed) }}
            </template>

            <template #started_at-cell="{ row }">
              {{ formatMonitoringDate(row.original.started_at) }}
            </template>

            <template #finished_at-cell="{ row }">
              {{ formatMonitoringDate(row.original.finished_at) }}
            </template>

            <template #actions-cell="{ row }">
              <div class="text-right">
                <UButton
                  :to="runPath(row.original)"
                  icon="i-lucide-chevron-right"
                  color="neutral"
                  variant="ghost"
                  :aria-label="`Ver execução #${row.original.id}`"
                />
              </div>
            </template>
          </UTable>
        </div>
      </template>
    </div>
  </div>
</template>
