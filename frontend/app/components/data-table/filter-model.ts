export type DataTableColumnType = 'text' | 'number' | 'date' | 'option' | 'multiOption'

export type DataTableFilterOption = {
  label: string
  value: string
  color?: 'neutral' | 'primary' | 'success' | 'info' | 'warning' | 'error'
}

export type DataTableFilterColumn = {
  id: string
  label: string
  icon: string
  type?: DataTableColumnType
  options?: DataTableFilterOption[]
  min?: number
  max?: number
}

type OperatorTarget = 'single' | 'multiple'

type OperatorDetail<T extends string> = {
  value: T
  label: string
  target: OperatorTarget
  singularOf?: T
  pluralOf?: T
  isNegated: boolean
}

export type TextFilterOperator = 'contains' | 'does not contain'

export type NumberFilterOperator
  = | 'is'
    | 'is not'
    | 'is greater than'
    | 'is greater than or equal to'
    | 'is less than'
    | 'is less than or equal to'
    | 'is between'
    | 'is not between'

export type DateFilterOperator
  = | 'is'
    | 'is not'
    | 'is before'
    | 'is on or after'
    | 'is after'
    | 'is on or before'
    | 'is between'
    | 'is not between'

export type OptionFilterOperator = 'is' | 'is not' | 'is any of' | 'is none of'

export type MultiOptionFilterOperator
  = | 'include'
    | 'exclude'
    | 'include any of'
    | 'exclude if all'
    | 'include all of'
    | 'exclude if any of'

export type DataTableFilterOperator
  = | TextFilterOperator
    | NumberFilterOperator
    | DateFilterOperator
    | OptionFilterOperator
    | MultiOptionFilterOperator

export type DataTableFilterModel = {
  columnId: string
  type?: DataTableColumnType
  operator: DataTableFilterOperator
  values: Array<string | number>
}

const textOperators = {
  'contains': { value: 'contains', label: 'contém', target: 'single', isNegated: false },
  'does not contain': { value: 'does not contain', label: 'não contém', target: 'single', isNegated: true }
} as const satisfies Record<TextFilterOperator, OperatorDetail<TextFilterOperator>>

const numberOperators = {
  'is': { value: 'is', label: 'é', target: 'single', singularOf: 'is between', isNegated: false },
  'is not': { value: 'is not', label: 'não é', target: 'single', singularOf: 'is not between', isNegated: true },
  'is greater than': { value: 'is greater than', label: 'é maior que', target: 'single', singularOf: 'is between', isNegated: false },
  'is greater than or equal to': { value: 'is greater than or equal to', label: 'é maior ou igual a', target: 'single', singularOf: 'is between', isNegated: false },
  'is less than': { value: 'is less than', label: 'é menor que', target: 'single', singularOf: 'is between', isNegated: false },
  'is less than or equal to': { value: 'is less than or equal to', label: 'é menor ou igual a', target: 'single', singularOf: 'is between', isNegated: false },
  'is between': { value: 'is between', label: 'está entre', target: 'multiple', pluralOf: 'is', isNegated: false },
  'is not between': { value: 'is not between', label: 'não está entre', target: 'multiple', pluralOf: 'is not', isNegated: true }
} as const satisfies Record<NumberFilterOperator, OperatorDetail<NumberFilterOperator>>

const dateOperators = {
  'is': { value: 'is', label: 'é', target: 'single', singularOf: 'is between', isNegated: false },
  'is not': { value: 'is not', label: 'não é', target: 'single', singularOf: 'is not between', isNegated: true },
  'is before': { value: 'is before', label: 'é antes de', target: 'single', singularOf: 'is between', isNegated: false },
  'is on or after': { value: 'is on or after', label: 'é em ou depois de', target: 'single', singularOf: 'is between', isNegated: false },
  'is after': { value: 'is after', label: 'é depois de', target: 'single', singularOf: 'is between', isNegated: false },
  'is on or before': { value: 'is on or before', label: 'é em ou antes de', target: 'single', singularOf: 'is between', isNegated: false },
  'is between': { value: 'is between', label: 'está entre', target: 'multiple', pluralOf: 'is', isNegated: false },
  'is not between': { value: 'is not between', label: 'não está entre', target: 'multiple', pluralOf: 'is not', isNegated: true }
} as const satisfies Record<DateFilterOperator, OperatorDetail<DateFilterOperator>>

const optionOperators = {
  'is': { value: 'is', label: 'é', target: 'single', singularOf: 'is any of', isNegated: false },
  'is not': { value: 'is not', label: 'não é', target: 'single', singularOf: 'is none of', isNegated: true },
  'is any of': { value: 'is any of', label: 'é qualquer um de', target: 'multiple', pluralOf: 'is', isNegated: false },
  'is none of': { value: 'is none of', label: 'não é nenhum de', target: 'multiple', pluralOf: 'is not', isNegated: true }
} as const satisfies Record<OptionFilterOperator, OperatorDetail<OptionFilterOperator>>

const multiOptionOperators = {
  'include': { value: 'include', label: 'inclui', target: 'single', singularOf: 'include any of', isNegated: false },
  'exclude': { value: 'exclude', label: 'exclui', target: 'single', singularOf: 'exclude if any of', isNegated: true },
  'include any of': { value: 'include any of', label: 'inclui qualquer um de', target: 'multiple', pluralOf: 'include', isNegated: false },
  'exclude if all': { value: 'exclude if all', label: 'exclui se tiver todos', target: 'multiple', pluralOf: 'exclude', isNegated: true },
  'include all of': { value: 'include all of', label: 'inclui todos', target: 'multiple', pluralOf: 'include', isNegated: false },
  'exclude if any of': { value: 'exclude if any of', label: 'exclui se tiver algum', target: 'multiple', pluralOf: 'exclude', isNegated: true }
} as const satisfies Record<MultiOptionFilterOperator, OperatorDetail<MultiOptionFilterOperator>>

