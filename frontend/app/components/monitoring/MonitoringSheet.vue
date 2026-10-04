<script setup lang="ts">
import { refDebounced, useInfiniteScroll } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { ComponentPublicInstance } from 'vue'
import { h, resolveComponent } from 'vue'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import type { FilterPanelColumn } from '~/utils/filterPanel'
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { sheetBodyClass, sheetTableUi } from '~/components/data-table/sheet'
import { apiStatus } from '~/composables/useApiError'
import type { ObligationListParams } from '~/composables/useSerpro'
import type { MonitoringClient, MonitoringMessageStub, MonitoringObligationSummary, MonitoringSituacao } from '~/types/serpro'
import { monitoringObligationUnserved, type MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringConsultedAt,
  formatMonitoringDueOn,
  isMonitoringSlipColumn,
  latestSlipFor,
  monitoringActions,
  monitoringAttentionReasonPresentation,
  monitoringCategoryPresentation,
  monitoringEmpty,
  monitoringFilters,
  monitoringMissingValue,
  monitoringProvenance,
  monitoringProvenanceLabels,
  monitoringSituacaoMissingPresentation,
  monitoringSituacaoPresentation,
  monitoringSlipColumnValue,
  monitoringSlipMissingPresentation,
  monitoringSlipStatusPresentation,
  monitoringStalePresentation
} from '~/utils/monitoringPresentation'
import AssociateClientsModal from '~/components/monitoring/AssociateClientsModal.vue'
import ManualSearchModal from '~/components/monitoring/ManualSearchModal.vue'
import MessageDetail from '~/components/monitoring/MessageDetail.vue'
import MessageStubSummary from '~/components/monitoring/MessageStubSummary.vue'
import ObligationActionMenu from '~/components/monitoring/ObligationActionMenu.vue'
import ObligationCounters from '~/components/monitoring/ObligationCounters.vue'

const props = defineProps<{
  obligation: MonitoringObligation
  situacao: MonitoringSituacao | null
}>()

const toast = useToast()
const { canManageClients } = useAuth()
const { listObligation } = useSerpro()
const { listTags } = useClients()

const associateOpen = ref(false)

/**
 * The bulk search: the rows checked in the table travel to the modal as a
 * starting selection. `rowSelection` is TanStack's model — keys are the row id
 * as a string, and a client id is the only stable one a row has.
 */
const searchOpen = ref(false)
const searchPreselected = ref<number[]>([])
const rowSelection = ref<Record<string, boolean>>({})

const selectedClientIds = computed(() =>
  Object.entries(rowSelection.value)
    .filter(([, value]) => value)
    .map(([key]) => Number(key))
    .filter(id => Number.isInteger(id))
)

function openSearch() {
  if (!canManageClients.value || isUnserved.value) return
  searchPreselected.value = selectedClientIds.value
  searchOpen.value = true
}

const search = ref('')
/** The house debounce is 350 ms — never a request per keystroke. */
const debouncedSearch = refDebounced(search, 350)
const tagFilter = ref<number[]>([])
const page = ref(1)

function emptySummary(obligation: MonitoringObligation): MonitoringObligationSummary {
  return {
    obligation: obligation.slug,
    category: obligation.category,
    total: 0,
    em_dia: 0,
    processando: 0,
    pendencias: 0,
    atencao: 0,
    encerrado: 0,
    nao_consultadas: 0,
    progress: null,
    current_page: 1,
    attention_reasons: []
  }
}

/**
 * Typed at the boundary so `situacao` keeps the backend's own vocabulary.
 *
 * `page` is deliberately **not** in `params`. `params` is what `useAsyncData`
 * watches, so a `page` here would mean `loadMore`'s own `page.value = nextPage`
 * re-triggers the fetch and replaces the accumulated list with the last page
 * alone — and a filter or counter navigation would then request page N of a new
 * query. The `watch(data)` handler is the single owner of `page`, so it always
 * reflects the last page actually fetched, which is 1 after every refilter.
 */
const params = computed<ObligationListParams>(() => ({
  situacao: props.situacao ?? '',
  q: debouncedSearch.value.trim(),
  tag_id: tagFilter.value.length ? tagFilter.value : undefined
}))

const listKey = computed(() => `serpro-monitoring-${props.obligation.slug}-${props.situacao ?? 'todas'}`)

