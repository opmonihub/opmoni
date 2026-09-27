import type {
  DataTableFilterColumn,
  DataTableFilterModel,
  DataTableFilterOperator,
  DataTableFilterOption
} from '~/components/data-table/Filter.vue'
import type {
  ClientStatus,
  ClientTag,
  DeadlineStatus,
  TaxRegime
} from '~/types/client'
import { deadlineStatusAppearance } from '~/utils/portfolioLabels'

// `satisfies` (not `: DataTableFilterOption[]`) so each `value` is checked
// against the real domain union — a typo in a filter value used to compile.
export const clientStatusFilterOptions = [
  { label: 'Ativo', value: 'active', color: 'success' },
  { label: 'Inativo', value: 'inactive', color: 'neutral' }
] satisfies (DataTableFilterOption & { value: ClientStatus })[]

export const clientRegimeFilterOptions = [
  { label: 'MEI', value: 'mei', color: 'info' },
  { label: 'Simples Nacional', value: 'simple_national', color: 'info' },
  { label: 'Lucro presumido', value: 'presumed_profit', color: 'info' },
  { label: 'Lucro real', value: 'actual_profit', color: 'info' },
  { label: 'Outro', value: 'other', color: 'info' },
  { label: 'Não se aplica', value: 'not_applicable', color: 'info' }
] satisfies (DataTableFilterOption & { value: TaxRegime })[]

export const clientDocumentStateOptions = [
  { label: 'Sem cadastro', value: 'missing', color: deadlineStatusAppearance.missing.color },
  { label: 'Válido', value: 'valid', color: deadlineStatusAppearance.valid.color },
  { label: 'A vencer', value: 'expiring', color: deadlineStatusAppearance.expiring.color },
  { label: 'Vencido', value: 'expired', color: deadlineStatusAppearance.expired.color }
] satisfies (DataTableFilterOption & { value: DeadlineStatus })[]

export type ClientListFilterRefs = {
  statusFilter: ClientStatus[]
  regimeFilter: TaxRegime[]
  tagFilter: number[]
  certificateFilter: DeadlineStatus[]
  poaFilter: DeadlineStatus[]
  filterOperator: Partial<Record<string, DataTableFilterOperator>>
}

export type ClientListFilterColumnSource = {
  tags: ClientTag[]
  rows: Array<{
    tax_regime: string | null
    status: string
    certificate_status: string
    ecac_power_of_attorney_status: string
    tags?: ReadonlyArray<{ id: number }>
  }>
}

export type { DataTableFilterColumn, DataTableFilterModel }
