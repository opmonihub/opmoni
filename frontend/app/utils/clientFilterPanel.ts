import type { DataTableFilterModel, DataTableFilterOperator } from '../components/data-table/filter-model.ts'

/**
 * A carteira grava operadores de opção (`é`, `não é`, `é qualquer um de`,
 * `não é nenhum de`). O painel, em coluna `multi`, fala inclusão e exclusão.
 * A tradução fica na fronteira para o filtro salvo continuar válido.
 */

const savedOperators = new Set<DataTableFilterOperator>(['is', 'is not', 'is any of', 'is none of'])

function panelOperator(operator: DataTableFilterOperator, count: number): DataTableFilterOperator {
  const many = count > 1
  if (operator === 'is not' || operator === 'exclude') return many ? 'exclude if any of' : 'exclude'
  if (operator === 'is none of' || operator === 'exclude if any of' || operator === 'exclude if all') {
    return many ? 'exclude if any of' : 'exclude'
  }
  if (operator === 'is any of' || operator === 'include any of' || operator === 'include all of') {
    return many ? 'include any of' : 'include'
  }
  return many ? 'include any of' : 'include'
}

function storedOperator(operator: DataTableFilterOperator, count: number): DataTableFilterOperator {
  const many = count > 1
  if (operator === 'is not' || operator === 'exclude') return many ? 'is none of' : 'is not'
  if (operator === 'is none of' || operator === 'exclude if any of' || operator === 'exclude if all') {
    return many ? 'is none of' : 'is not'
  }
  if (operator === 'is' || operator === 'include') return many ? 'is any of' : 'is'
  return many ? 'is any of' : 'is'
}

export function toPanelClientFilters(filters: readonly DataTableFilterModel[]): DataTableFilterModel[] {
  return filters.map(filter => ({
    columnId: filter.columnId,
    type: 'multiOption',
    operator: panelOperator(filter.operator, filter.values.length),
    values: [...filter.values]
  }))
}

export function toStoredClientFilters(filters: readonly DataTableFilterModel[]): DataTableFilterModel[] {
  return filters.map(filter => ({
    columnId: filter.columnId,
    operator: storedOperator(filter.operator, filter.values.length),
    values: [...filter.values]
  }))
}

export function isSavedClientOperator(operator: string): operator is DataTableFilterOperator {
  return savedOperators.has(operator as DataTableFilterOperator)
}