/**
 * Above the `useAsyncData` call, and that ordering is load-bearing.
 *
 * `useAsyncData` invokes its handler **synchronously** during `await`, on both
 * entry paths (server at `asyncData.js:85-86`, client navigation at `:112`, both
 * through `initialFetch` → `execute` → the promise executor at `:348`). So every
 * `const` the handler reads has to be initialized before the call: declared
 * below it, `isUnserved.value` throws a temporal-dead-zone `ReferenceError` inside
 * the async function, which becomes a rejected promise, and `asyncData.js:368-377`
 * swallows it — `error.value` is set, `data` falls back to the `default`, and
 * setup does not throw, so nothing is logged as a crash. The result is the worst
 * of every state: no request is issued and `data` is `emptySummary()` — a clean,
 * plausible, entirely false "nothing needs anything", the failure having arrived
 * before the handler could even reach the `try` that turns a 404 into an empty
 * envelope. That is also why nothing below may own the failure flag: the
 * composable that owns it is created after this call, so the handler could only
 * read it inside that same window.
 *
 * `summary` and `isLoading` stay below: they read `data`, which only exists after
 * the call. TypeScript does not catch the other order — a closure boundary hides
 * the use-before-declaration — and neither does a green unit suite.
 */
const isUnserved = computed(() => monitoringObligationUnserved(props.obligation))
const category = computed(() => monitoringCategoryPresentation[props.obligation.category])
/** What a `derived` obligation projects over; `null` for anything else. */
const provenance = computed(() => monitoringProvenance(props.obligation))

const { data, status, error, refresh: reload } = await useAsyncData(listKey, async () => {
  if (isUnserved.value) {
    return { data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }
  }
  try {
    return await listObligation(props.obligation.slug, params.value)
  } catch (error) {
    // The obligation slug comes from the registry and is never typed, so a 404
    // means "no data", never "wrong URL". Answering with an empty envelope is
    // honest in both worlds — the endpoint not existing yet, or nothing to show.
    if (apiStatus(error) === 404) return { data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }
    throw error
  }
}, { watch: [params], default: () => ({ data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }) })

/**
 * The unserved variant arrives with every counter `null` — that is the API
 * saying the provider never answers for this obligation, and the sheet never
 * fetches it (`isUnserved` short-circuits the handler). The guard still
 * narrows the union: what reaches `ObligationCounters` is the served summary,
 * where each counter is a number.
 */
const summary = computed<MonitoringObligationSummary>(() => {
  const loaded = data.value?.data
  return loaded === null || loaded === undefined || loaded.total === null
    ? emptySummary(props.obligation)
    : loaded
})

const rows = ref<MonitoringClient[]>([])
const total = ref(0)
const loadingMore = ref(false)
let generation = 0

watch(data, (value) => {
  generation += 1
  rows.value = value?.data_rows ?? []
  total.value = value?.data.total ?? 0
  page.value = value?.data.current_page ?? 1
  // The checked rows belonged to the list as it stood; a refilter or a refresh
  // replaced that list, and a selection that survives it would carry ids the
  // operator can no longer see.
  rowSelection.value = {}
}, { immediate: true })

async function loadMore() {
  if (loadingMore.value || status.value === 'pending') return
  if (rows.value.length >= total.value) return

  const seen = generation
  const nextPage = page.value + 1
  loadingMore.value = true
  try {
    const response = await listObligation(props.obligation.slug, { ...params.value, page: nextPage })
    // Two fast filter changes must not interleave and append a stale page.
    if (seen !== generation) return
    const known = new Set(rows.value.map(row => row.client_id))
    const fresh = (response.data_rows ?? []).filter(row => !known.has(row.client_id))
    if (!fresh.length) {
      total.value = rows.value.length
      return
    }
    rows.value = [...rows.value, ...fresh]
    page.value = nextPage
  } catch {
    toast.add({ title: 'Não foi possível carregar mais clientes', color: 'error' })
  } finally {
    loadingMore.value = false
  }
}

const canLoadMore = computed(() =>
  status.value !== 'pending'
  && !loadingMore.value
  && rows.value.length > 0
  && rows.value.length < total.value
)

const { data: tagCatalog } = await useAsyncData('serpro-monitoring-tags', () => listTags())

const filterColumns = computed<FilterPanelColumn[]>(() => [
  {
    id: 'tag_id',
    label: 'Tags',
    icon: 'i-lucide-tags',
    control: 'multi',
    options: (tagCatalog.value?.data ?? []).map(tag => ({ label: tag.name, value: String(tag.id) }))
  }
])

