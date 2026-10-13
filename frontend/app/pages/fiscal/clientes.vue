<script setup lang="ts">
import { h } from 'vue'
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import DataTableSortButton from '~/components/data-table/SortButton.vue'
import { sheetTableUi } from '~/components/data-table/sheet'
import { matchesFilters } from '~/components/data-table/filter-model'
import type {
  FiscalClientCertificateStatus,
  FiscalClientSummary,
  FiscalClientsFilters,
  FiscalModel
} from '~/types/fiscal'
import {
  fiscalClientCsv,
  fiscalClientCsvFileName,
  fiscalClientDocumentsPath,
  fiscalClientsCsv
} from '~/utils/fiscalClients'
import {
  fiscalClientCertificatePresentation,
  fiscalMissingValue,
  formatFiscalAmount,
  formatFiscalCount,
  formatFiscalDay,
  modelLabel,
  modelVolumes
} from '~/utils/fiscalPresentation'
import { toPanelColumns, type FilterPanelColumn } from '~/utils/filterPanel'
import { pageTableClass } from '~/utils/pageShell'
import { formatTaxId } from '~/utils/taxId'

type ClientSortKey = 'cliente' | 'certificado' | 'total' | 'saidas' | 'entradas' | 'ultima_emissao_at'

const certificateStatuses: FiscalClientCertificateStatus[] = [
  'missing',
  'expired',
  'password_missing',
  'expiring',
  'valid'
]

const modelOptions: { label: string, value: FiscalModel }[] = [
  { label: modelLabel('nfe'), value: 'nfe' },
  { label: modelLabel('nfce'), value: 'nfce' },
  { label: modelLabel('cte'), value: 'cte' },
  { label: modelLabel('cte_os'), value: 'cte_os' },
  { label: modelLabel('gtve'), value: 'gtve' },
  { label: modelLabel('nfse'), value: 'nfse' }
]

const filterColumns = [{
  id: 'certificado_status',
  label: 'Certificado A1',
  icon: 'i-lucide-shield-check',
  type: 'option',
  options: certificateStatuses.map((status) => {
    const presentation = fiscalClientCertificatePresentation(status)
    return { label: presentation.label, value: status, color: presentation.color }
  })
}, {
  id: 'modelo',
  label: 'Modelo',
  icon: 'i-lucide-files',
  type: 'option',
  options: modelOptions
}]

const hideableColumns = [
  { id: 'certificado', label: 'Certificado A1' },
  { id: 'total', label: 'Documentos' },
  { id: 'saidas', label: 'Saídas' },
  { id: 'entradas', label: 'Entradas' },
  { id: 'modelos', label: 'Modelos' },
  { id: 'ultima_emissao', label: 'Última emissão' }
]

/**
 * A visão fiscal por cliente, em `/fiscal/clientes`.
 *
 * Middleware nomeado como em todas as páginas do produto: qualquer membro da
 * conta lê a captura. Uma linha por cliente, com totais, saídas, entradas,
 * volume por modelo, última emissão e o estado do A1 — e o menu da linha leva
 * aos documentos ou exporta os dados daquele cliente.
 *
 * Se o `GET /fiscal/clients` responder 404, o backend ainda não subiu: a página
 * mostra o estado "resumo ainda não disponível" em vez do alerta de falha, sem
 * quebrar a tabela de documentos — que tem o seu próprio caminho.
 */
definePageMeta({ middleware: 'auth' })

const { clients } = useFiscal()

const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const filterModels = ref<DataTableFilterModel[]>([])
const modelFilter = computed(() => filterModels.value.find(filter => filter.columnId === 'modelo'))
const selectedModels = computed<FiscalModel[]>(() => {
  const filter = modelFilter.value
  if (!filter) return []

  const values = new Set(filter.values.map(String))
  const excluded = filter.operator === 'is not'
    || filter.operator === 'is none of'
    || filter.operator === 'exclude'
    || filter.operator === 'exclude if any of'
    || filter.operator === 'exclude if all'
  return modelOptions
    .filter(option => excluded ? !values.has(option.value) : values.has(option.value))
    .map(option => option.value)
})
const issuedFilter = computed(() => filterModels.value.find(filter => filter.columnId === 'issued'))
const issuedFrom = computed(() => issuedFilter.value?.values[0] === undefined ? '' : String(issuedFilter.value.values[0]))
const issuedTo = computed(() => issuedFilter.value?.values[1] === undefined ? '' : String(issuedFilter.value.values[1]))
const panelColumns = computed<FilterPanelColumn[]>(() => [
  ...toPanelColumns(filterColumns, { operators: true }),
  { id: 'issued', label: 'Emissão', icon: 'i-lucide-calendar-range', control: 'date-range' }
])
const columnVisibility = ref<Record<string, boolean>>({})
const rowSelection = ref<Record<string, boolean>>({})
const sort = ref<ClientSortKey>('cliente')
const direction = ref<'asc' | 'desc'>('asc')
const currentPage = ref(1)
const pageSize = ref(25)
const desktopTable = useClientMediaQuery('(min-width: 768px)')

