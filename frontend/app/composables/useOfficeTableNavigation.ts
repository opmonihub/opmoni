import type { SortingState } from '@tanstack/table-core'
import { operatorChoices, type DataTableColumnType, type DataTableFilterModel, type DataTableFilterOperator } from '~/components/data-table/filter-model'

// Only criteria travel through the authority-owned registry. In particular, table
// records, row selections, expanded groups and mutation drafts remain page-local.
type Criterion = string | number | boolean | null | (string | number | boolean)[]
type TableState = Record<string, Criterion>
const types: DataTableColumnType[] = ['text', 'number', 'date', 'option', 'multiOption']
const operators = types.flatMap(type => operatorChoices(type).map(option => option.value))

export function useOfficeTableNavigation(key: string, columns: string[], sortIds: string[] = [], options: { page?: boolean, pageSize?: boolean, views?: boolean } = {}) {
  function normalize(value: unknown): TableState {
    const input = value && typeof value === 'object' ? value as Record<string, unknown> : {}
    const seenSortIds = new Set<string>()
    const sorting = Array.isArray(input.sortIds)
      ? input.sortIds.flatMap((id, index) => {
          if (typeof id !== 'string' || !sortIds.includes(id) || seenSortIds.has(id)) return []
          seenSortIds.add(id)
          const direction = Array.isArray(input.sortDesc) ? input.sortDesc[index] : undefined
          return [{ id, desc: typeof direction === 'boolean' ? direction : false }]
        })
      : []
    const output: TableState = {
      search: typeof input.search === 'string' ? input.search.slice(0, 1000) : '',
      sortIds: sorting.map(sort => sort.id),
      sortDesc: sorting.map(sort => sort.desc)
    }
    if (options.page) output.page = Number.isSafeInteger(input.page) && Number(input.page) > 0 ? Number(input.page) : 1
    if (options.pageSize) output.pageSize = [25, 50, 100].includes(Number(input.pageSize)) ? Number(input.pageSize) : 25
    if (options.views) {
      output.viewMode = input.viewMode === 'table' ? 'table' : 'board'
      output.scopeMode = input.scopeMode === 'undated' ? 'undated' : 'month'
    }
    for (const column of columns) {
      output[`filter:${column}`] = []
      output[`operator:${column}`] = 'is'
      output[`type:${column}`] = null
      const values = input[`filter:${column}`]
      const operator = input[`operator:${column}`]
      if (!Array.isArray(values) || !operators.includes(operator as DataTableFilterOperator)) continue
      output[`filter:${column}`] = values.filter((item): item is string | number => typeof item === 'string' || (typeof item === 'number' && Number.isFinite(item)))
      output[`operator:${column}`] = operator as string
      if (types.includes(input[`type:${column}`] as DataTableColumnType)) output[`type:${column}`] = input[`type:${column}`] as string
    }
    return output
  }
  const state = useListNavigationState<TableState>(key, { defaults: () => normalize(null), normalize })
  const field = <T extends Criterion>(name: string, fallback: T) => computed<T>({
    get: () => (state.value[name] ?? fallback) as T,
    set: (value) => { state.value = normalize({ ...state.value, [name]: value }) }
  })
  const filterModels = computed<DataTableFilterModel[]>({
    get: (previous) => {
      const next = columns.flatMap((columnId) => {
        const values = state.value[`filter:${columnId}`]
        if (!Array.isArray(values) || !values.length) return []
        return [{
          columnId,
          operator: state.value[`operator:${columnId}`] as DataTableFilterOperator,
          values: values as (string | number)[],
          ...(state.value[`type:${columnId}`] ? { type: state.value[`type:${columnId}`] as DataTableColumnType } : {})
        }]
      })
      return JSON.stringify(previous) === JSON.stringify(next) ? previous! : next
    },
    set: (models) => {
      const next = { ...state.value }
      for (const column of columns) {
        next[`filter:${column}`] = []
        next[`operator:${column}`] = 'is'
        next[`type:${column}`] = null
      }
      for (const model of models) {
        if (!columns.includes(model.columnId)) continue
        next[`filter:${model.columnId}`] = model.values
        next[`operator:${model.columnId}`] = model.operator
        if (model.type) next[`type:${model.columnId}`] = model.type
      }
      state.value = normalize(next)
    }
  })
  const sorting = computed<SortingState>({
    get: (previous) => {
      const next = (state.value.sortIds as string[]).map((id, index) => ({ id, desc: (state.value.sortDesc as boolean[])[index] ?? false }))
      return JSON.stringify(previous) === JSON.stringify(next) ? previous! : next
    },
    set: (value) => {
      const supported = value.filter(sort => sortIds.includes(sort.id))
      state.value = normalize({ ...state.value, sortIds: supported.map(sort => sort.id), sortDesc: supported.map(sort => sort.desc) })
    }
  })
  return {
    search: field<string>('search', ''), filterModels, sorting,
    page: field<number>('page', 1), pageSize: field<number>('pageSize', 25),
    viewMode: field<'board' | 'table'>('viewMode', 'board'), scopeMode: field<'month' | 'undated'>('scopeMode', 'month')
  }
}
