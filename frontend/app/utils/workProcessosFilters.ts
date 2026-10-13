import type { DataTableFilterColumn, DataTableFilterModel, DataTableFilterOption } from '../components/data-table/filter-model.ts'
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

export type WorkProcessosFilterLeaf = {
  id: string
  processKey: string
  processId: number
  processName: string
  processStatus: string
  processDueOn: string | null
  templateId: number | null
  templateName: string
  clientId: number
  clientName: string
  cascade: boolean
  title: string
  status: WorkTaskStatus | null
  department_id: number | null
  departmentName: string
  due_on: string | null
  empty: boolean
  taskId: number | null
}

const PROCESS_STATUS_OPTIONS: DataTableFilterOption[] = [
  { label: 'A fazer', value: 'open', color: 'info' },
  { label: 'Em progresso', value: 'in_progress', color: 'warning' },
  { label: 'Concluído', value: 'done', color: 'success' }
]

const PROCESS_FACET = { id: 'process', label: 'Processo', icon: 'i-lucide-layers' } as const
const PROCESS_STATUS_FACET = { id: 'processStatus', label: 'Status do processo', icon: 'i-lucide-activity', options: PROCESS_STATUS_OPTIONS } as const

/**
 * Modelos are an id → name pair rather than a flat value list, because one model
 * fans out into a process per client. Options follow the model name, not the id.
 */
function templateOptions(leaves: readonly WorkProcessosFilterLeaf[]): DataTableFilterOption[] {
  const templates = new Map<number, string>()
  for (const leaf of leaves) {
    if (leaf.templateId !== null && leaf.templateName) {
      templates.set(leaf.templateId, leaf.templateName)
    }
  }
  return [...templates.entries()]
    .sort(([, left], [, right]) => left.localeCompare(right, 'pt-BR'))
    .map(([id, name]) => ({ label: name, value: String(id), color: 'neutral' as const }))
}

/**
 * Always present, even with no options: a model already picked in the filter bar
 * must keep its column when the leaves hold none of it, or the operator edits
 * their way out of the filter.
 */
function templateColumn(leaves: readonly WorkProcessosFilterLeaf[]): DataTableFilterColumn {
  return { id: 'template', label: 'Modelo', icon: 'i-lucide-shapes', options: templateOptions(leaves) }
}

/**
 * Facets offered on Work › Processos. This view groups by modelo and processo, so
 * it filters by them too — which is the only reason the model facet exists at all.
 */
export function workProcessosFilterColumns(
  leaves: readonly WorkProcessosFilterLeaf[],
  models: readonly DataTableFilterModel[],
  departments: Iterable<{ id: number, name: string }> = []
): DataTableFilterColumn[] {
  const columns: DataTableFilterColumn[] = []

  for (const facet of [
    templateColumn(leaves),
    workValueFacetColumn(PROCESS_FACET, models, leaves.map(leaf => leaf.processName)),
    workValueFacetColumn(WORK_CLIENT_FACET, models, leaves.map(leaf => leaf.clientName)),
    workFixedFacetColumn(
      PROCESS_STATUS_FACET,
      models,
      leaves.map(leaf => leaf.processStatus)
    ),
    workFixedFacetColumn(
      WORK_STATUS_FACET,
      models,
      leaves.filter(leaf => leaf.status).map(leaf => leaf.status as string)
    ),
    workDepartmentFacetColumn(models, departments),
    workFixedFacetColumn(WORK_CASCADE_FACET, models, leaves.map(leaf => String(leaf.cascade)))
  ]) {
    if (facet) columns.push(facet)
  }

  return columns
}

function readLeafValue(leaf: WorkProcessosFilterLeaf, columnId: string): unknown {
  switch (columnId) {
    case 'process':
      return leaf.processName
    case 'template':
      return leaf.templateId === null ? '' : String(leaf.templateId)
    case 'processStatus':
      return leaf.processStatus
    case 'client':
      return leaf.clientName
    case 'status':
      return leaf.status ?? ''
    case 'department':
      return workDepartmentKey(leaf)
    case 'cascade':
      return String(leaf.cascade)
    default:
      return ''
  }
}

function searchHaystack(leaf: WorkProcessosFilterLeaf): string {
  return [leaf.processName, leaf.templateName, leaf.clientName, leaf.title, leaf.departmentName].join(' ')
}

export function matchesWorkProcessosSearch(leaf: WorkProcessosFilterLeaf, search: string): boolean {
  const term = search.trim().toLocaleLowerCase('pt-BR')
  if (!term) return true
  return searchHaystack(leaf).toLocaleLowerCase('pt-BR').includes(term)
}

export function filterWorkProcessosLeaves<T extends WorkProcessosFilterLeaf>(
  leaves: readonly T[],
  filters: DataTableFilterModel[],
  search: string
): T[] {
  return filterWorkFacetLeaves(leaves, filters, search, readLeafValue, searchHaystack)
}

export function hasWorkProcessosActiveFilters(filters: DataTableFilterModel[], search: string): boolean {
  return hasWorkActiveFilters(filters, search)
}