const filterModels = computed<DataTableFilterModel[]>(() => {
  if (!tagFilter.value.length) return []
  return [{
    columnId: 'tag_id',
    type: 'multiOption',
    operator: tagFilter.value.length > 1 ? 'include any of' : 'include',
    values: tagFilter.value.map(String)
  }]
})

function onFilters(models: DataTableFilterModel[]) {
  tagFilter.value = (models.find(model => model.columnId === 'tag_id')?.values ?? []).map(Number).filter(Number.isFinite)
}

/**
 * The situation is the list's identity — it is in the route, and a member
 * follows it as a link — so it is not one of the filters a member applied to a
 * list, and counting it here would offer "Limpar filtros" on a button that
 * cannot clear anything.
 */
const hasActiveFilters = computed(() => !!debouncedSearch.value.trim() || tagFilter.value.length > 0)

function clearFilters() {
  search.value = ''
  tagFilter.value = []
}

/**
 * `ignoreStatus: 404` — a 404 must never reach the error alert: it is the inert
 * state, not a failure, and the loader above already answers it with an empty
 * envelope.
 *
 * `sticky` because the alert is fatal, not a toast over live content: a
 * `watch: [params]` refilter re-runs the handler, and `useAsyncData` clears
 * `error` on the next success — without the sticky form the alert would blink out
 * and the empty summary behind it would flash back as if it had loaded. The
 * operator clears it by retrying, which is the only thing that proves the load
 * works.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar a lista',
  refreshErrorTitle: 'Não foi possível atualizar a lista',
  ignoreStatus: 404,
  sticky: true
})

/**
 * The navbar buttons live in `pages/monitoring.vue`. Associating is offered
 * only inside a served obligation and to a role the backend accepts — the shell
 * hides the button on the same conditions, and this guard keeps a stale request
 * from opening a picker the page does not mount.
 */
useMonitoringActions({
  refresh,
  loading: isLoading,
  associate: () => {
    if (canManageClients.value && !isUnserved.value) associateOpen.value = true
  }
})

const UCheckbox = resolveComponent('UCheckbox')

/**
 * The obligation's own declared columns, then — for whoever can manage clients
 * — the selection column that feeds the bulk search. `accessorFn`, not
 * `accessorKey`. The per-obligation values live under `row.fields[id]`, so a
 * flat `accessorKey: column.id` resolves to `undefined` for every column that
 * is not `name` or `situacao` and the desktop table renders blanks — the
 * mobile card reads the same values through `fieldValue`, so the two layouts
 * would disagree about what the source delivered. One accessor makes the
 * default cell agree with the card.
 *
 * `meta.class` stays the `{ th, td }` object @nuxt/ui v4 reads (it resolves
 * `class.th`/`class.td`, nothing else), and a numeric column declares **both**,
 * so the header label shares the right edge of the figures under it. A `th`
 * only entry would left-align the heading over right-aligned numbers.
 */
const columns = computed<TableColumn<MonitoringClient>[]>(() => {
  const list: TableColumn<MonitoringClient>[] = props.obligation.columns.map(column => ({
    id: column.id,
    accessorFn: (row: MonitoringClient) => fieldValue(row, column.id),
    header: column.header,
    meta: {
      class: column.numeric
        ? { th: 'whitespace-nowrap text-right', td: 'text-right tabular-nums' }
        : { th: column.id === 'name' ? 'min-w-64 whitespace-nowrap' : 'whitespace-nowrap', td: '' }
    }
  }))
  if (canManageClients.value) {
    list.unshift({
      id: 'select',
      enableSorting: false,
      enableHiding: false,
      meta: { class: { th: 'w-10', td: 'w-10' } },
      header: ({ table }) => h(UCheckbox, {
        'modelValue': table.getIsSomePageRowsSelected() ? 'indeterminate' : table.getIsAllPageRowsSelected(),
        'onUpdate:modelValue': (value: boolean | 'indeterminate') => table.toggleAllPageRowsSelected(value === true),
        'ariaLabel': 'Selecionar todos os clientes visíveis'
      }),
      cell: ({ row }) => h(UCheckbox, {
        'modelValue': row.getIsSelected(),
        'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(value === true),
        'ariaLabel': `Selecionar ${row.original.name}`
      })
    })
  }
  return list
})

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = computed(() =>
  props.obligation.columns.map(column => ({ id: column.id, label: column.header }))
)

const detailFields = computed(() => props.obligation.columns.filter(column => column.id !== 'name' && column.id !== 'situacao'))

