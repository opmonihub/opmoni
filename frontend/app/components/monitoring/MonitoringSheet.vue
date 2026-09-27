<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/Filter.vue'
import { sheetBodyClass, sheetTableUi, sheetToolbarUi } from '~/components/data-table/sheet'
import { apiStatus } from '~/composables/useApiError'
import type { ObligationListParams } from '~/composables/useSerpro'
import type { MonitoringClient, MonitoringMessageStub, MonitoringObligationSummary, MonitoringSituacao } from '~/types/serpro'
import { monitoringObligationUnserved, type MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringDate,
  formatMonitoringDueOn,
  isMonitoringSlipColumn,
  latestSlipFor,
  monitoringAttentionReasonPresentation,
  monitoringCategoryPresentation,
  monitoringDeadlinePassed,
  monitoringMissingValue,
  monitoringProvenance,
  monitoringProvenanceLabels,
  monitoringSituacaoPresentation,
  monitoringSlipColumnValue,
  monitoringSlipMissingPresentation,
  monitoringSlipStatusPresentation,
  monitoringStalePresentation
} from '~/utils/monitoringPresentation'
import AssociateClientsModal from '~/components/monitoring/AssociateClientsModal.vue'
import MessageDetail from '~/components/monitoring/MessageDetail.vue'
import ObligationCounters from '~/components/monitoring/ObligationCounters.vue'

const props = defineProps<{
  obligation: MonitoringObligation
  situacao: MonitoringSituacao | null
}>()

const emit = defineEmits<{ refreshed: [] }>()

const toast = useToast()
const { canManageClients } = useAuth()
const { listObligation } = useSerpro()
const { listTags } = useClients()

/** A 404 must never reach the error alert: it is the inert state, not a failure. */
const failed = ref(false)
const associateOpen = ref(false)

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
 * of every state: no request is issued, `data` is `emptySummary()`, `isLoading` is
 * false, and because the throw happens before the `try`, `failed` stays false — a
 * clean, plausible, entirely false "nothing needs anything" with no message at all.
 *
 * `summary` and `isLoading` stay below: they read `data`, which only exists after
 * the call. TypeScript does not catch the other order — a closure boundary hides
 * the use-before-declaration — and neither does a green unit suite.
 */
const isUnserved = computed(() => monitoringObligationUnserved(props.obligation))
const category = computed(() => monitoringCategoryPresentation[props.obligation.category])
/** What a `derived` obligation projects over; `null` for anything else. */
const provenance = computed(() => monitoringProvenance(props.obligation))

const { data, status, error, refresh } = await useAsyncData(listKey, async () => {
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
    failed.value = true
    throw error
  }
}, { watch: [params], default: () => ({ data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }) })

const summary = computed(() => data.value?.data ?? emptySummary(props.obligation))
const isLoading = computed(() => status.value === 'pending')

const rows = ref<MonitoringClient[]>([])
const total = ref(0)
const loadingMore = ref(false)
let generation = 0

watch(data, (value) => {
  generation += 1
  rows.value = value?.data_rows ?? []
  total.value = value?.data.total ?? 0
  page.value = value?.data.current_page ?? 1
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

const canLoadMore = computed(() => rows.value.length > 0 && rows.value.length < total.value)

const { data: tagCatalog } = await useAsyncData('serpro-monitoring-tags', () => listTags())

const filterColumns = computed<DataTableFilterColumn[]>(() => [
  { id: 'q', label: 'Busca', icon: 'i-lucide-search', type: 'text' },
  {
    id: 'tag_id',
    label: 'Tags',
    icon: 'i-lucide-tags',
    type: 'multiOption',
    options: (tagCatalog.value?.data ?? []).map(tag => ({ label: tag.name, value: String(tag.id) }))
  }
])

const filterModels = computed<DataTableFilterModel[]>(() => {
  const models: DataTableFilterModel[] = []
  if (debouncedSearch.value.trim()) models.push({ columnId: 'q', type: 'text', operator: 'contains', values: [debouncedSearch.value.trim()] })
  if (tagFilter.value.length) models.push({ columnId: 'tag_id', type: 'multiOption', operator: 'include', values: tagFilter.value.map(String) })
  return models
})

function onFilters(models: DataTableFilterModel[]) {
  search.value = String(models.find(model => model.columnId === 'q')?.values[0] ?? '')
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

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar a lista', color: 'error' })
  }
}

watch(error, (value) => {
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar a lista', color: 'error' })
  }
})

