<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import DataTableIdentity from '~/components/data-table/Identity.vue'
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

const { data, status, error, refresh } = await useAsyncData<SerproSyncRunDetail | null>(
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

const isLoading = computed(() => status.value === 'pending')
const run = computed(() => data.value)
const items = computed(() => run.value?.items ?? [])
const runState = computed(() => (run.value ? serproRunStatePresentation[run.value.state] : null))

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar a execução', color: 'error' })
  }
}

watch(error, (value) => {
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar a execução', color: 'error' })
  }
})

const showError = computed(() => !!error.value && apiStatus(error.value) !== 404)

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
      await onRefresh()
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
  { id: 'client', header: 'Cliente' },
  { id: 'state', header: 'Estado' },
  { id: 'obligation', header: 'Obrigação' },
  { id: 'reason', header: 'Motivo' },
  { id: 'updated_at', header: 'Atualizado em' }
]
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 sm:p-6">
    <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <UButton
          to="/monitoring/execucoes"
          icon="i-lucide-arrow-left"
          color="neutral"
          variant="ghost"
          size="xs"
          label="Execuções"
          class="-ms-1"
        />
        <div class="mt-1 flex min-w-0 flex-wrap items-center gap-2">
          <h2 class="text-lg font-semibold text-highlighted">
            {{ run ? `Execução #${run.id}` : 'Execução' }}
          </h2>
          <UBadge
            v-if="runState"
            :color="runState.color"
            :icon="runState.icon"
            variant="subtle"
            :label="runState.label"
          />
        </div>
      </div>

      <UButton
        v-if="canManageClients && run"
        label="Reenviar sincronização"
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="outline"
        :loading="resyncing"
        @click="onResync"
      />
    </div>

    <UAlert
      v-if="showError"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar a execução"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <USkeleton v-else-if="isLoading" class="h-64 w-full" />

    <UEmpty
      v-else-if="!run"
      icon="i-lucide-file-question"
      title="Execução não encontrada"
      description="A execução pode ter sido removida ou o link está incorreto."
      variant="naked"
      :actions="[{ label: 'Ver execuções', icon: 'i-lucide-arrow-left', to: '/monitoring/execucoes' }]"
    />

    <template v-else>
      <UCard :ui="{ body: 'p-4 sm:p-5' }">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 lg:grid-cols-6">
          <div>
            <dt class="text-xs text-muted">
              Total
            </dt>
            <dd class="text-sm font-medium text-default tabular-nums">
              {{ formatMonitoringCount(run.total) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Sincronizados
            </dt>
            <dd class="text-sm font-medium text-default tabular-nums">
              {{ formatMonitoringCount(run.synchronized) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Ignorados
            </dt>
            <dd class="text-sm font-medium text-default tabular-nums">
              {{ formatMonitoringCount(run.skipped) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Falhos
            </dt>
            <dd class="text-sm font-medium text-default tabular-nums">
              {{ formatMonitoringCount(run.failed) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Iniciada em
            </dt>
            <dd class="text-sm text-default tabular-nums">
              {{ formatMonitoringDate(run.started_at) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">
              Concluída em
            </dt>
            <dd class="text-sm text-default tabular-nums">
              {{ formatMonitoringDate(run.finished_at) }}
            </dd>
          </div>
        </dl>
      </UCard>

      <UEmpty
        v-if="items.length === 0"
        icon="i-lucide-inbox"
        title="Nenhum cliente nesta execução"
        description="A execução não registrou nenhum item."
        variant="naked"
      />

      <UTable
        v-else
        :data="items"
        :columns="itemColumns"
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
          <span class="tabular-nums">{{ formatMonitoringDate(row.original.updated_at) }}</span>
        </template>
      </UTable>
    </template>
  </div>
</template>
