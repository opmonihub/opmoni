<script setup lang="ts">
import type {
  Client,
  ClientListParams,
  ClientSheet,
  ClientPortfolioView,
  DeadlineStatus
} from '~/types/client'
import { clientExportColumns } from '~/utils/clientExportColumns'
import { customerDetailPath, customerListPath, parseCustomerList } from '~/utils/customerRoutes'
import { deadlineStatusAppearance } from '~/utils/portfolioLabels'

definePageMeta({ middleware: 'auth' })

const readLifecycle = useOfficeReadLifecycle()

const { list, show, portfolioSummary, update, listTags, createSelection } = useClients()
const { canManageClients } = useAuth()
const toast = useToast()

const route = useRoute()
const listing = computed(() => parseCustomerList(route.params.documento, route.params.situacao))
if (!listing.value) {
  throw createError({ statusCode: 404, statusMessage: 'Página não encontrada' })
}

watch(listing, (value) => {
  if (!value) {
    showError(createError({ statusCode: 404, statusMessage: 'Página não encontrada' }))
  }
})

const documentTab = computed(() => listing.value?.document ?? 'certificate')
const documentStatus = computed(() => listing.value?.status ?? 'all')
const view = computed<ClientPortfolioView | 'all'>(() =>
  documentStatus.value === 'all' ? 'all' : `${documentTab.value}_${documentStatus.value}`
)

const navigation = useOfficeTableNavigation('portfolio', ['tag', 'regime', 'status', 'certificate', 'poa'], ['name', 'tax_id', 'status', 'tax_regime', 'created_at', 'certificate', 'poa'], { page: true })
const sort = computed<ClientListParams['sort']>({
  get: () => navigation.sorting.value[0]?.id as ClientListParams['sort'] ?? 'name',
  set: (value) => { navigation.sorting.value = [{ id: value ?? 'name', desc: direction.value === 'desc' }] }
})
const direction = computed<'asc' | 'desc'>({
  get: () => navigation.sorting.value[0]?.desc ? 'desc' : 'asc',
  set: (value) => { navigation.sorting.value = [{ id: sort.value ?? 'name', desc: value === 'desc' }] }
})
const page = navigation.page
const rows = ref<ClientSheet[]>([])
const matchingTotal = ref(0)
const listMode = ref<'sheet' | 'paged'>('sheet')
const loadingMore = ref(false)
let listGeneration = 0
const listEpoch = ref(0)

const { data: tagCatalog, clear: clearOffice0, refresh: refreshOffice0 } = await useAsyncData('client-tag-catalog', (_nuxtApp, { signal }) => listTags({ signal }))
readLifecycle.own({ clear: clearOffice0, refresh: refreshOffice0 })

const listStatus = ref('idle')

const {
  search,
  debouncedSearch,
  columnFilters,
  filterColumns,
  filterModels,
  activeFilterCount,
  onFilters,
  clearAppliedFilters,
  clearSearch
} = useClientListFilters({
  navigation,
  listing,
  rows,
  matchingTotal,
  listMode,
  listStatus,
  tagCatalog,
  view
})

const params = computed<ClientListParams>(() => ({
  sheet: 1,
  q: debouncedSearch.value || undefined,
  ...columnFilters.value,
  view: view.value === 'all' ? undefined : view.value,
  sort: sort.value,
  direction: direction.value
}))

const listKey = computed(() => JSON.stringify([
  route.params.documento,
  route.params.situacao ?? '',
  params.value
]))

let moreController: AbortController | undefined
watch(params, () => {
  navigation.page.value = 1
  listGeneration++
  moreController?.abort()
  loadingMore.value = false
}, { flush: 'sync' })
onBeforeUnmount(() => {
  listGeneration++
  moreController?.abort()
})

const { data, status, error, refresh, clear: clearOffice1 } = await useAsyncData(
  listKey,
  async (_nuxtApp, { signal }) => {
    const targetPage = page.value
    const query = { ...params.value }
    const response = await list(query, { signal })
    const seen = new Set(response.data.map(client => client.id))
    for (let next = 2; response.meta?.mode === 'paged' && next <= targetPage && next <= (response.meta.last_page ?? 1); next++) {
      const following = await list({ ...query, page: next }, { signal })
      response.data.push(...following.data.filter(client => !seen.has(client.id)))
      following.data.forEach(client => seen.add(client.id))
      response.meta = following.meta
      if (!following.data.length) break
    }
    return response
  }
)
readLifecycle.own({ clear: clearOffice1, refresh: refresh }, () => {
  rows.value = []
  matchingTotal.value = 0
  listGeneration++
  moreController?.abort()
})