/**
 * `accessorFn`, not `accessorKey`. The per-obligation values live under
 * `row.fields[id]`, so a flat `accessorKey: column.id` resolves to `undefined`
 * for every column that is not `name` or `situacao` and the desktop table
 * renders blanks — the mobile card reads the same values through
 * `fieldValue`, so the two layouts would disagree about what the source
 * delivered. One accessor makes the default cell agree with the card.
 *
 * `meta.class` stays the `{ th, td }` object @nuxt/ui v4 reads (it resolves
 * `class.th`/`class.td`, nothing else), and a numeric column declares **both**,
 * so the header label shares the right edge of the figures under it. A `th`
 * only entry would left-align the heading over right-aligned numbers.
 */
const columns = computed<TableColumn<MonitoringClient>[]>(() =>
  props.obligation.columns.map(column => ({
    id: column.id,
    accessorFn: (row: MonitoringClient) => fieldValue(row, column.id),
    header: column.header,
    meta: { class: column.numeric ? { th: 'text-right', td: 'text-right tabular-nums' } : undefined }
  }))
)

const detailFields = computed(() => props.obligation.columns.filter(column => column.id !== 'name' && column.id !== 'situacao'))

function fieldValue(row: MonitoringClient, id: string) {
  if (id === 'name') return row.name
  if (id === 'situacao') return monitoringSituacaoPresentation[row.situacao].label
  if (id === 'due_on') return formatMonitoringDueOn(row.due_on)
  // The guide columns come from the periods already synchronized for the row,
  // not from `fields`: an opaque provider string could not say which period it
  // belonged to or whether it was paid. Handled before the `fields` lookup, or
  // they would resolve to a key the backend never populates.
  if (isMonitoringSlipColumn(id)) return monitoringSlipColumnValue(id, row.periods)
  const value = row.fields[id]
  return value == null || value === '' ? monitoringMissingValue : String(value)
}

/** The row's situation, refined by its named cause when it is `atencao`. */
function situacaoLabel(row: MonitoringClient) {
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao].label
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label).label
}

/**
 * The cause's colour, not the aggregate's. A `sem_declaracao` row is an
 * `atencao` counter, so the situation's own colour is `error` — while the label
 * and the icon on the same badge both resolve the cause and say `warning`. One
 * severity per row: whichever of the two the office reads, they have to agree.
 */
function situacaoColor(row: MonitoringClient) {
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao].color
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label).color
}

function situacaoIcon(row: MonitoringClient) {
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao].icon
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label).icon
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
  await onRefresh()
  emit('refreshed')
}

/**
 * The mailbox's one legal act. The sheet holds the row and opens the detail; it
 * never fetches a body. `MessageDetail` asks for the consent first and is the
 * only caller of `readMessage`, because opening the dialog is not the act.
 */
const messageOpen = ref(false)
const messageStub = ref<MonitoringMessageStub | null>(null)
const messageClientName = ref('')

function openMessage(row: MonitoringClient) {
  if (!row.message) return
  messageClientName.value = row.name
  messageStub.value = row.message
  messageOpen.value = true
}

/** An office that has missed a deadline has to see that it missed one. */
function prazoPresentation(message: MonitoringMessageStub) {
  const passed = monitoringDeadlinePassed(message.prazo_limite)
  return {
    label: `${passed ? 'Prazo vencido em' : 'Prazo'} ${formatMonitoringDate(message.prazo_limite)}`,
    class: passed ? 'font-medium text-error' : 'text-muted'
  }
}

/** The same wording on both layouts, so a phone does not read as a different act. */
function messageAction(message: MonitoringMessageStub, clientName: string) {
  const label = message.ciencia_em ? 'Ver mensagem' : 'Abrir mensagem'
  return { label, ariaLabel: `${label}: ${message.assunto} (${clientName})` }
}

