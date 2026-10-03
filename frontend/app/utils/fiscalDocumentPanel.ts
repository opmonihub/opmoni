import type { DataTableFilterModel, DataTableFilterOption } from '../components/data-table/filter-model.ts'
import type { FiscalListFilters } from '../types/fiscal.ts'
import type { FilterPanelColumn, FilterPanelDraft } from './filterPanel.ts'
import { appliedFiscalFilters, isFiscalModel } from './fiscalFilters.ts'

/**
 * A barra de documentos e a URL, nos dois sentidos.
 *
 * O painel fala `DataTableFilterModel`. A URL continua com as mesmas chaves
 * (`model`, `kind`, `client_id`, prefixo de CNPJ, emissão e valor). A busca
 * `q` não entra no modelo: ela mora no campo da página, e Limpar não mexe nela.
 */

export function fiscalPrefixError(draft: FilterPanelDraft): string | null {
  const text = draft.text.trim()
  if (!text) return null
  return /^\d{1,14}$/.test(text) ? null : 'Só dígitos, no máximo 14.'
}

export function fiscalAmountError(draft: FilterPanelDraft): string | null {
  for (const value of [draft.from, draft.to]) {
    if (!value.trim()) continue
    const parsed = Number(value)
    if (Number.isFinite(parsed) && parsed < 0) return 'O valor precisa ser maior ou igual a zero.'
  }
  return null
}

export function fiscalDocumentPanelColumns(
  modelOptions: DataTableFilterOption[],
  clientOptions: DataTableFilterOption[]
): FilterPanelColumn[] {
  return [
    { id: 'model', label: 'Modelo', icon: 'i-lucide-file-text', control: 'multi', options: modelOptions },
    {
      id: 'kind',
      label: 'Tipo',
      icon: 'i-lucide-tags',
      control: 'select',
      options: [
        { label: 'Documento', value: 'document' },
        { label: 'Evento', value: 'event' }
      ]
    },
    { id: 'client_id', label: 'Cliente', icon: 'i-lucide-building-2', control: 'select', options: clientOptions },
    {
      id: 'issuer',
      label: 'Emitente',
      icon: 'i-lucide-building-2',
      control: 'text',
      validate: fiscalPrefixError
    },
    {
      id: 'recipient',
      label: 'Destinatário',
      icon: 'i-lucide-user-round',
      control: 'text',
      validate: fiscalPrefixError
    },
    { id: 'issued', label: 'Emissão', icon: 'i-lucide-calendar-range', control: 'date-range' },
    {
      id: 'amount',
      label: 'Valor',
      icon: 'i-lucide-banknote',
      control: 'number-range',
      validate: fiscalAmountError
    }
  ]
}

function textOf(models: readonly DataTableFilterModel[], id: string) {
  const value = models.find(model => model.columnId === id)?.values[0]
  return value === undefined ? '' : String(value)
}

function rangeOf(model: DataTableFilterModel | undefined) {
  if (!model) return { from: '', to: '' }
  const first = model.values[0] === undefined ? '' : String(model.values[0])
  const second = model.values[1] === undefined ? '' : String(model.values[1])
  const endOnly = model.operator === 'is on or before'
    || model.operator === 'is before'
    || model.operator === 'is less than or equal to'
    || model.operator === 'is less than'
  if (endOnly) return { from: '', to: first }
  if (model.operator === 'is between' || model.operator === 'is not between') {
    return { from: first, to: second }
  }
  if (model.operator === 'is') return { from: first, to: first }
  return { from: first, to: '' }
}

function dateEntry(from?: string | null, to?: string | null): DataTableFilterModel | null {
  const start = from || ''
  const end = to || ''
  if (start && end) return { columnId: 'issued', type: 'date', operator: 'is between', values: [start, end] }
  if (start) return { columnId: 'issued', type: 'date', operator: 'is on or after', values: [start] }
  if (end) return { columnId: 'issued', type: 'date', operator: 'is on or before', values: [end] }
  return null
}

function amountEntry(min?: number | null, max?: number | null): DataTableFilterModel | null {
  const hasMin = min !== undefined && min !== null
  const hasMax = max !== undefined && max !== null
  if (hasMin && hasMax) return { columnId: 'amount', type: 'number', operator: 'is between', values: [min, max] }
  if (hasMin) return { columnId: 'amount', type: 'number', operator: 'is greater than or equal to', values: [min] }
  if (hasMax) return { columnId: 'amount', type: 'number', operator: 'is less than or equal to', values: [max] }
  return null
}

export function fiscalDocumentPanelModel(filters: FiscalListFilters): DataTableFilterModel[] {
  const applied: DataTableFilterModel[] = []

  if (filters.model?.length) {
    applied.push({
      columnId: 'model',
      type: 'multiOption',
      operator: filters.model.length > 1 ? 'include any of' : 'include',
      values: [...filters.model]
    })
  }
  if (filters.kind) applied.push({ columnId: 'kind', type: 'option', operator: 'is', values: [filters.kind] })
  if (filters.client_id) {
    applied.push({ columnId: 'client_id', type: 'option', operator: 'is', values: [String(filters.client_id)] })
  }
  if (filters.issuer) applied.push({ columnId: 'issuer', type: 'text', operator: 'contains', values: [filters.issuer] })
  if (filters.recipient) applied.push({ columnId: 'recipient', type: 'text', operator: 'contains', values: [filters.recipient] })

  const issued = dateEntry(filters.issued_from, filters.issued_to)
  if (issued) applied.push(issued)
  const amount = amountEntry(filters.amount_min, filters.amount_max)
  if (amount) applied.push(amount)

  return applied
}

/**
 * O que o painel devolve, lido de novo como filtro da URL.
 *
 * Passa pelo mesmo `appliedFiscalFilters` dos campos livres: CNPJ vira prefixo
 * de dígitos e valor negativo não entra na query. Modelo, tipo e cliente
 * substituem o que já estava — a API só sabe inclusão, então o operador do
 * painel não vai para a URL.
 */
export function fiscalFiltersFromPanel(
  current: FiscalListFilters,
  models: readonly DataTableFilterModel[]
): FiscalListFilters {
  const issued = rangeOf(models.find(model => model.columnId === 'issued'))
  const amount = rangeOf(models.find(model => model.columnId === 'amount'))
  const applied = appliedFiscalFilters(current, {
    issuer: textOf(models, 'issuer'),
    recipient: textOf(models, 'recipient'),
    issued_from: issued.from,
    issued_to: issued.to,
    amount_min: amount.from,
    amount_max: amount.to
  })

  const model = (models.find(entry => entry.columnId === 'model')?.values.map(String) ?? []).filter(isFiscalModel)
  const kind = models.find(entry => entry.columnId === 'kind')?.values[0]
  const client = models.find(entry => entry.columnId === 'client_id')?.values[0]
  const clientId = client === undefined || client === '' ? Number.NaN : Number(client)

  const next: FiscalListFilters = {
    ...applied,
    kind: kind === 'document' || kind === 'event' ? kind : null,
    client_id: Number.isInteger(clientId) && clientId > 0 ? clientId : null
  }
  if (model.length) next.model = model
  else delete next.model

  return next
}
