import {
  matchesFilters,
  type DataTableColumnType,
  type DataTableFilterColumn,
  type DataTableFilterModel,
  type DataTableFilterOption
} from '../components/data-table/filter-model.ts'
import { statusPresentation } from '../composables/useWorkPresentation.ts'
import type { WorkTaskStatus } from '../types/work.ts'

/**
 * Facet-filter primitives shared by the Work grouped tables (Clientes, Processos).
 *
 * Those two files were 186 and 247 lines with ~120 identical: the same four
 * facets (status, departamento, cascata, cliente), the same `uniqueSorted` /
 * `facetChoices` / `matchesFilters` plumbing, and the same status and cascade
 * option lists — including the same comment about keeping the `Cascata` wording in
 * step with the badge in `workGroupedTable.cascadeLabel()`.
 *
 * A facet is one column: a fixed option list (status, cascata) or the values
 * present in the leaves (cliente, departamento, processo). Which columns a table
 * offers is still that table's decision — Processos adds modelo and status do
 * processo, Clientes does not.
 */

/** A facet whose option list is fixed and filtered down to what the leaves hold. */
export type WorkFixedFacet = {
  id: string
  label: string
  icon: string
  options: DataTableFilterOption[]
}

/** A facet whose option list is the distinct values in the leaves. */
export type WorkValueFacet = {
  id: string
  label: string
  icon: string
}

/**
 * Task status as filter options. Labels and colours come from
 * `statusPresentation`, so **A fazer** is never rendered as "Aberto" here either.
 */
export const WORK_STATUS_FILTER_OPTIONS: DataTableFilterOption[] = ([
  'todo',
  'doing',
  'done',
  'dismissed'
] as const satisfies readonly WorkTaskStatus[]).map(status => ({
  label: statusPresentation(status).label,
  value: status,
  color: statusPresentation(status).color
}))

/** Wording matches the `Cascata` badge in workGroupedTable.cascadeLabel(). */
export const WORK_CASCADE_FILTER_OPTIONS: DataTableFilterOption[] = [
  { label: 'Cascata', value: 'true', color: 'warning' },
  { label: 'Sem cascata', value: 'false', color: 'neutral' }
]

/** The facets every Work grouped table offers, in the order the filter bar shows them. */
export const WORK_DEPARTMENT_FACET: WorkValueFacet = { id: 'department', label: 'Depto.', icon: 'i-lucide-building-2' }
export const WORK_CASCADE_FACET: WorkFixedFacet = { id: 'cascade', label: 'Cascata', icon: 'i-lucide-git-branch', options: WORK_CASCADE_FILTER_OPTIONS }
export const WORK_STATUS_FACET: WorkFixedFacet = { id: 'status', label: 'Status', icon: 'i-lucide-circle-dot', options: WORK_STATUS_FILTER_OPTIONS }
export const WORK_CLIENT_FACET: WorkValueFacet = { id: 'client', label: 'Cliente', icon: 'i-lucide-users' }

/** Distinct non-empty values, pt-BR sorted — the option order operators read. */
export function workFacetValues(values: Iterable<string>): string[] {
  return [...new Set([...values].filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'))
}

/**
 * Narrow a facet's options to the ones the current leaves actually hold, unless
 * the operator has already picked from it — a selection is never dropped, or the
 * filter they are editing would vanish from the menu under their cursor.
 *
 * `null` means "fewer than `minimum` options are left", i.e. no column.
 */
export function workFacetChoices(
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

/** Selected values for one facet, as strings. */
export function workFacetSelection(models: readonly DataTableFilterModel[], id: string): string[] {
  return models.find(filter => filter.columnId === id)?.values.map(String) ?? []
}

/** Column for a fixed option list, or `null` when nothing is left to offer. */
export function workFixedFacetColumn(
  facet: WorkFixedFacet,
  models: readonly DataTableFilterModel[],
  present: readonly string[]
): DataTableFilterColumn | null {
  const options = workFacetChoices(
    facet.options,
    workFacetSelection(models, facet.id),
    present,
    1
  )
  if (!options?.length) return null
  return { id: facet.id, label: facet.label, icon: facet.icon, options }
}

/** Column for the distinct values in the leaves, or `null` when there are none. */
export function workValueFacetColumn(
  facet: WorkValueFacet,
  models: readonly DataTableFilterModel[],
  values: Iterable<string>
): DataTableFilterColumn | null {
  const present = workFacetValues(values)
  const options = workFacetChoices(
    present.map(value => ({ label: value, value, color: 'neutral' as const })),
    workFacetSelection(models, facet.id),
    present,
    1
  )
  if (!options?.length) return null
  return { id: facet.id, label: facet.label, icon: facet.icon, options }
}

/** Every Work facet is an option list; there is no free-text column here. */
export function workFacetColumnType(_columnId: string): DataTableColumnType {
  return 'option'
}

/**
 * Filter leaves by the facet filters and the search box, in that order.
 *
 * `haystack` is the page's own search scope — Clientes searches cliente,
 * processo and tarefa; Processos adds the model name. Keeping it at the call site
 * is deliberate: it is a product decision what a search box promises to find.
 */
export function filterWorkFacetLeaves<T>(
  leaves: readonly T[],
  filters: readonly DataTableFilterModel[],
  search: string,
  read: (leaf: T, columnId: string) => unknown,
  haystack: (leaf: T) => string
): T[] {
  const term = search.trim().toLocaleLowerCase('pt-BR')

  return leaves.filter((leaf) => {
    if (term && !haystack(leaf).toLocaleLowerCase('pt-BR').includes(term)) return false
    if (!filters.length) return true
    return matchesFilters(
      [...filters],
      columnId => read(leaf, columnId),
      workFacetColumnType
    )
  })
}

/** Whether the filter bar holds anything, which is what the empty state asks. */
export function hasWorkActiveFilters(filters: readonly DataTableFilterModel[], search: string): boolean {
  return filters.length > 0 || search.trim().length > 0
}