/** Reading is done: the row, its unread count and the counters all moved. */
async function afterRead() {
  await onRefresh()
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <UDashboardToolbar class="hidden min-w-0 md:flex" :ui="sheetToolbarUi">
      <template #left>
        <div class="min-w-0 flex-1">
          <!--
            Gated on the real failure, and on nothing else. A 404 is the inert
            state, so the exemption stays; but under a `500` the alert below
            announces the failure while these five readings would render
            `emptySummary`'s zeros — the page would claim nothing needs attention
            at the same moment as saying it could not load. Same reason the
            overview hides its whole body behind `showError`.
          -->
          <ObligationCounters
            v-if="!isUnserved && !(error && failed)"
            :obligation="obligation"
            :summary="summary"
            :situacao="situacao"
            @associate="associateOpen = true"
          />
        </div>
      </template>
    </UDashboardToolbar>

    <div :class="sheetBodyClass">
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

      <template v-if="isUnserved">
        <UAlert
          :color="category.color"
          variant="subtle"
          :icon="category.icon"
          :title="category.label"
          :description="category.description"
        />
      </template>

      <template v-else>
        <!-- Same gate as the desktop strip, and it is already inside the served
             branch, so `isUnserved` is not repeated here. -->
        <div
          v-if="!(error && failed)"
          class="md:hidden"
        >
          <ObligationCounters
            :obligation="obligation"
            :summary="summary"
            :situacao="situacao"
            @associate="associateOpen = true"
          />
        </div>

        <!--
          Inside the served branch, deliberately, and outside the
          loading/error/table chain below so a refilter cannot unmount an open
          picker. An unserved obligation (`declaracoes/dirf`) has nothing to
          associate clients to, and a picker mounted beside the chain would be
          one edit away from offering to attach clients to an obligation that
          does not exist. Both triggers for it — the counter strip and the empty
          state — are in here too, so the mount cannot outlive its own triggers.
        -->
        <AssociateClientsModal
          v-if="canManageClients"
          v-model:open="associateOpen"
          :obligation="obligation"
          :associated-ids="rows.map(row => row.client_id)"
          @associated="afterAssociate"
        />

        <UAlert
          v-if="error && failed"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Não foi possível carregar esta obrigação"
          description="Verifique sua conexão e tente novamente."
          :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
        />

        <USkeleton v-else-if="isLoading && rows.length === 0" class="h-64 w-full" />

        <template v-else>
          <DataTableFilter
            :columns="filterColumns"
            :model-value="filterModels"
            :disabled="isLoading"
            class="min-w-0"
            @update:model-value="onFilters"
          >
            <UInput
              v-model="search"
              icon="i-lucide-search"
              placeholder="Buscar por nome ou CNPJ"
              class="min-w-0 flex-1"
              :disabled="isLoading"
            />
          </DataTableFilter>

          <UEmpty
            v-if="rows.length === 0 && !hasActiveFilters"
            icon="i-lucide-inbox"
            title="Nenhum cliente nesta obrigação"
            description="Nenhum cliente da carteira tem registro sincronizado para esta obrigação."
            variant="naked"
            :actions="canManageClients
              ? [{ label: 'Adicionar clientes', icon: 'i-lucide-user-plus', onClick: () => { associateOpen = true } }]
              : []"
          />

          <UEmpty
            v-else-if="rows.length === 0"
            icon="i-lucide-search-x"
            title="Nenhum resultado com estes filtros"
            description="Ajuste a busca ou limpe os filtros aplicados."
            variant="naked"
            :actions="[{ label: 'Limpar filtros', color: 'neutral', variant: 'outline', onClick: clearFilters }]"
          />

          <template v-else>
            <div class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto md:hidden">
              <UCard v-for="row in rows" :key="row.client_id" :ui="{ body: 'p-3 sm:p-4' }">
                <div class="flex items-start justify-between gap-3">
                  <DataTableIdentity :title="row.name" :meta="row.tax_id ?? ''" />
                  <div class="flex shrink-0 flex-col items-end gap-1">
                    <UBadge
                      :color="situacaoColor(row)"
                      :icon="situacaoIcon(row)"
                      variant="subtle"
                      :label="situacaoLabel(row)"
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
                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
                  <div v-for="field in detailFields" :key="field.id" class="min-w-0">
                    <dt class="text-xs text-muted">
                      {{ field.header }}
                    </dt>
                    <dd class="truncate text-sm text-default tabular-nums">
                      {{ fieldValue(row, field.id) }}
                    </dd>
                  </div>
                </dl>
                <div
                  v-if="row.message"
                  class="mt-3 flex flex-col gap-2 border-t border-default pt-3"
                >
                  <!--
                    The subject the office is about to consent to. A phone that showed
                    the action and the dates without naming the message would ask for
                    the act before showing what the act is about.
                  -->
                  <span class="truncate text-xs text-muted" :title="row.message.assunto">
                    {{ row.message.assunto }}
                  </span>
                  <div class="flex flex-wrap items-center gap-2">
                    <UButton
                      size="xs"
                      color="neutral"
                      variant="outline"
                      icon="i-lucide-mail-open"
                      :label="messageAction(row.message, row.name).label"
                      :aria-label="messageAction(row.message, row.name).ariaLabel"
                      @click="openMessage(row)"
                    />
                    <span
                      v-if="row.message.ciencia_em"
                      class="text-xs text-muted tabular-nums"
                    >
                      Ciência {{ formatMonitoringDate(row.message.ciencia_em) }}
                    </span>
                    <span
                      v-if="row.message.prazo_limite"
                      class="text-xs tabular-nums"
                      :class="prazoPresentation(row.message).class"
                    >
                      {{ prazoPresentation(row.message).label }}
                    </span>
                  </div>
                </div>
              </UCard>
            </div>

            <div class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex">
              <UTable
                sticky
                :data="rows"
                :columns="columns"
                class="h-full min-h-0 w-full flex-1"
                :ui="sheetTableUi"
              >
                <template #name-cell="{ row }">
                  <div class="flex min-w-0 flex-col gap-1">
                    <DataTableIdentity :title="row.original.name" :meta="row.original.tax_id ?? ''" />
                    <!--
                      The mailbox's one legal act, and it lives here because `name` is the only
                      column every served obligation declares: `ultima` is declared by
                      `caixas-postais/e-cac` alone, so a slot on it would leave the two derived
                      mailbox pages unable to open a message on desktop while the phone card
                      could. A cell never reaches the body — the button asks `MessageDetail`
                      for consent.
                    -->
                    <template v-if="row.original.message">
                      <span class="truncate text-xs text-muted" :title="row.original.message.assunto">
                        {{ row.original.message.assunto }}
                      </span>
                      <div class="flex flex-wrap items-center gap-2">
                        <UButton
                          size="xs"
                          color="neutral"
                          variant="outline"
                          icon="i-lucide-mail-open"
                          :label="messageAction(row.original.message, row.original.name).label"
                          :aria-label="messageAction(row.original.message, row.original.name).ariaLabel"
                          @click="openMessage(row.original)"
                        />
                        <span
                          v-if="row.original.message.ciencia_em"
                          class="text-xs text-muted tabular-nums"
                        >
                          Ciência {{ formatMonitoringDate(row.original.message.ciencia_em) }}
                        </span>
                        <span
                          v-if="row.original.message.prazo_limite"
                          class="text-xs tabular-nums"
                          :class="prazoPresentation(row.original.message).class"
                        >
                          {{ prazoPresentation(row.original.message).label }}
                        </span>
                      </div>
                    </template>
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
                      :color="situacaoColor(row.original)"
                      :icon="situacaoIcon(row.original)"
                      variant="subtle"
                      :label="situacaoLabel(row.original)"
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

            <div v-if="canLoadMore" class="flex justify-center">
              <UButton
                label="Carregar mais"
                color="neutral"
                variant="outline"
                icon="i-lucide-chevrons-down"
                :loading="loadingMore"
                @click="loadMore"
              />
            </div>
          </template>
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
      :stub="messageStub"
      @read="afterRead"
    />
  </div>
</template>