watch(status, (value) => {
  listStatus.value = value
}, { immediate: true })

watch(data, (value) => {
  moreController?.abort()
  loadingMore.value = false
  listGeneration += 1
  listEpoch.value = listGeneration
  rows.value = value?.data ?? []
  matchingTotal.value = value?.meta?.total ?? rows.value.length
  listMode.value = value?.meta?.mode === 'paged' ? 'paged' : 'sheet'
  page.value = value?.meta?.current_page ?? 1
}, { immediate: true })

async function loadMore() {
  if (listMode.value !== 'paged' || loadingMore.value || status.value === 'pending') return
  if (rows.value.length >= matchingTotal.value) return

  const generation = listGeneration
  moreController?.abort()
  const attempt = new AbortController()
  moreController = attempt
  const nextPage = page.value + 1
  loadingMore.value = true
  try {
    const response = await list({ ...params.value, page: nextPage }, { signal: attempt.signal })
    if (attempt.signal.aborted || generation !== listGeneration) return
    if (!response.data.length) {
      matchingTotal.value = rows.value.length
      return
    }
    const seen = new Set(rows.value.map(client => client.id))
    rows.value = [...rows.value, ...response.data.filter(client => !seen.has(client.id))]
    matchingTotal.value = response.meta?.total ?? matchingTotal.value
    page.value = nextPage
  } catch {
    if (attempt.signal.aborted || generation !== listGeneration) return
    toast.add({ title: 'Não foi possível carregar mais clientes', color: 'error' })
  } finally {
    if (generation === listGeneration) loadingMore.value = false
  }
}

const columnVisibility = ref<Record<string, boolean>>({})
const hideableColumns = [...clientExportColumns]
const exportOpen = ref(false)
const exportSelectionOnly = ref(false)
const exportColumnIds = ref<string[]>([])

const desktopTable = useClientMediaQuery('(min-width: 768px)')

const summaryParams = computed(() => ({
  q: debouncedSearch.value || undefined,
  ...columnFilters.value
}))

const { data: summary, refresh: refreshSummary, status: summaryStatus, error: summaryError, clear: clearOffice2 } = await useAsyncData(
  'client-portfolio-summary',
  (_nuxtApp, { signal }) => portfolioSummary(summaryParams.value, { signal }),
  { watch: [summaryParams] }
)
readLifecycle.own({ clear: clearOffice2, refresh: refreshSummary })

async function refreshPortfolio() {
  await Promise.all([refresh(), refreshSummary()])
}

const isLoading = computed(() => status.value === 'pending')

const selectionParams = computed(() => ({
  q: debouncedSearch.value || undefined,
  ...columnFilters.value,
  view: view.value === 'all' ? undefined : view.value
}))

const {
  selectingAll,
  selectedCount,
  isClientSelected,
  setClientSelected,
  clearSelection,
  rowSelection,
  headerState,
  onHeaderToggle,
  tagAssignment
} = useClientListSelection({
  matchingTotal,
  rows,
  selectionParams,
  createSelection
})
watch(readLifecycle.authority, clearSelection, { flush: 'sync' })

const { exportClients } = useClientListExport({
  rows,
  isClientSelected,
  selectedCount
})

function openExport(onlySelection = false) {
  exportSelectionOnly.value = onlySelection
  const visible = hideableColumns.filter(column => columnVisibility.value[column.id] !== false).map(column => column.id)
  exportColumnIds.value = visible.length ? visible : hideableColumns.map(column => column.id)
  exportOpen.value = true
}

function confirmExport(columnIds: string[]) {
  exportClients(exportSelectionOnly.value, columnIds)
  exportOpen.value = false
}

const tagsOpen = ref(false)
const tagsIntent = ref<'selection' | 'catalog'>('selection')
const tagsFocusCreate = ref(false)

function openTags(intent: 'selection' | 'catalog', focusCreate = false) {
  tagsIntent.value = intent
  tagsFocusCreate.value = focusCreate
  tagsOpen.value = true
}

type SortKey = ClientListParams['sort']

function toggleSort(key: SortKey) {
  if (sort.value === key) {
    direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = key
    direction.value = 'asc'
  }
}

const target = shallowRef<Client | null>(null)
const formOpen = ref(false)
const certificateOpen = ref(false)
const deleteOpen = ref(false)
const deleteTarget = shallowRef<ClientSheet | null>(null)

let lastTrigger: HTMLElement | null = null

function rememberFocus() {
  const activeElement = document.activeElement instanceof HTMLElement ? document.activeElement : null
  if (activeElement?.getAttribute('role') === 'menuitem' && lastTrigger?.isConnected) return
  lastTrigger = activeElement
}

