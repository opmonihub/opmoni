<script setup lang="ts">
import type { DropdownMenuItem, NavigationMenuItem } from '@nuxt/ui'
import type {
  Client,
  ClientListParams,
  ClientSheet,
  ClientPortfolioView,
  DeadlineStatus
} from '~/types/client'
import { sheetBodyClass } from '~/components/data-table/sheet'
import { customerDetailPath, customerListPath, parseCustomerList } from '~/utils/customerRoutes'
import { deadlineStatusAppearance } from '~/utils/portfolioLabels'

definePageMeta({ middleware: 'auth' })

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

const sort = ref<ClientListParams['sort']>('name')
const direction = ref<'asc' | 'desc'>('asc')

const page = ref(1)
const rows = ref<ClientSheet[]>([])
const matchingTotal = ref(0)
const listMode = ref<'sheet' | 'paged'>('sheet')
const loadingMore = ref(false)
let listGeneration = 0
const listEpoch = ref(0)

const { data: tagCatalog } = await useAsyncData('client-tag-catalog', () => listTags())

const listStatus = ref('idle')

const {
  search,
  debouncedSearch,
  statusFilter,
  regimeFilter,
  tagFilter,
  certificateFilter,
  poaFilter,
  columnFilters,
  hasActiveFilters,
  filterColumns,
  filterModels,
  activeFilterCount,
  onFilters,
  applySavedFilter,
  clearAppliedFilters,
  clearSearch
} = useClientListFilters({
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

const { data, status, error, refresh } = await useAsyncData(
  listKey,
  () => list(params.value)
)

watch(status, (value) => {
  listStatus.value = value
}, { immediate: true })

watch(data, (value) => {
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
  const nextPage = page.value + 1
  loadingMore.value = true
  try {
    const response = await list({ ...params.value, page: nextPage })
    if (generation !== listGeneration) return
    if (!response.data.length) {
      matchingTotal.value = rows.value.length
      return
    }
    const seen = new Set(rows.value.map(client => client.id))
    rows.value = [...rows.value, ...response.data.filter(client => !seen.has(client.id))]
    matchingTotal.value = response.meta?.total ?? matchingTotal.value
    page.value = nextPage
  } catch {
    toast.add({ title: 'Não foi possível carregar mais clientes', color: 'error' })
  } finally {
    loadingMore.value = false
  }
}

const columnVisibility = ref<Record<string, boolean>>({})
const hideableColumns = [
  { id: 'name', label: 'Nome/Razão social' },
  { id: 'tags', label: 'Tags' },
  { id: 'tax_regime', label: 'Regime' },
  { id: 'status', label: 'Situação' },
  { id: 'certificate', label: 'Cert. A1' },
  { id: 'ecac_power_of_attorney', label: 'e-CAC' }
]

const desktopTable = useClientMediaQuery('(min-width: 768px)')

const summaryParams = computed(() => ({
  q: debouncedSearch.value || undefined,
  ...columnFilters.value
}))

const { data: summary } = await useAsyncData(
  'client-portfolio-summary',
  () => portfolioSummary(summaryParams.value),
  { watch: [summaryParams] }
)

const total = computed(() => matchingTotal.value)
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

const { exportClients } = useClientListExport({
  rows,
  columnVisibility,
  isClientSelected,
  selectedCount
})

const tagsOpen = ref(false)
const tagsIntent = ref<'selection' | 'catalog'>('selection')
const tagsFocusCreate = ref(false)

function openTags(intent: 'selection' | 'catalog', focusCreate = false) {
  tagsIntent.value = intent
  tagsFocusCreate.value = focusCreate
  tagsOpen.value = true
}

const selectionMenu = computed<DropdownMenuItem[][]>(() => [[
  ...(canManageClients.value
    ? [{
        label: 'Tags',
        icon: 'i-lucide-tags',
        onSelect: () => openTags('selection')
      }]
    : []),
  {
    label: 'Exportar seleção',
    icon: 'i-lucide-file-spreadsheet',
    onSelect: () => exportClients(true)
  },
  {
    label: 'Cancelar seleção',
    icon: 'i-lucide-x',
    onSelect: clearSelection
  }
]])

watch([debouncedSearch, statusFilter, regimeFilter, tagFilter, certificateFilter, poaFilter, view], (_value, previous) => {
  if (!previous || selectedCount.value === 0) return
  clearSelection()
})

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
const powerOfAttorneyOpen = ref(false)
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

async function openPowerOfAttorney(client: ClientSheet) {
  rememberFocus()
  const full = await loadClient(client.id)
  if (!full) return
  target.value = full
  powerOfAttorneyOpen.value = true
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
    await refresh()
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
    { label: 'Procuração e-CAC', icon: 'i-lucide-file-key-2', onSelect: () => openPowerOfAttorney(client) },
    { type: 'separator' as const },
    { label: client.status === 'active' ? 'Inativar' : 'Ativar', icon: 'i-lucide-power', onSelect: () => toggleStatus(client) },
    { label: 'Excluir', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => openDelete(client) }
  ]
}

async function onSaved() {
  await refresh()
}

async function onDeleted() {
  deleteTarget.value = null
  await refresh()
}

const statusChoices: { label: string, value: DeadlineStatus | 'all', icon: string, iconClass?: string }[] = [
  { label: 'Todos', value: 'all', icon: 'i-lucide-building-2' },
  { label: 'A vencer', value: 'expiring', icon: deadlineStatusAppearance.expiring.icon, iconClass: deadlineStatusAppearance.expiring.iconClass },
  { label: 'Vencido', value: 'expired', icon: deadlineStatusAppearance.expired.icon, iconClass: deadlineStatusAppearance.expired.iconClass },
  { label: 'Válido', value: 'valid', icon: deadlineStatusAppearance.valid.icon, iconClass: deadlineStatusAppearance.valid.iconClass },
  { label: 'Sem cadastro', value: 'missing', icon: deadlineStatusAppearance.missing.icon, iconClass: deadlineStatusAppearance.missing.iconClass }
]

const statusTabs = computed<NavigationMenuItem[][]>(() => {
  const counts = summary.value
  const bucket = counts?.[documentTab.value]

  return [statusChoices.map(item => ({
    label: item.label,
    icon: item.icon,
    iconClass: item.iconClass,
    to: customerListPath(documentTab.value, item.value),
    exact: true,
    active: documentStatus.value === item.value,
    badge: item.value === 'all' ? counts?.total : bucket?.[item.value]
  }))]
})

function statusCount(value: DeadlineStatus | 'all') {
  const counts = summary.value
  if (!counts) return undefined
  return value === 'all' ? counts.total : counts[documentTab.value][value]
}

const mobileStatusItems = computed(() => statusChoices.map(item => ({
  label: item.label,
  value: item.value,
  to: customerListPath(documentTab.value, item.value),
  count: statusCount(item.value)
})))

const canLoadMore = computed(() =>
  listMode.value === 'paged'
  && status.value !== 'pending'
  && !loadingMore.value
  && rows.value.length < matchingTotal.value
)
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <CustomersClientListToolbar
      :status-tabs="statusTabs"
      :document-status="documentStatus"
      :search="search"
      :filter-models="filterModels"
      :can-manage-clients="canManageClients"
      @apply-saved-filter="applySavedFilter"
      @open-tags="openTags"
    />

    <div :class="sheetBodyClass">
      <CustomersClientListFilters
        v-model:search="search"
        v-model:column-visibility="columnVisibility"
        :document-status="documentStatus"
        :filter-columns="filterColumns"
        :filter-models="filterModels"
        :mobile-status-items="mobileStatusItems"
        :can-manage-clients="canManageClients"
        :is-loading="isLoading"
        :selected-count="selectedCount"
        :desktop-table="desktopTable"
        :rows-length="rows.length"
        :selection-menu="selectionMenu"
        :hideable-columns="hideableColumns"
        @update:filter-models="onFilters"
        @apply-saved-filter="applySavedFilter"
        @open-tags="openTags"
        @export="exportClients(false)"
      />

      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        title="Não foi possível carregar a carteira"
        description="Verifique sua conexão e tente novamente."
        :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => refresh() }]"
      />

      <template v-else>
        <CustomersClientPortfolioMobileList
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
          @open-power-of-attorney="openPowerOfAttorney"
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
          @open-power-of-attorney="openPowerOfAttorney"
          @remember-focus="rememberFocus"
        />

        <UEmpty
          v-if="!isLoading && total === 0 && !hasActiveFilters"
          icon="i-lucide-users"
          title="Nenhum cliente na carteira"
          description="Cadastre o primeiro cliente para começar a gerenciar a carteira."
          variant="naked"
          :actions="canManageClients ? [{
            label: 'Cadastrar cliente',
            icon: 'i-lucide-plus',
            onClick: openCreate
          }] : undefined"
        />

        <UEmpty
          v-else-if="!isLoading && total === 0"
          icon="i-lucide-search-x"
          title="Nenhum cliente encontrado"
          description="Ajuste a busca ou limpe os filtros aplicados."
          variant="naked"
          :actions="[
            ...(activeFilterCount ? [{
              label: 'Limpar filtros',
              color: 'neutral' as const,
              variant: 'outline' as const,
              onClick: clearAppliedFilters
            }] : []),
            ...(search ? [{
              label: 'Limpar busca',
              color: 'neutral' as const,
              variant: 'outline' as const,
              onClick: clearSearch
            }] : [])
          ]"
        />
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
    <CustomersEcacPowerOfAttorneyModal
      v-model:open="powerOfAttorneyOpen"
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
      @applied="refresh"
      @changed="refresh"
    />

    <CustomersClientDeleteModal
      v-model:open="deleteOpen"
      :client="deleteTarget"
      :content="overlayContent"
      @deleted="onDeleted"
    />
  </div>
</template>
