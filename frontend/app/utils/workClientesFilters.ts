import {
  matchesFilters,
  type DataTableColumnType,
  type DataTableFilterColumn,
  type DataTableFilterModel,
  type DataTableFilterOption
} from '~/components/data-table/filter-model'
import { statusPresentation } from '~/composables/useWorkPresentation'
import type { WorkTaskStatus } from '~/types/work'

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
  department: string
  due_on: string | null
  empty: boolean
  taskId: number | null
}

const STATUS_OPTIONS: DataTableFilterOption[] = ([
  'todo',
  'doing',
  'done',
  'dismissed'
] as const).map(status => ({
  label: statusPresentation(status).label,
  value: status,
  color: statusPresentation(status).color
}))

// Wording matches the `Cascata` badge in workGroupedTable.cascadeLabel().
const CASCADE_OPTIONS: DataTableFilterOption[] = [
  { label: 'Cascata', value: 'true', color: 'warning' },
  { label: 'Sem cascata', value: 'false', color: 'neutral' }
]

function uniqueSorted(values: Iterable<string>): string[] {
  return [...new Set([...values].filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'))
}

function facetChoices(
  choices: readonly DataTableFilterOption[],
  selected: readonly string[],
  present: readonly string[],
  minimum: number
): DataTableFilterOption[] | null {
  if (selected.length > 0) return [...choices]
  const allowed = new Set(present)
  const next = choices.filter(choice => allowed.has(choice.value))
  return next.length < minimum ? null : next
}

export function workClientesFilterColumns(
  leaves: readonly WorkClientesFilterLeaf[],
  models: readonly DataTableFilterModel[]
): DataTableFilterColumn[] {
  const valuesOf = (id: string) => models.find(filter => filter.columnId === id)?.values.map(String) ?? []
  const columns: DataTableFilterColumn[] = []

  const statuses = facetChoices(
    STATUS_OPTIONS,
    valuesOf('status'),
    uniqueSorted(leaves.filter(leaf => leaf.status).map(leaf => leaf.status as string)),
    1
  )
  if (statuses?.length) {
    columns.push({
      id: 'status',
      label: 'Status',
      icon: 'i-lucide-circle-dot',
      options: statuses
    })
  }

  const departments = uniqueSorted(leaves.map(leaf => leaf.department))
  const departmentSelected = valuesOf('department')
  const departmentOptions = facetChoices(
    departments.map(value => ({ label: value, value, color: 'neutral' as const })),
    departmentSelected,
    departments,
    1
  )
  if (departmentOptions?.length) {
    columns.push({
      id: 'department',
      label: 'Depto.',
      icon: 'i-lucide-building-2',
      options: departmentOptions
    })
  }

  const cascades = facetChoices(
    CASCADE_OPTIONS,
    valuesOf('cascade'),
    uniqueSorted(leaves.map(leaf => String(leaf.cascade))),
    1
  )
  if (cascades?.length) {
    columns.push({
      id: 'cascade',
      label: 'Cascata',
      icon: 'i-lucide-git-branch',
      options: cascades
    })
  }

  const clients = uniqueSorted(leaves.map(leaf => leaf.clientName))
  const clientSelected = valuesOf('client')
  const clientOptions = facetChoices(
    clients.map(value => ({ label: value, value, color: 'neutral' as const })),
    clientSelected,
    clients,
    1
  )
  if (clientOptions?.length) {
    columns.push({
      id: 'client',
      label: 'Cliente',
      icon: 'i-lucide-users',
      options: clientOptions
    })
  }

  return columns
}

function readLeafValue(leaf: WorkClientesFilterLeaf, columnId: string): unknown {
  switch (columnId) {
    case 'status':
      return leaf.status ?? ''
    case 'department':
      return leaf.department
    case 'cascade':
      return String(leaf.cascade)
    case 'client':
      return leaf.clientName
    default:
      return ''
  }
}

export function matchesWorkClientesSearch(leaf: WorkClientesFilterLeaf, search: string): boolean {
  const term = search.trim().toLocaleLowerCase('pt-BR')
  if (!term) return true
  const haystack = [leaf.clientName, leaf.processName, leaf.title, leaf.department]
    .join(' ')
    .toLocaleLowerCase('pt-BR')
  return haystack.includes(term)
}

function filterColumnType(_columnId: string): DataTableColumnType {
  return 'option'
}

export function filterWorkClientesLeaves<T extends WorkClientesFilterLeaf>(
  leaves: readonly T[],
  filters: DataTableFilterModel[],
  search: string
): T[] {
  return leaves.filter((leaf) => {
    if (!matchesWorkClientesSearch(leaf, search)) return false
    if (!filters.length) return true
    return matchesFilters(
      filters,
      columnId => readLeafValue(leaf, columnId),
      filterColumnType
    )
  })
}

/**
 * Keep hierarchy: if a leaf matches, its process/client siblings that are empty placeholders
 * stay only when they alone represent an empty process still matching search/client/cascade filters.
 * Callers should filter leaves first, then rebuild grouping from the filtered set.
 */
export function hasWorkClientesActiveFilters(filters: DataTableFilterModel[], search: string): boolean {
  return filters.length > 0 || search.trim().length > 0
}
