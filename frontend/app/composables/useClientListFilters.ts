import { refDebounced } from '@vueuse/core'
import type { Ref } from 'vue'
import type { DataTableFilterColumn, DataTableFilterModel, DataTableFilterOperator } from '~/components/data-table/Filter.vue'
import type {
  ClientPortfolioView,
  ClientSheet,
  ClientStatus,
  ClientTag,
  DeadlineStatus,
  TaxRegime
} from '~/types/client'
import {
  clientDocumentStateOptions,
  clientRegimeFilterOptions,
  clientStatusFilterOptions
} from '~/utils/clientListFilterOptions'
import { pinnedDocumentFilter, singleValueFacets, tagFacets, type PortfolioListing } from '~/utils/portfolioFilters'

type UseClientListFiltersOptions = {
  listing: Ref<PortfolioListing | null | undefined>
  rows: Ref<ClientSheet[]>
  matchingTotal: Ref<number>
  listMode: Ref<'sheet' | 'paged'>
  listStatus: Ref<string>
  tagCatalog: Ref<{ data: ClientTag[] } | null | undefined>
  view: Ref<ClientPortfolioView | 'all'>
}

export function useClientListFilters(options: UseClientListFiltersOptions) {
  const search = ref('')
  const debouncedSearch = refDebounced(search, 350)
  const statusFilter = ref<ClientStatus[]>([])
  const regimeFilter = ref<TaxRegime[]>([])
  const tagFilter = ref<number[]>([])
  const certificateFilter = ref<DeadlineStatus[]>([])
  const poaFilter = ref<DeadlineStatus[]>([])
  const filterOperator = ref<Partial<Record<string, DataTableFilterOperator>>>({})

  watch(options.listing, (value) => {
    if (!value) return
    const pinned = pinnedDocumentFilter(value)
    if (pinned === 'certificate') certificateFilter.value = []
    if (pinned === 'poa') poaFilter.value = []
  })

  const columnFilters = computed(() => {
    const pinned = options.listing.value ? pinnedDocumentFilter(options.listing.value) : null
    return {
      status: statusFilter.value.length ? statusFilter.value : undefined,
      tax_regime: regimeFilter.value.length ? regimeFilter.value : undefined,
      tag_id: tagFilter.value.length ? tagFilter.value : undefined,
      certificate_status: pinned === 'certificate' || !certificateFilter.value.length ? undefined : certificateFilter.value,
      poa_status: pinned === 'poa' || !poaFilter.value.length ? undefined : poaFilter.value
    }
  })

  const hasActiveFilters = computed(() =>
    !!debouncedSearch.value
    || statusFilter.value.length > 0
    || regimeFilter.value.length > 0
    || tagFilter.value.length > 0
    || certificateFilter.value.length > 0
    || poaFilter.value.length > 0
    || options.view.value !== 'all'
  )

  const canFacet = computed(() =>
    options.listMode.value === 'sheet'
    && options.listStatus.value === 'success'
    && options.rows.value.length > 0
    && options.rows.value.length === options.matchingTotal.value
  )

  function facetChoices<T extends { value: string }>(
    choices: readonly T[],
    selected: readonly string[],
    present: readonly string[],
    minimum: number
  ) {
    if (selected.length > 0 || !canFacet.value) return [...choices]
    const allowed = new Set(present)
    const next = choices.filter(choice => allowed.has(choice.value))
    return next.length < minimum ? null : next
  }

  const filterColumns = computed(() => {
    const pinned = options.listing.value ? pinnedDocumentFilter(options.listing.value) : null
    const columns: DataTableFilterColumn[] = []

    const tags = facetChoices(
      (options.tagCatalog.value?.data ?? []).map(tag => ({
        label: tag.name,
        value: String(tag.id),
        color: tag.color
      })),
      tagFilter.value.map(String),
      tagFacets(options.rows.value).map(String),
      1
    )
    if (tags?.length) {
      columns.push({ id: 'tag', label: 'Tags', icon: 'i-lucide-tags', options: tags })
    }

    const regimes = facetChoices(
      clientRegimeFilterOptions,
      regimeFilter.value,
      singleValueFacets(options.rows.value.map(client => client.tax_regime)),
      2
    )
    if (regimes) {
      columns.push({ id: 'regime', label: 'Regime', icon: 'i-lucide-scale', options: regimes })
    }

    const situations = facetChoices(
      clientStatusFilterOptions,
      statusFilter.value,
      singleValueFacets(options.rows.value.map(client => client.status)),
      2
    )
    if (situations) {
      columns.push({ id: 'status', label: 'Situação', icon: 'i-lucide-circle-dot', options: situations })
    }

    if (pinned !== 'certificate') {
      const certificates = facetChoices(
        clientDocumentStateOptions,
        certificateFilter.value,
        singleValueFacets(options.rows.value.map(client => client.certificate_status)),
        2
      )
      if (certificates) {
        columns.push({ id: 'certificate', label: 'Cert. A1', icon: 'i-lucide-key-round', options: certificates })
      }
    }

    if (pinned !== 'poa') {
      const powers = facetChoices(
        clientDocumentStateOptions,
        poaFilter.value,
        singleValueFacets(options.rows.value.map(client => client.ecac_power_of_attorney_status)),
        2
      )
      if (powers) {
        columns.push({ id: 'poa', label: 'e-CAC', icon: 'i-lucide-file-key-2', options: powers })
      }
    }

    return columns
  })

  function optionModel(columnId: string, values: string[]): DataTableFilterModel | undefined {
    if (!values.length) return undefined
    return {
      columnId,
      operator: filterOperator.value[columnId] ?? (values.length > 1 ? 'is any of' : 'is'),
      values
    }
  }

  const filterModels = computed<DataTableFilterModel[]>(() => {
    const pinned = options.listing.value ? pinnedDocumentFilter(options.listing.value) : null
    return [
      optionModel('tag', tagFilter.value.map(String)),
      optionModel('regime', regimeFilter.value),
      optionModel('status', statusFilter.value),
      optionModel('certificate', pinned === 'certificate' ? [] : certificateFilter.value),
      optionModel('poa', pinned === 'poa' ? [] : poaFilter.value)
    ].filter(model => model !== undefined)
  })

  const activeFilterCount = computed(() => filterModels.value.length)

  function onFilters(models: DataTableFilterModel[]) {
    const valuesOf = (id: string) => models.find(filter => filter.columnId === id)?.values ?? []
    tagFilter.value = valuesOf('tag').map(Number)
    regimeFilter.value = valuesOf('regime') as TaxRegime[]
    statusFilter.value = valuesOf('status') as ClientStatus[]
    certificateFilter.value = valuesOf('certificate') as DeadlineStatus[]
    poaFilter.value = valuesOf('poa') as DeadlineStatus[]
    filterOperator.value = Object.fromEntries(models.map(model => [model.columnId, model.operator]))
  }

  function applySavedFilter(preset: { q: string, filters: DataTableFilterModel[] }) {
    search.value = preset.q
    onFilters(preset.filters)
  }

  function clearAppliedFilters() {
    onFilters([])
  }

  function clearSearch() {
    search.value = ''
  }

  return {
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
  }
}