const requestFilters = computed<FiscalClientsFilters>(() => ({
  model: selectedModels.value.length ? [...selectedModels.value].sort() : undefined,
  issued_from: issuedFrom.value || undefined,
  issued_to: issuedTo.value || undefined
}))

const listKey = computed(() => `fiscal-clients-${JSON.stringify(requestFilters.value)}`)

const { data, status, error, refresh: reload } = await useAsyncData<FiscalClientSummary[]>(
  listKey,
  () => clients(requestFilters.value),
  {
    // Reutiliza o SSR na hidratação e busca dados novos nas próximas entradas.
    getCachedData: (key, nuxtApp) => nuxtApp.isHydrating ? nuxtApp.payload.data[key] : undefined
  }
)

const { isLoading, showError, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar os clientes',
  refreshErrorTitle: 'Não foi possível atualizar os clientes',
  sticky: true
})

/**
 * 404 é "o backend ainda não subiu", e não uma carga quebrada: o alerta de
 * falha continua aparecendo — é a superfície que a página tem — mas com a
 * frase do briefing em vez do texto genérico, para não gritar erro sobre algo
 * que ainda não existe. Sem a separação, um backend em paralelo faria a tela
 * acusar uma carga quebrada. A tabela de documentos tem o seu próprio caminho
 * e não é afetada.
 */
const endpointMissing = computed(() => apiStatus(error.value) === 404)

const rows = computed(() => data.value ?? [])

const filteredRows = computed(() => {
  if (modelFilter.value && !selectedModels.value.length) return []

  const term = normalizeSearch(debouncedSearch.value)
  const rawSearch = debouncedSearch.value.trim()
  const taxIdSearch = /^[\d\s./-]+$/.test(rawSearch) ? rawSearch.replace(/\D/g, '') : ''
  const certificateFilters = filterModels.value.filter(filter => filter.columnId === 'certificado_status')

  return rows.value
    .filter((summary) => {
      const name = normalizeSearch(summary.client.name)
      const taxId = (summary.client.tax_id ?? '').replace(/\D/g, '')
      const matchesSearch = !term
        || name.includes(term)
        || (!!taxIdSearch && taxId.includes(taxIdSearch))

      const matchesCertificate = matchesFilters(
        certificateFilters,
        columnId => columnId === 'certificado_status' ? summary.certificado_status : null,
        () => 'option'
      )

      return matchesSearch && matchesCertificate
    })
    .sort(compareClientRows)
})

const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize.value)))

const pageRows = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredRows.value.slice(start, start + pageSize.value)
})

const selectedCount = computed(() => Object.values(rowSelection.value).filter(Boolean).length)

const selectedRows = computed(() => filteredRows.value.filter(summary => rowSelection.value[String(summary.client.id)]))

const headerState = computed<boolean | 'indeterminate'>(() => {
  if (!filteredRows.value.length) return false
  const selected = selectedRows.value.length
  if (!selected) return false
  return selected === filteredRows.value.length ? true : 'indeterminate'
})

const hasActiveFilters = computed(() => Boolean(
  search.value.trim()
  || filterModels.value.length
))

const UCheckbox = resolveComponent('UCheckbox')