function restoreFocus(event: Event) {
  event.preventDefault()
  lastTrigger?.focus?.()
  lastTrigger = null
}

const overlayContent = { onCloseAutoFocus: restoreFocus }

async function loadClient(id: number) {
  try {
    return await show(id)
  } catch {
    toast.add({ title: 'Não foi possível abrir o cliente', color: 'error' })
    return null
  }
}

function openDetails(client: ClientSheet) {
  return navigateTo(customerDetailPath(client.id))
}

function openCreate() {
  rememberFocus()
  formOpen.value = true
}

const createRequest = useState('customers-create', () => 0)
watch(createRequest, (value, previous) => {
  if (value === previous) return
  openCreate()
})

function openEdit(client: ClientSheet) {
  return navigateTo(customerDetailPath(client.id))
}

async function openCertificate(client: ClientSheet) {
  rememberFocus()
  const full = await loadClient(client.id)
  if (!full) return
  target.value = full
  certificateOpen.value = true
}

function openDelete(client: ClientSheet) {
  rememberFocus()
  deleteTarget.value = client
  deleteOpen.value = true
}

async function toggleStatus(client: ClientSheet) {
  try {
    await update(client.id, { status: client.status === 'active' ? 'inactive' : 'active' })
    toast.add({ title: client.status === 'active' ? 'Cliente inativado' : 'Cliente ativado', color: 'success' })
    await refreshPortfolio()
  } catch {
    toast.add({ title: 'Não foi possível alterar a situação', color: 'error' })
  }
}

function rowActions(client: ClientSheet) {
  const items = [{ label: 'Visualizar', icon: 'i-lucide-eye', onSelect: () => openDetails(client) }]
  if (!canManageClients.value) return items
  return [
    ...items,
    { label: 'Editar', icon: 'i-lucide-pencil', onSelect: () => openEdit(client) },
    { label: 'Certificado A1', icon: 'i-lucide-key-round', onSelect: () => openCertificate(client) },
    { type: 'separator' as const },
    { label: client.status === 'active' ? 'Inativar' : 'Ativar', icon: 'i-lucide-power', onSelect: () => toggleStatus(client) },
    { label: 'Excluir', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => openDelete(client) }
  ]
}

async function onSaved() {
  await refreshPortfolio()
}

async function onDeleted() {
  deleteTarget.value = null
  await refreshPortfolio()
}

const statusChoices: { label: string, value: DeadlineStatus | 'all', icon: string, iconClass?: string }[] = [
  { label: 'Todos', value: 'all', icon: 'i-lucide-building-2' },
  { label: 'A vencer', value: 'expiring', icon: deadlineStatusAppearance.expiring.icon, iconClass: deadlineStatusAppearance.expiring.iconClass },
  { label: 'Vencido', value: 'expired', icon: deadlineStatusAppearance.expired.icon, iconClass: deadlineStatusAppearance.expired.iconClass },
  { label: 'Válido', value: 'valid', icon: deadlineStatusAppearance.valid.icon, iconClass: deadlineStatusAppearance.valid.iconClass },
  { label: 'Sem cadastro', value: 'missing', icon: deadlineStatusAppearance.missing.icon, iconClass: deadlineStatusAppearance.missing.iconClass }
]

const statusCards = computed(() => {
  const counts = summaryError.value ? undefined : summary.value
  const bucket = counts?.[documentTab.value]

  return statusChoices.map(item => ({
    ...item,
    to: customerListPath(documentTab.value, item.value),
    selected: documentStatus.value === item.value,
    count: item.value === 'all' ? counts?.total : bucket?.[item.value]
  }))
})