/**
 * The obligation's own declared columns, as facts. Every one of them is a
 * recorded reading — a value, a period, a status — so every one of them is
 * tabular and clamps to one line: the card is a summary the office scans, not
 * the place a long text is read.
 */
function detailFacts(row: MonitoringClient): MetaListItem[] {
  return detailFields.value.map(field => ({
    label: field.header,
    value: fieldValue(row, field.id),
    mono: true,
    truncate: true
  }))
}

function fieldValue(row: MonitoringClient, id: string) {
  if (id === 'name') return row.name
  if (id === 'situacao') return row.situacao === null
    ? monitoringSituacaoMissingPresentation.label
    : monitoringSituacaoPresentation[row.situacao].label
  if (id === 'due_on') return formatMonitoringDueOn(row.due_on)
  // The last consultation is the row's own stamp (`source_at`), not a provider
  // field: read from the row and never from `fields`, or the cell would
  // resolve to a key the backend never populates.
  if (id === 'ultima_consulta') return formatMonitoringConsultedAt(row.consulted_at)
  // The guide columns come from the periods already synchronized for the row,
  // not from `fields`: an opaque provider string could not say which period it
  // belonged to or whether it was paid. Handled before the `fields` lookup, or
  // they would resolve to a key the backend never populates.
  if (isMonitoringSlipColumn(id)) return monitoringSlipColumnValue(id, row.periods)
  const value = row.fields[id]
  return value == null || value === '' ? monitoringMissingValue : String(value)
}

/**
 * The row's situation, refined by its named cause when it is `atencao`.
 *
 * A row the provider never answered has no situation at all: the badge is the
 * em dash, and "Última consulta" is what says why. The cause's colour and icon
 * too, not the aggregate's — one severity per row, whichever the office reads.
 */
function situacaoPresentation(row: MonitoringClient) {
  if (row.situacao === null) return monitoringSituacaoMissingPresentation
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao]
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label)
}

/**
 * The derived guide status for the desktop badge. `null` becomes the em dash
 * presentation rather than "Sem guia": a row with no synchronized period has not
 * been told it owes nothing.
 */
function slipPresentation(row: MonitoringClient) {
  const slip = latestSlipFor(row.periods)
  return slip ? monitoringSlipStatusPresentation[slip.status] : monitoringSlipMissingPresentation
}

async function afterAssociate() {
  // The modal decides whether it closes — a per-row add must not, so the next
  // one is a click away. The counters behind it are stale either way.
  await refresh()
}

/**
 * The requested rows leave the selection and the list reloads: the backend
 * marks them "Processando" while the manual searches run, so the counters and
 * the situation badges behind the modal are stale the moment it closes.
 */
async function afterSearch() {
  rowSelection.value = {}
  await refresh()
}

/**
 * The mailbox's one legal act. The sheet holds the row and opens the detail; it
 * never fetches a body. `MessageDetail` asks for the consent first and is the
 * only caller of `readMessage`, because opening the dialog is not the act.
 */
const messageOpen = ref(false)
const messageStub = ref<MonitoringMessageStub | null>(null)
const messageClientName = ref('')
const messageClientId = ref(0)

function openMessage(row: MonitoringClient) {
  if (!row.message) return
  messageClientName.value = row.name
  messageClientId.value = row.client_id
  messageStub.value = row.message
  messageOpen.value = true
}