const columns = computed<TableColumn<FiscalClientSummary>[]>(() => [
  {
    id: 'select',
    enableHiding: false,
    meta: { class: { th: 'w-10', td: 'w-10' } },
    header: () => h(UCheckbox, {
      'modelValue': headerState.value,
      'onUpdate:modelValue': toggleAllRows,
      'aria-label': 'Selecionar todos os clientes filtrados'
    }),
    cell: ({ row }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(value === true),
      'aria-label': `Selecionar ${row.original.client.name}`
    })
  },
  {
    id: 'cliente',
    header: () => sortableHeader('Cliente', 'cliente'),
    enableHiding: false,
    meta: { class: { th: 'min-w-56 whitespace-nowrap', td: 'max-w-0' } }
  },
  {
    id: 'certificado',
    header: () => sortableHeader('Certificado A1', 'certificado'),
    meta: { class: { th: 'min-w-40 whitespace-nowrap', td: 'whitespace-nowrap' } }
  },
  {
    id: 'total',
    header: () => sortableHeader('Documentos', 'total'),
    meta: { class: { th: 'whitespace-nowrap text-right', td: 'whitespace-nowrap text-right tabular-nums' } }
  },
  {
    id: 'saidas',
    header: () => sortableHeader('Saídas', 'saidas'),
    meta: { class: { th: 'min-w-36 whitespace-nowrap', td: 'whitespace-nowrap' } }
  },
  {
    id: 'entradas',
    header: () => sortableHeader('Entradas', 'entradas'),
    meta: { class: { th: 'min-w-36 whitespace-nowrap', td: 'whitespace-nowrap' } }
  },
  {
    id: 'modelos',
    header: 'Modelos',
    meta: { class: { th: 'min-w-48 whitespace-nowrap', td: 'max-w-64' } }
  },
  {
    id: 'ultima_emissao',
    header: () => sortableHeader('Última emissão', 'ultima_emissao_at'),
    meta: { class: { th: 'min-w-32 whitespace-nowrap', td: 'whitespace-nowrap tabular-nums' } }
  },
  {
    id: 'acoes',
    header: 'Ações',
    enableHiding: false,
    meta: { class: { th: 'w-14', td: 'w-14 text-right' } }
  }
])

watch([debouncedSearch, filterModels], () => {
  currentPage.value = 1
  clearSelection()
})

watch(pageSize, () => {
  currentPage.value = 1
})

watch(pageCount, (count) => {
  if (currentPage.value > count) currentPage.value = count
})

function normalizeSearch(value: string): string {
  return value
    .trim()
    .toLocaleLowerCase('pt-BR')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
}

function compareNullableText(left: string | null, right: string | null, multiplier: number): number {
  if (left === null) return right === null ? 0 : 1
  if (right === null) return -1
  return left.localeCompare(right, 'pt-BR', { numeric: true }) * multiplier
}

function compareNullableAmount(left: string | null, right: string | null, multiplier: number): number {
  if (left === null) return right === null ? 0 : 1
  if (right === null) return -1
  return (Number(left) - Number(right)) * multiplier
}

function compareClientRows(left: FiscalClientSummary, right: FiscalClientSummary): number {
  const multiplier = direction.value === 'asc' ? 1 : -1
  let result = 0

  switch (sort.value) {
    case 'cliente':
      result = left.client.name.localeCompare(right.client.name, 'pt-BR', { sensitivity: 'base', numeric: true }) * multiplier
      break
    case 'certificado':
      result = fiscalClientCertificatePresentation(left.certificado_status).label.localeCompare(
        fiscalClientCertificatePresentation(right.certificado_status).label,
        'pt-BR',
        { sensitivity: 'base' }
      ) * multiplier
      break
    case 'total':
      result = (left.total - right.total) * multiplier
      break
    case 'saidas':
      result = compareNullableAmount(left.saidas.valor, right.saidas.valor, multiplier)
      break
    case 'entradas':
      result = compareNullableAmount(left.entradas.valor, right.entradas.valor, multiplier)
      break
    case 'ultima_emissao_at':
      result = compareNullableText(left.ultima_emissao_at, right.ultima_emissao_at, multiplier)
      break
  }

  return result || left.client.name.localeCompare(right.client.name, 'pt-BR', { sensitivity: 'base' })
}

function sortableHeader(label: string, key: ClientSortKey) {
  return h(DataTableSortButton, {
    label,
    sorted: sort.value === key ? direction.value : false,
    onToggle: () => toggleSort(key)
  })
}

function toggleSort(key: ClientSortKey) {
  if (sort.value === key) {
    direction.value = direction.value === 'asc' ? 'desc' : 'asc'
    return
  }

  sort.value = key
  direction.value = 'asc'
}

function toggleAllRows(value: boolean | 'indeterminate') {
  if (value !== true) {
    clearSelection()
    return
  }

  rowSelection.value = Object.fromEntries(
    filteredRows.value.map(summary => [String(summary.client.id), true])
  )
}

function clearSelection() {
  rowSelection.value = {}
}

function clientRowId(summary: FiscalClientSummary): string {
  return String(summary.client.id)
}

function setPageSize(value: string | number) {
  const parsed = Number(value)
  if ([25, 50, 100].includes(parsed)) pageSize.value = parsed
}

function clearFilters() {
  search.value = ''
  filterModels.value = []
  clearSelection()
  currentPage.value = 1
}

