import {
  matchesFilters,
  type DataTableColumnType,
  type DataTableFilterColumn,
  type DataTableFilterModel,
  type DataTableFilterOption
} from '../components/data-table/filter-model.ts'
import { statusPresentation } from '../composables/useWorkPresentation.ts'
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

const PROCESS_STATUS_OPTIONS: DataTableFilterOption[] = [
  { label: 'A fazer', value: 'open', color: 'info' },
  { label: 'Em progresso', value: 'in_progress', color: 'warning' },
  { label: 'Concluído', value: 'done', color: 'success' }
]

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

export function workProcessosFilterColumns(
  leaves: readonly WorkProcessosFilterLeaf[],
  models: readonly DataTableFilterModel[]
): DataTableFilterColumn[] {
  const valuesOf = (id: string) => models.find(filter => filter.columnId === id)?.values.map(String) ?? []
  const columns: DataTableFilterColumn[] = []

  const templates = new Map<number, string>()
  for (const leaf of leaves) {
    if (leaf.templateId !== null && leaf.templateName) {
      templates.set(leaf.templateId, leaf.templateName)
    }
  }
  const templateOptions = [...templates.entries()]
    .sort(([, left], [, right]) => left.localeCompare(right, 'pt-BR'))
    .map(([id, name]) => ({ label: name, value: String(id), color: 'neutral' as const }))
  const selectedTemplates = valuesOf('template')
  if (templateOptions.length || selectedTemplates.length) {
    columns.push({
      id: 'template',
      label: 'Modelo',
      icon: 'i-lucide-shapes',
      options: templateOptions
    })
  }

  const processes = uniqueSorted(leaves.map(leaf => leaf.processName))
  const processSelected = valuesOf('process')
  const processOptions = facetChoices(
    processes.map(value => ({ label: value, value, color: 'neutral' as const })),
    processSelected,
    processes,
    1
  )
  if (processOptions?.length) {
    columns.push({
      id: 'process',
      label: 'Processo',
      icon: 'i-lucide-layers',
      options: processOptions
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

  const processStatuses = facetChoices(
    PROCESS_STATUS_OPTIONS,
    valuesOf('processStatus'),
    uniqueSorted(leaves.map(leaf => leaf.processStatus)),
    1
  )
  if (processStatuses?.length) {
    columns.push({
      id: 'processStatus',
      label: 'Status do processo',
      icon: 'i-lucide-activity',
      options: processStatuses
    })
  }

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
      return leaf.department
    case 'cascade':
      return String(leaf.cascade)
    default:
      return ''
  }
}

export function matchesWorkProcessosSearch(leaf: WorkProcessosFilterLeaf, search: string): boolean {
  const term = search.trim().toLocaleLowerCase('pt-BR')
  if (!term) return true
  const haystack = [leaf.processName, leaf.templateName, leaf.clientName, leaf.title, leaf.department]
    .join(' ')
    .toLocaleLowerCase('pt-BR')
  return haystack.includes(term)
}

function filterColumnType(_columnId: string): DataTableColumnType {
  return 'option'
}

export function filterWorkProcessosLeaves<T extends WorkProcessosFilterLeaf>(
  leaves: readonly T[],
  filters: DataTableFilterModel[],
  search: string
): T[] {
  return leaves.filter((leaf) => {
    if (!matchesWorkProcessosSearch(leaf, search)) return false
    if (!filters.length) return true
    return matchesFilters(
      filters,
      columnId => readLeafValue(leaf, columnId),
      filterColumnType
    )
  })
}

export function hasWorkProcessosActiveFilters(filters: DataTableFilterModel[], search: string): boolean {
  return filters.length > 0 || search.trim().length > 0
}
