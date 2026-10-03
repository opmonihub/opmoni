import {
  defaultFilterOperator,
  determineNewOperator,
  operatorDetail,
  operatorLabel,
  type DataTableColumnType,
  type DataTableFilterModel,
  type DataTableFilterOperator,
  type DataTableFilterOption
} from '../components/data-table/filter-model.ts'

export type FilterPanelControl = 'select' | 'multi' | 'text' | 'date-range' | 'number-range'

/**
 * O que o campo está editando antes de ir para o modelo.
 *
 * Select e multi não passam por aqui: eles gravam na hora. Texto e intervalo
 * ficam neste rascunho até o Aplicar, e fechar o painel sem aplicar é só
 * descartar este objeto.
 */
export type FilterPanelDraft = {
  text: string
  from: string
  to: string
  operator: DataTableFilterOperator
}

export type FilterPanelColumn = {
  id: string
  label: string
  icon?: string
  control: FilterPanelControl
  options?: DataTableFilterOption[]
  /** Mostra o seletor de operador. Sem a flag, o campo só inclui. */
  operators?: boolean
  /** Mensagem de erro, ou null quando o rascunho serve. Vazio não é consultado. */
  validate?: (draft: FilterPanelDraft) => string | null
}

export type FilterPanelChip = {
  columnId: string
  label: string
  value: string
}

export type ApplyDraftsResult = {
  ok: boolean
  model: DataTableFilterModel[]
  errors: Record<string, string>
}

/**
 * Colunas de opção da paleta antiga, no formato do painel.
 * `multi` porque a paleta marcava vários valores; `operators` liga a negação.
 */
export function toPanelColumns(
  columns: ReadonlyArray<{ id: string, label: string, icon?: string, options?: DataTableFilterOption[] }>,
  options: { operators?: boolean, control?: 'select' | 'multi' } = {}
): FilterPanelColumn[] {
  return columns.map((column) => {
    const next: FilterPanelColumn = {
      id: column.id,
      label: column.label,
      icon: column.icon,
      control: options.control ?? 'multi',
      options: column.options
    }
    if (options.operators) next.operators = true
    return next
  })
}

export function columnTypeOf(control: FilterPanelControl): DataTableColumnType {
  if (control === 'select') return 'option'
  if (control === 'multi') return 'multiOption'
  if (control === 'text') return 'text'
  if (control === 'date-range') return 'date'
  return 'number'
}

export function isDraftControl(control: FilterPanelControl) {
  return control === 'text' || control === 'date-range' || control === 'number-range'
}

export function defaultOperatorFor(column: FilterPanelColumn): DataTableFilterOperator {
  const type = columnTypeOf(column.control)
  const target = column.control === 'date-range' || column.control === 'number-range' ? 'multiple' : 'single'
  return defaultFilterOperator(type, target)
}

export function emptyDraft(column: FilterPanelColumn): FilterPanelDraft {
  return { text: '', from: '', to: '', operator: defaultOperatorFor(column) }
}

export function draftsFromModel(
  columns: FilterPanelColumn[],
  model: DataTableFilterModel[]
): Record<string, FilterPanelDraft> {
  const drafts: Record<string, FilterPanelDraft> = {}
  for (const column of columns) {
    const current = model.find(filter => filter.columnId === column.id)
    const draft = emptyDraft(column)
    if (!current) {
      drafts[column.id] = draft
      continue
    }
    draft.operator = current.operator
    draft.text = current.values[0] === undefined ? '' : String(current.values[0])
    draft.from = draft.text
    draft.to = current.values[1] === undefined ? '' : String(current.values[1])
    drafts[column.id] = draft
  }
  return drafts
}

export function activeCount(model: DataTableFilterModel[]) {
  return model.length
}

export function withoutColumn(model: DataTableFilterModel[], columnId: string) {
  return model.filter(filter => filter.columnId !== columnId)
}

function replace(model: DataTableFilterModel[], next: DataTableFilterModel) {
  return [...withoutColumn(model, next.columnId), next]
}

export function commitChoice(
  column: FilterPanelColumn,
  model: DataTableFilterModel[],
  values: string[],
  operator?: DataTableFilterOperator
): DataTableFilterModel[] {
  const chosen = values.filter(value => value !== '')
  if (!chosen.length) return withoutColumn(model, column.id)

  const current = model.find(filter => filter.columnId === column.id)
  const type = columnTypeOf(column.control)

  if (column.control === 'multi') {
    const nextOperator = column.operators
      ? determineNewOperator(type, current?.values ?? [], chosen, operator ?? current?.operator ?? defaultOperatorFor(column))
      : (chosen.length > 1 ? 'include any of' : 'include')
    return replace(model, { columnId: column.id, type, operator: nextOperator, values: chosen })
  }

  const nextOperator = column.operators
    ? (operator ?? current?.operator ?? defaultOperatorFor(column))
    : defaultOperatorFor(column)
  return replace(model, { columnId: column.id, type, operator: nextOperator, values: [chosen[0]!] })
}