const canLoadMore = computed(() =>
  listMode.value === 'paged'
  && status.value !== 'pending'
  && !loadingMore.value
  && rows.value.length < matchingTotal.value
)
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-1 flex-col">
    <div class="relative flex min-h-0 min-w-0 flex-1 flex-col gap-4 overflow-hidden p-4 sm:gap-6 sm:p-6">
      <CustomersClientListToolbar
        :status-cards="statusCards"
        :loading="summaryStatus === 'pending'"
        :unavailable="Boolean(summaryError) || !summary"
      />
      <CustomersClientListFilters
        v-model:search="search"
        v-model:column-visibility="columnVisibility"
        :filter-columns="filterColumns"
        :filter-models="filterModels"
        :can-manage-clients="canManageClients"
        :is-loading="isLoading"
        :desktop-table="desktopTable"
        :rows-length="rows.length"
        :hideable-columns="hideableColumns"
        @update:filter-models="onFilters"
        @open-tags="openTags"
        @export="openExport(false)"
      />

      <ErrorRetryAlert
        v-if="error"
        title="Não foi possível carregar a carteira"
        @retry="refreshPortfolio"
      />

      <div
        v-else-if="isLoading && !rows.length"
        class="min-h-0 flex-1 overflow-y-auto"
        role="status"
        aria-label="Carregando clientes"
      >
        <div class="flex flex-col gap-3" aria-hidden="true">
          <USkeleton class="hidden h-11 w-full shrink-0 rounded-lg md:block" />
          <USkeleton
            v-for="index in 6"
            :key="index"
            class="h-40 w-full shrink-0 rounded-lg md:h-12"
          />
        </div>
      </div>

      <template v-else>
        <CustomersClientPortfolioEmpty
          v-if="!rows.length"
          class="md:hidden"
          :document="documentTab"
          :status="documentStatus"
          :portfolio-total="summary?.total"
          :search="debouncedSearch"
          :has-filters="activeFilterCount > 0"
          :can-manage-clients="canManageClients"
          @create="openCreate"
          @clear-filters="clearAppliedFilters"
          @clear-search="clearSearch"
          @refresh="refreshPortfolio"
        />

        <CustomersClientPortfolioMobileList
          v-else
          :rows="rows"
          :list-epoch="listEpoch"
          :is-loading="isLoading"
          :can-manage-clients="canManageClients"
          :selected-count="selectedCount"
          :can-load-more="canLoadMore"
          :is-client-selected="isClientSelected"
          :row-actions="rowActions"
          @load-more="loadMore"
          @set-selected="setClientSelected"
          @open-certificate="openCertificate"
          @remember-focus="rememberFocus"
        />

        <CustomersClientPortfolioTable
          v-model:column-visibility="columnVisibility"
          v-model:row-selection="rowSelection"
          :rows="rows"
          :is-loading="isLoading"
          :loading-more="loadingMore"
          :can-manage-clients="canManageClients"
          :selected-count="selectedCount"
          :can-load-more="canLoadMore"
          :header-state="headerState"
          :selecting-all="selectingAll"
          :matching-total="matchingTotal"
          :sort="sort"
          :direction="direction"
          :row-actions="rowActions"
          @load-more="loadMore"
          @header-toggle="onHeaderToggle"
          @toggle-sort="toggleSort"
          @open-certificate="openCertificate"
          @remember-focus="rememberFocus"
        >
          <template #empty>
            <CustomersClientPortfolioEmpty
              :document="documentTab"
              :status="documentStatus"
              :portfolio-total="summary?.total"
              :search="debouncedSearch"
              :has-filters="activeFilterCount > 0"
              :can-manage-clients="canManageClients"
              @create="openCreate"
              @clear-filters="clearAppliedFilters"
              @clear-search="clearSearch"
              @refresh="refreshPortfolio"
            />
          </template>
        </CustomersClientPortfolioTable>
      </template>

      <Transition
        enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
        enter-from-class="translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-100 ease-in motion-reduce:transition-none"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-2 opacity-0"
      >
        <CustomersSelectionBar
          v-if="canManageClients && selectedCount"
          class="absolute bottom-3 left-1/2 z-20 w-max max-w-[calc(100%-1.5rem)] -translate-x-1/2"
          :count="selectedCount"
          :disabled="isLoading || selectingAll"
          @clear="clearSelection"
          @tags="openTags('selection')"
          @export="openExport(true)"
        />
      </Transition>
    </div>

    <CustomersClientCreateModal
      v-model:open="formOpen"
      :content="overlayContent"
      @saved="onSaved"
    />
    <CustomersCertificateModal
      v-model:open="certificateOpen"
      :client="target"
      :content="overlayContent"
      @saved="onSaved"
    />
    <CustomersTagsModal
      v-if="canManageClients"
      v-model:open="tagsOpen"
      :count="selectedCount"
      :assignment="tagAssignment"
      :intent="tagsIntent"
      :focus-create="tagsFocusCreate"
      @applied="refreshPortfolio"
      @changed="refreshPortfolio"
    />

    <CustomersClientExportModal
      v-model:open="exportOpen"
      :columns="hideableColumns"
      :selected-ids="exportColumnIds"
      :selection-only="exportSelectionOnly"
      @export="confirmExport"
    />

    <CustomersClientDeleteModal
      v-model:open="deleteOpen"
      :client="deleteTarget"
      :content="overlayContent"
      @deleted="onDeleted"
    />
  </div>
</template>
