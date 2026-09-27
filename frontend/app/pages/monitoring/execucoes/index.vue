<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { sheetTableUi } from '~/components/data-table/sheet'
import { apiStatus } from '~/composables/useApiError'
import type { SerproSyncRun } from '~/types/serpro'
import {
  formatMonitoringCount,
  formatMonitoringDate,
  serproRunStatePresentation
} from '~/utils/monitoringPresentation'

definePageMeta({ middleware: 'auth' })

const toast = useToast()
const { syncRuns } = useSerpro()

/**
 * `getCachedData: () => undefined` is load-bearing, not a default. A run that
 * finished a minute ago would otherwise keep showing the history as it stood
 * before it, which is the one thing this screen exists to report.
 */
const { data, status, error, refresh } = await useAsyncData<{ data: SerproSyncRun[] }>(
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

const isLoading = computed(() => status.value === 'pending')
const runs = computed(() => data.value?.data ?? [])

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar as execuções', color: 'error' })
  }
}

watch(error, (value) => {
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar as execuções', color: 'error' })
  }
})

const showError = computed(() => !!error.value && apiStatus(error.value) !== 404)

const countClass = { th: 'text-right', td: 'text-right tabular-nums' }

const columns: TableColumn<SerproSyncRun>[] = [
  { id: 'id', header: 'Execução' },
  { id: 'state', header: 'Estado' },
  { id: 'total', header: 'Total', meta: { class: countClass } },
  { id: 'synchronized', header: 'Sincronizados', meta: { class: countClass } },
  { id: 'skipped', header: 'Ignorados', meta: { class: countClass } },
  { id: 'failed', header: 'Falhos', meta: { class: countClass } },
  { id: 'finished_at', header: 'Concluída em' }
]
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 sm:p-6">
    <div>
      <h2 class="text-lg font-semibold text-highlighted">
        Execuções de sincronização
      </h2>
      <p class="text-sm text-muted">
        Cada execução fica registrada com o que o provedor respondeu para cada cliente da carteira. Reenviar uma execução é uma ação à parte.
      </p>
    </div>

    <UAlert
      v-if="showError"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar as execuções"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <USkeleton v-else-if="isLoading" class="h-64 w-full" />

    <UEmpty
      v-else-if="runs.length === 0"
      icon="i-lucide-refresh-cw"
      title="Nenhuma execução registrada"
      description="Nenhuma sincronização foi disparada para este escritório."
      variant="naked"
    />

    <UTable
      v-else
      :data="runs"
      :columns="columns"
      :ui="sheetTableUi"
    >
      <template #id-cell="{ row }">
        <UButton
          :to="`/monitoring/execucoes/${row.original.id}`"
          :label="`#${row.original.id}`"
          color="primary"
          variant="ghost"
          size="xs"
          class="font-medium tabular-nums"
        />
      </template>

      <template #state-cell="{ row }">
        <UBadge
          :color="serproRunStatePresentation[row.original.state].color"
          :icon="serproRunStatePresentation[row.original.state].icon"
          variant="subtle"
          :label="serproRunStatePresentation[row.original.state].label"
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
        {{ formatMonitoringCount(row.original.failed) }}
      </template>

      <template #finished_at-cell="{ row }">
        <span class="tabular-nums">{{ formatMonitoringDate(row.original.finished_at) }}</span>
      </template>
    </UTable>
  </div>
</template>
