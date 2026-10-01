import type { DataTableFilterColumn, DataTableFilterModel } from '../components/data-table/filter-model.ts'
import {
  WORK_CASCADE_FACET,
  WORK_CLIENT_FACET,
  WORK_STATUS_FACET,
  filterWorkFacetLeaves,
  hasWorkActiveFilters,
  workDepartmentFacetColumn,
  workDepartmentKey,
  workFixedFacetColumn,
  workValueFacetColumn
} from './workFacetFilters.ts'
import type { WorkTaskStatus } from '../types/work.ts'

export type WorkClientesFilterLeaf = {
  id: string
  clientId: number
  clientName: string
  processId: number
  processName: string
  processRatio: number
  cascade: boolean
  order: number
  title: string
  status: WorkTaskStatus | null
  department_id: number | null
  departmentName: string
  due_on: string | null
  empty: boolean
  taskId: number | null
}

function readLeafValue(leaf: WorkClientesFilterLeaf, columnId: string): unknown {
  switch (columnId) {
    case 'status':
      return leaf.status ?? ''
    case 'department':
      return workDepartmentKey(leaf)
    case 'cascade':
      return String(leaf.cascade)
    case 'client':
      return leaf.clientName
    default:
      return ''
  }
}

function searchHaystack(leaf: WorkClientesFilterLeaf): string {
  return [leaf.clientName, leaf.processName, leaf.title, leaf.departmentName].join(' ')
}

/**
 * Facets offered on Work › Clientes: what the operator scans this table by.
 * Modelo and status do processo belong to Processos, which groups by them.
 */
export function workClientesFilterColumns(
  leaves: readonly WorkClientesFilterLeaf[],
  models: readonly DataTableFilterModel[],
  departments: Iterable<{ id: number, name: string }> = []
): DataTableFilterColumn[] {
  const columns: DataTableFilterColumn[] = []

  for (const facet of [
    workFixedFacetColumn(
      WORK_STATUS_FACET,
      models,
      leaves.filter(leaf => leaf.status).map(leaf => leaf.status as string)
    ),
    workDepartmentFacetColumn(models, departments),
    workFixedFacetColumn(WORK_CASCADE_FACET, models, leaves.map(leaf => String(leaf.cascade))),
    workValueFacetColumn(WORK_CLIENT_FACET, models, leaves.map(leaf => leaf.clientName))
  ]) {
    if (facet) columns.push(facet)
  }

  return columns
}

export function matchesWorkClientesSearch(leaf: WorkClientesFilterLeaf, search: string): boolean {
  const term = search.trim().toLocaleLowerCase('pt-BR')
  if (!term) return true
  return searchHaystack(leaf).toLocaleLowerCase('pt-BR').includes(term)
}

/**
 * Keep hierarchy: if a leaf matches, its process/client siblings that are empty placeholders
 * stay only when they alone represent an empty process still matching search/client/cascade filters.
 * Callers should filter leaves first, then rebuild grouping from the filtered set.
 */
export function filterWorkClientesLeaves<T extends WorkClientesFilterLeaf>(
  leaves: readonly T[],
  filters: DataTableFilterModel[],
  search: string
): T[] {
  return filterWorkFacetLeaves(leaves, filters, search, readLeafValue, searchHaystack)
}

export function hasWorkClientesActiveFilters(filters: DataTableFilterModel[], search: string): boolean {
  return hasWorkActiveFilters(filters, search)
}