function exportRows(summaries: readonly FiscalClientSummary[]) {
  if (!summaries.length) return

  const exportableColumns = ['cliente', 'certificado', 'total', 'saidas', 'entradas', 'modelos', 'ultima_emissao']
  const visibleColumns = exportableColumns.filter(id => columnVisibility.value[id] !== false)
  const blob = new Blob([`\uFEFF${fiscalClientsCsv(summaries, visibleColumns)}`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'fiscal-clientes.csv'
  link.rel = 'noopener'
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

const selectionMenu = computed<DropdownMenuItem[][]>(() => [[
  {
    label: 'Exportar seleção',
    icon: 'i-lucide-file-spreadsheet',
    onSelect: () => exportRows(selectedRows.value)
  },
  {
    label: 'Cancelar seleção',
    icon: 'i-lucide-x',
    onSelect: clearSelection
  }
]])

/**
 * O CSV sai do agregado já carregado, sem endpoint novo — é a planilha da
 * visão, e não uma segunda consulta. O nome leva o id do cliente e a
 * competência da última emissão, e o download segue o mesmo acordo da lista de
 * clientes: âncora no documento no clique, URL revogada no próximo ciclo.
 */
function exportClient(summary: FiscalClientSummary) {
  const blob = new Blob([`\uFEFF${fiscalClientCsv(summary)}`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fiscalClientCsvFileName(summary.client.id, summary.ultima_emissao_at)
  link.rel = 'noopener'
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

function goToDocuments(clientId: number) {
  return navigateTo(fiscalClientDocumentsPath(clientId))
}

function clientActions(summary: FiscalClientSummary): DropdownMenuItem[] {
  return [
    {
      label: 'Exportar CSV',
      icon: 'i-lucide-download',
      onSelect: () => exportClient(summary)
    },
    {
      label: 'Ver documentos',
      icon: 'i-lucide-files',
      onSelect: () => goToDocuments(summary.client.id)
    }
  ]
}
</script>

<template>
  <div :class="pageTableClass">
    <header class="flex min-w-0 items-center justify-between gap-3">
      <div class="flex min-w-0 items-center gap-2.5">
        <UIcon name="i-lucide-building-2" class="size-5 shrink-0 text-primary" />
        <h2 class="truncate text-base font-semibold tracking-tight text-highlighted sm:text-lg">
          Clientes
        </h2>
      </div>
    </header>

    <ErrorRetryAlert
      v-if="showError"
      :title="endpointMissing ? 'Resumo por cliente ainda não disponível' : 'Não foi possível carregar os clientes'"
      :description="endpointMissing ? 'O agregado de documentos por cliente ainda não foi publicado. A tabela de documentos continua funcionando normalmente.' : undefined"
      @retry="retry"
    />

    <template v-else>
      <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-3">
        <DataTableFilterPanel
          :columns="panelColumns"
          :model-value="filterModels"
          :disabled="isLoading"
          class="min-w-0 shrink-0"
          @update:model-value="filterModels = $event"
        >
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Buscar cliente ou CPF/CNPJ..."
            class="w-full min-w-0 flex-1"
            :disabled="isLoading"
          />
          <template #trailing>
            <div class="ml-auto flex shrink-0 items-center gap-1.5">
              <UButton
                v-if="search.trim()"
                :label="desktopTable ? 'Limpar busca' : undefined"
                icon="i-lucide-filter-x"
                color="neutral"
                variant="ghost"
                size="sm"
                class="shrink-0"
                :disabled="isLoading"
                aria-label="Limpar busca"
                @click="search = ''"
              />

              <UDropdownMenu
                v-if="selectedCount"
                :items="selectionMenu"
                :content="{ align: 'end' }"
              >
                <UButton
                  :label="desktopTable ? 'Seleção' : undefined"
                  icon="i-lucide-list-checks"
                  color="neutral"
                  variant="subtle"
                  class="shrink-0"
                  aria-label="Ações da seleção"
                >
                  <template #trailing>
                    <UKbd>{{ formatFiscalCount(selectedCount) }}</UKbd>
                  </template>
                </UButton>
              </UDropdownMenu>

              <UButton
                :label="desktopTable ? 'Exportar' : undefined"
                icon="i-lucide-file-spreadsheet"
                color="neutral"
                variant="outline"
                class="shrink-0"
                aria-label="Exportar clientes filtrados para CSV"
                :disabled="isLoading || !filteredRows.length"
                @click="exportRows(filteredRows)"
              />

              <UButton
                icon="i-lucide-refresh-cw"
                color="neutral"
                variant="outline"
                class="shrink-0"
                aria-label="Atualizar clientes"
                :loading="isLoading"
                @click="retry"
              />

              <DataTableColumnMenu
                v-model="columnVisibility"
                :columns="hideableColumns"
                class="shrink-0"
              />
            </div>
          </template>
        </DataTableFilterPanel>

        <UEmpty
          v-if="!isLoading && filteredRows.length === 0"
          :icon="hasActiveFilters ? 'i-lucide-search-x' : 'i-lucide-users'"
          :title="hasActiveFilters ? 'Nenhum cliente encontrado' : 'Nenhum cliente com documento capturado'"
          :description="hasActiveFilters
            ? 'Ajuste os filtros ou limpe a busca para ver outros clientes.'
            : 'Quando a captura trouxer documentos, cada cliente aparece em uma linha com os totais dele.'"
          variant="naked"
          :actions="hasActiveFilters
            ? [{ label: 'Limpar filtros', icon: 'i-lucide-filter-x', color: 'neutral', variant: 'outline', onClick: clearFilters }]
            : [{ label: 'Ver documentos', icon: 'i-lucide-files', color: 'neutral', variant: 'outline', to: '/fiscal/documentos' }]"
        />

        <template v-else>
          <UTable
            v-model:row-selection="rowSelection"
            v-model:column-visibility="columnVisibility"
            sticky
            :watch-options="{ deep: false }"
            :get-row-id="clientRowId"
            :data="pageRows"
            :columns="columns"
            :loading="isLoading"
            class="h-full min-h-0 w-full flex-1"
            :ui="{ ...sheetTableUi, base: `${sheetTableUi.base} min-w-7xl` }"
          >
            <template #cliente-cell="{ row }">
              <DataTableIdentity
                :title="row.original.client.name"
                :meta="row.original.client.tax_id ? formatTaxId(row.original.client.tax_id) : fiscalMissingValue"
              />
            </template>

            <template #certificado-cell="{ row }">
              <UBadge
                :label="fiscalClientCertificatePresentation(row.original.certificado_status).label"
                :color="fiscalClientCertificatePresentation(row.original.certificado_status).color"
                :icon="fiscalClientCertificatePresentation(row.original.certificado_status).icon"
                variant="subtle"
              />
            </template>

            <template #total-cell="{ row }">
              <span class="font-semibold text-highlighted">
                {{ formatFiscalCount(row.original.total) }}
              </span>
            </template>

            <template #saidas-cell="{ row }">
              <div>
                <span class="block font-medium tabular-nums text-highlighted">
                  {{ formatFiscalAmount(row.original.saidas.valor) }}
                </span>
                <span class="text-xs text-muted">
                  {{ formatFiscalCount(row.original.saidas.qtd) }} documentos
                </span>
              </div>
            </template>

            <template #entradas-cell="{ row }">
              <div>
                <span class="block font-medium tabular-nums text-highlighted">
                  {{ formatFiscalAmount(row.original.entradas.valor) }}
                </span>
                <span class="text-xs text-muted">
                  {{ formatFiscalCount(row.original.entradas.qtd) }} documentos
                </span>
              </div>
            </template>

            <template #modelos-cell="{ row }">
              <div v-if="modelVolumes(row.original.por_modelo).length" class="flex flex-wrap gap-1">
                <UBadge
                  v-for="model in modelVolumes(row.original.por_modelo)"
                  :key="model.model"
                  :label="`${model.label} ${formatFiscalCount(model.total)}`"
                  variant="subtle"
                  class="tabular-nums"
                />
              </div>
              <span v-else class="text-muted">{{ fiscalMissingValue }}</span>
            </template>

            <template #ultima_emissao-cell="{ row }">
              {{ formatFiscalDay(row.original.ultima_emissao_at) }}
            </template>

            <template #acoes-cell="{ row }">
              <DataTableRowActionsMenu
                :items="clientActions(row.original)"
                :label="`Ações para ${row.original.client.name}`"
                flush
              />
            </template>
          </UTable>

          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-muted">
              {{ formatFiscalCount(filteredRows.length) }} cliente(s) · página {{ currentPage }} de {{ pageCount }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
              <USelect
                :model-value="String(pageSize)"
                :items="[
                  { label: '25 por página', value: '25' },
                  { label: '50 por página', value: '50' },
                  { label: '100 por página', value: '100' }
                ]"
                size="sm"
                class="w-36"
                aria-label="Clientes por página"
                :disabled="isLoading"
                @update:model-value="setPageSize"
              />
              <UPagination
                v-model:page="currentPage"
                :total="filteredRows.length"
                :items-per-page="pageSize"
                :sibling-count="1"
                show-edges
                size="sm"
              />
            </div>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