function rangeTarget(column: FilterPanelColumn, draft: FilterPanelDraft) {
  const operator = column.operators ? draft.operator : defaultOperatorFor(column)
  return operatorDetail(columnTypeOf(column.control), operator).target
}

function blank(column: FilterPanelColumn, draft: FilterPanelDraft) {
  if (column.control === 'text') return draft.text.trim() === ''
  if (rangeTarget(column, draft) === 'single') return draft.from.trim() === ''
  return draft.from.trim() === '' && draft.to.trim() === ''
}

export function draftError(column: FilterPanelColumn, draft: FilterPanelDraft): string | null {
  if (!isDraftControl(column.control) || blank(column, draft)) return null

  if (column.control === 'date-range' || column.control === 'number-range') {
    const pair = rangeTarget(column, draft) === 'multiple'
    if (pair && (draft.from.trim() === '' || draft.to.trim() === '')) return 'Preencha os dois limites.'

    if (column.control === 'number-range') {
      const from = Number(draft.from)
      const to = Number(draft.to)
      if (!Number.isFinite(from) || (pair && !Number.isFinite(to))) return 'Informe um número.'
      if (pair && from > to) return 'O valor inicial é maior que o final.'
    }

    if (column.control === 'date-range' && pair && draft.from > draft.to) {
      return 'A data inicial é posterior à final.'
    }
  }

  return column.validate?.(draft) || null
}

function filterFromDraft(column: FilterPanelColumn, draft: FilterPanelDraft): DataTableFilterModel | null {
  if (blank(column, draft)) return null

  const type = columnTypeOf(column.control)
  const operator = column.operators ? draft.operator : defaultOperatorFor(column)

  if (column.control === 'text') {
    return { columnId: column.id, type, operator, values: [draft.text.trim()] }
  }

  if (rangeTarget(column, draft) === 'single') {
    const value = column.control === 'number-range' ? Number(draft.from) : draft.from
    return { columnId: column.id, type, operator, values: [value] }
  }

  const from = column.control === 'number-range' ? Number(draft.from) : draft.from
  const to = column.control === 'number-range' ? Number(draft.to) : draft.to
  return { columnId: column.id, type, operator, values: [from, to] }
}

export function applyDrafts(
  columns: FilterPanelColumn[],
  model: DataTableFilterModel[],
  drafts: Record<string, FilterPanelDraft>
): ApplyDraftsResult {
  const draftColumns = columns.filter(column => isDraftControl(column.control))
  const errors: Record<string, string> = {}

  for (const column of draftColumns) {
    const error = draftError(column, drafts[column.id] ?? emptyDraft(column))
    if (error) errors[column.id] = error
  }

  if (Object.keys(errors).length) return { ok: false, model, errors }

  const draftIds = new Set(draftColumns.map(column => column.id))
  const kept = model.filter(filter => !draftIds.has(filter.columnId))
  const applied: DataTableFilterModel[] = []

  for (const column of draftColumns) {
    const next = filterFromDraft(column, drafts[column.id] ?? emptyDraft(column))
    if (next) applied.push(next)
  }

  return { ok: true, model: [...kept, ...applied], errors }
}

function optionLabel(column: FilterPanelColumn, value: string | number) {
  return column.options?.find(option => option.value === String(value))?.label ?? String(value)
}

export function formatFilterValue(column: FilterPanelColumn, filter: DataTableFilterModel) {
  const labels = filter.values.map(value => optionLabel(column, value))
  const separator = column.control === 'date-range' || column.control === 'number-range' ? ' – ' : ', '
  return labels.join(separator)
}

export function chipOf(column: FilterPanelColumn, filter: DataTableFilterModel): FilterPanelChip {
  const readable = formatFilterValue(column, filter)
  const value = column.operators
    ? `${operatorLabel(columnTypeOf(column.control), filter.operator)} ${readable}`
    : readable

  return { columnId: column.id, label: column.label, value }
}

/**
 * Grava o rascunho de uma coluna. As outras ficam onde estão.
 * Vazio tira o filtro; rascunho inválido não muda o modelo.
 */
export function applyColumnDraft(
  column: FilterPanelColumn,
  model: DataTableFilterModel[],
  draft: FilterPanelDraft
): ApplyDraftsResult {
  const error = draftError(column, draft)
  if (error) return { ok: false, model, errors: { [column.id]: error } }

  const next = filterFromDraft(column, draft)
  if (!next) return { ok: true, model: withoutColumn(model, column.id), errors: {} }

  const index = model.findIndex(filter => filter.columnId === column.id)
  if (index === -1) return { ok: true, model: [...model, next], errors: {} }

  const copy = model.slice()
  copy[index] = next
  return { ok: true, model: copy, errors: {} }
}