const operatorsByType = {
  text: textOperators,
  number: numberOperators,
  date: dateOperators,
  option: optionOperators,
  multiOption: multiOptionOperators
}

const defaultOperator: Record<DataTableColumnType, Record<OperatorTarget, DataTableFilterOperator>> = {
  text: { single: 'contains', multiple: 'contains' },
  number: { single: 'is', multiple: 'is between' },
  date: { single: 'is', multiple: 'is between' },
  option: { single: 'is', multiple: 'is any of' },
  multiOption: { single: 'include', multiple: 'include any of' }
}

export function columnType(column?: DataTableFilterColumn): DataTableColumnType {
  if (column?.type) return column.type
  return 'option'
}

export function operatorChoices(type: DataTableColumnType) {
  return Object.values(operatorsByType[type])
}

export function operatorDetail(type: DataTableColumnType, operator: DataTableFilterOperator) {
  const details = operatorsByType[type] as Record<string, OperatorDetail<DataTableFilterOperator>>
  return details[operator] ?? operatorChoices(type)[0]
}

export function operatorLabel(type: DataTableColumnType, operator: DataTableFilterOperator) {
  return operatorDetail(type, operator).label
}

export function defaultFilterOperator(type: DataTableColumnType, target: OperatorTarget = 'single') {
  return defaultOperator[type][target]
}

export function editorKind(type: DataTableColumnType, operator: DataTableFilterOperator) {
  const target = operatorDetail(type, operator).target
  if (type === 'text') return 'text' as const
  if (type === 'number') return target === 'multiple' ? 'number-range' as const : 'number' as const
  if (type === 'date') return target === 'multiple' ? 'date-range' as const : 'date' as const
  return null
}

/**
 * One value uses the singular operator. Two or more use its plural.
 * Stays on the chosen operator while the value count does not cross that line,
 * so "inclui todos" does not collapse back to "inclui qualquer um".
 */
export function determineNewOperator(
  type: DataTableColumnType,
  previous: readonly unknown[],
  next: readonly unknown[],
  current: DataTableFilterOperator
): DataTableFilterOperator {
  const before = previous.length
  const after = next.length
  if (before === after || (before >= 2 && after >= 2) || (before <= 1 && after <= 1)) return current

  const details = operatorDetail(type, current)
  if (before < after && after >= 2) return details.singularOf ?? current
  if (before > after && after <= 1) return details.pluralOf ?? current
  return current
}

function dayNumber(value: string) {
  const [year, month, day] = value.split('-').map(Number)
  if (!year || !month || !day) return Number.NaN
  return Date.UTC(year, month - 1, day)
}

function asText(value: unknown) {
  return String(value ?? '').trim().toLocaleLowerCase('pt-BR')
}

export function matchesFilter(input: unknown, filter: DataTableFilterModel, type: DataTableColumnType): boolean {
  const values = filter.values
  if (!values.length) return true

  if (type === 'text') {
    const needle = asText(values[0])
    if (!needle) return true
    const found = asText(input).includes(needle)
    return filter.operator === 'does not contain' ? !found : found
  }

  if (type === 'number') {
    const value = typeof input === 'number' ? input : Number(input)
    const first = Number(values[0])
    const second = Number(values[1])
    if (!Number.isFinite(value) || !Number.isFinite(first)) return false
    const operator = filter.operator as NumberFilterOperator
    if (operator === 'is') return value === first
    if (operator === 'is not') return value !== first
    if (operator === 'is greater than') return value > first
    if (operator === 'is greater than or equal to') return value >= first
    if (operator === 'is less than') return value < first
    if (operator === 'is less than or equal to') return value <= first
    if (!Number.isFinite(second)) return false
    const inside = value >= Math.min(first, second) && value <= Math.max(first, second)
    return operator === 'is not between' ? !inside : inside
  }

  if (type === 'date') {
    const value = dayNumber(String(input ?? ''))
    const first = dayNumber(String(values[0] ?? ''))
    const second = dayNumber(String(values[1] ?? ''))
    if (!Number.isFinite(value) || !Number.isFinite(first)) return false
    const operator = filter.operator as DateFilterOperator
    if (operator === 'is') return value === first
    if (operator === 'is not') return value !== first
    if (operator === 'is before') return value < first
    if (operator === 'is on or after') return value >= first
    if (operator === 'is after') return value > first
    if (operator === 'is on or before') return value <= first
    if (!Number.isFinite(second)) return false
    const start = Math.min(first, second)
    const end = Math.max(first, second)
    const inside = value >= start && value <= end
    return operator === 'is not between' ? !inside : inside
  }

  if (type === 'option') {
    const found = values.some(value => asText(value) === asText(input))
    return filter.operator === 'is not' || filter.operator === 'is none of' ? !found : found
  }

  const row = Array.isArray(input) ? input.map(asText) : [asText(input)]
  const selected = values.map(asText)
  const overlap = selected.filter(value => row.includes(value)).length
  const operator = filter.operator as MultiOptionFilterOperator
  if (operator === 'include' || operator === 'include any of') return overlap > 0
  if (operator === 'exclude' || operator === 'exclude if any of') return overlap === 0
  if (operator === 'include all of') return overlap === selected.length
  return overlap !== selected.length
}

export function matchesFilters(
  filters: DataTableFilterModel[],
  read: (columnId: string, type: DataTableColumnType) => unknown,
  typeOf: (columnId: string) => DataTableColumnType
) {
  return filters.every(filter => matchesFilter(read(filter.columnId, typeOf(filter.columnId)), filter, filter.type ?? typeOf(filter.columnId)))
}