/** Reading is done: the row, its unread count and the counters all moved. */
async function afterRead() {
  await refresh()
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

const showCounters = computed(() => !isUnserved.value && !showError.value)
const showEmpty = computed(() => !isLoading.value && rows.value.length === 0)
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!--
      Gated on the real failure, and on nothing else. A 404 is the inert
      state, so the exemption stays; but under a `500` the alert below
      announces the failure while these readings would render `emptySummary`'s
      zeros — the page would claim nothing needs attention at the same moment
      as saying it could not load.
    -->
    <ObligationCounters
      v-if="showCounters"
      mode="toolbar"
      :obligation="obligation"
      :summary="summary"
      :situacao="situacao"
    >
      <template v-if="canManageClients && !isUnserved" #actions>
        <ObligationActionMenu
          :selected-count="selectedClientIds.length"
          @search="openSearch"
          @associate="associateOpen = true"
        />
      </template>
    </ObligationCounters>

    <div :class="sheetBodyClass">
      <ObligationCounters
        v-if="showCounters"
        mode="chips"
        :obligation="obligation"
        :summary="summary"
        :situacao="situacao"
      >
        <template v-if="canManageClients && !isUnserved" #actions>
          <ObligationActionMenu
            compact
            :selected-count="selectedClientIds.length"
            @search="openSearch"
            @associate="associateOpen = true"
          />
        </template>
      </ObligationCounters>

      <!--
        The provenance of a `derived` obligation, above the readings it qualifies.
        Without it the office reads a projection as an independent source, which
        is the one thing the classification exists to prevent.
      -->
      <p
        v-if="provenance"
        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted"
      >
        <UBadge
          size="sm"
          variant="subtle"
          :color="category.color"
          :icon="category.icon"
          :label="category.label"
        />
        <span v-if="provenance.origin">
          {{ monitoringProvenanceLabels.origin }}: {{ provenance.origin }}.
        </span>
        <span v-if="provenance.service">
          {{ monitoringProvenanceLabels.service }}: {{ provenance.service }}.
        </span>
      </p>

      <UAlert
        v-if="isUnserved"
        :color="category.color"
        variant="subtle"
        :icon="category.icon"
        :title="category.label"
        :description="category.description"
      />

      <template v-else>
        <!--
          Inside the served branch, deliberately, and outside the
          loading/error/table chain below so a refilter cannot unmount an open
          picker. An unserved obligation (`declaracoes/dirf`) has nothing to
          associate clients to, and a picker mounted beside the chain would be
          one edit away from offering to attach clients to an obligation that
          does not exist.
        -->
        <AssociateClientsModal
          v-if="canManageClients"
          v-model:open="associateOpen"
          :obligation="obligation"
          :associated-ids="rows.map(row => row.client_id)"
          @associated="afterAssociate"
        />

        <!--
          The bulk manual search, beside the associate picker for the same
          reason the picker is here: inside the served branch, outside the
          loading chain, so a refilter cannot unmount an open modal.
        -->
        <ManualSearchModal
          v-if="canManageClients"
          v-model:open="searchOpen"
          :obligation="obligation"
          :associated-ids="rows.map(row => row.client_id)"
          :selected-ids="searchPreselected"
          @requested="afterSearch"
        />

        <DataTableFilterPanel
          :columns="filterColumns"
          :model-value="filterModels"
          :disabled="isLoading"
          class="min-w-0"
          @update:model-value="onFilters"
        >
          <UInput
            v-model="search"
            icon="i-lucide-search"
            :placeholder="monitoringFilters.search"
            class="w-full min-w-0 flex-1"
            :disabled="isLoading"
          />
          <template #trailing>
            <DataTableColumnMenu
              v-model="columnVisibility"
              :columns="hideableColumns"
              class="hidden shrink-0 md:flex"
            />
          </template>
        </DataTableFilterPanel>

        <ErrorRetryAlert
          v-if="showError"
          title="Não foi possível carregar esta obrigação"
          @retry="retry"
        />

        <template v-else>
          <!-- Mobile: cards, not windowed — their height varies with the message block. -->
          <div
            v-if="isLoading && rows.length === 0"
            class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto md:hidden"
          >
            <USkeleton v-for="index in 4" :key="index" class="h-40 w-full rounded-lg" />
          </div>

          <div
            v-else-if="rows.length"
            ref="mobileList"
            class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto md:hidden"
          >
            <UCard
              v-for="row in rows"
              :key="row.client_id"
              variant="subtle"
              :ui="{ root: 'overflow-visible', body: 'p-4' }"
            >
              <div class="flex items-start gap-3">
                <DataTableIdentity
                  class="flex-1"
                  :title="row.name"
                  :meta="row.tax_id ?? ''"
                  :truncate="false"
                />
                <div class="flex shrink-0 flex-col items-end gap-1">
                  <UBadge
                    variant="subtle"
                    :color="situacaoPresentation(row).color"
                    :icon="situacaoPresentation(row).icon"
                    :label="situacaoPresentation(row).label"
                  />
                  <UBadge
                    v-if="row.stale"
                    size="sm"
                    variant="subtle"
                    :color="monitoringStalePresentation.color"
                    :icon="monitoringStalePresentation.icon"
                    :label="monitoringStalePresentation.label"
                  />
                </div>
              </div>

              <template v-if="detailFields.length">
                <USeparator class="my-3" />
                <DataTableMetaList :items="detailFacts(row)" columns="grid-cols-2 gap-x-4 gap-y-3" />
              </template>

              <div
                v-if="row.message"
                class="mt-3 rounded-lg bg-default p-3 ring ring-default"
              >
                <MessageStubSummary
                  :message="row.message"
                  :client-name="row.name"
                  @open="openMessage(row)"
                />
              </div>
            </UCard>

            <div v-if="loadingMore" class="flex justify-center py-2">
              <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
            </div>
          </div>

          <!-- Desktop -->
          <div
            v-if="rows.length || isLoading"
            class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex"
          >
            <UTable
              ref="table"
              v-model:column-visibility="columnVisibility"
              v-model:row-selection="rowSelection"
              sticky
              :data="rows"
              :columns="columns"
              :loading="isLoading || loadingMore"
              :row-selection-options="{ enableRowSelection: true }"
              :get-row-id="(row: MonitoringClient) => String(row.client_id)"
              class="h-full min-h-0 w-full flex-1"
              :ui="sheetTableUi"
            >
              <!--
                The mailbox's one legal act lives in the `name` cell because
                `name` is the only column every served obligation declares:
                `ultima` is declared by `caixas-postais/e-cac` alone, so a slot
                on it would leave the two derived mailbox pages unable to open a
                message on desktop while the phone card could.
              -->
              <template #name-cell="{ row }">
                <div class="flex min-w-0 flex-col gap-1">
                  <DataTableIdentity :title="row.original.name" :meta="row.original.tax_id ?? ''" />
                  <MessageStubSummary
                    v-if="row.original.message"
                    :message="row.original.message"
                    :client-name="row.original.name"
                    @open="openMessage(row.original)"
                  />
                </div>
              </template>

              <!--
                The guide carries a severity the plain cell cannot: a slip
                issued and unpaid is a warning, and a slip paid is not. Read
                from the row's periods, never from `fields`.
              -->
              <template #guia-cell="{ row }">
                <UBadge
                  class="max-w-full"
                  :color="slipPresentation(row.original).color"
                  :icon="slipPresentation(row.original).icon"
                  variant="subtle"
                  :label="slipPresentation(row.original).label"
                  :ui="{ base: 'max-w-full', label: 'truncate' }"
                />
              </template>

              <template #situacao-cell="{ row }">
                <div class="flex flex-wrap items-center gap-1.5">
                  <UBadge
                    class="max-w-full"
                    :color="situacaoPresentation(row.original).color"
                    :icon="situacaoPresentation(row.original).icon"
                    variant="subtle"
                    :label="situacaoPresentation(row.original).label"
                    :ui="{ base: 'max-w-full', label: 'truncate' }"
                  />
                  <UBadge
                    v-if="row.original.stale"
                    size="sm"
                    variant="subtle"
                    :color="monitoringStalePresentation.color"
                    :icon="monitoringStalePresentation.icon"
                    :label="monitoringStalePresentation.label"
                  />
                </div>
              </template>
            </UTable>
          </div>

          <UEmpty
            v-if="showEmpty && !hasActiveFilters"
            icon="i-lucide-inbox"
            :title="monitoringEmpty.noClients"
            :description="monitoringEmpty.noClientsDescription"
            variant="naked"
            :actions="canManageClients
              ? [{ label: monitoringActions.associate, icon: 'i-lucide-user-plus', onClick: () => { associateOpen = true } }]
              : undefined"
          />

          <UEmpty
            v-else-if="showEmpty"
            icon="i-lucide-search-x"
            :title="monitoringEmpty.noResults"
            :description="monitoringEmpty.noResultsDescription"
            variant="naked"
            :actions="[{ label: 'Limpar filtros', color: 'neutral', variant: 'outline', onClick: clearFilters }]"
          />
        </template>
      </template>
    </div>

    <!--
      Outside the served branch, and the `v-if="messageStub"` is what makes that
      safe — not the branch. A stub can only exist once a row was opened, and a
      row requires `rows`, which requires a served obligation.

      Which depends on the `:key` in `pages/monitoring/[...slug].vue`: the
      remount on an obligation or situation change is what clears `messageStub`.
      Remove that key and the reactive-key carry-forward in `useAsyncData` brings
      the stale dialog straight back — a message body from the previous
      obligation's row, under the new obligation's name, including for one the
      provider does not serve. The stub guard is real; the key is what keeps it
      true.
    -->
    <MessageDetail
      v-if="messageStub"
      v-model:open="messageOpen"
      :obligation="obligation"
      :client-name="messageClientName"
      :client-id="messageClientId"
      :stub="messageStub"
      @read="afterRead"
    />
  </div>
</template>
