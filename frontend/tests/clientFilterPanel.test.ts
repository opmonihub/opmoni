import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { DataTableFilterModel } from '../app/components/data-table/filter-model.ts'
import {
  isSavedClientOperator,
  toPanelClientFilters,
  toStoredClientFilters
} from '../app/utils/clientFilterPanel.ts'

describe('filtro salvo da carteira no painel', () => {
  it('mostra no painel o que foi gravado e grava de volta o operador que a API aceita', () => {
    const saved: DataTableFilterModel[] = [
      { columnId: 'regime', operator: 'is', values: ['mei'] },
      { columnId: 'status', operator: 'is not', values: ['inactive'] },
      { columnId: 'tag', operator: 'is any of', values: ['1', '2'] },
      { columnId: 'certificate', operator: 'is none of', values: ['expired', 'missing'] }
    ]

    const panel = toPanelClientFilters(saved)
    assert.deepEqual(panel.map(filter => filter.operator), [
      'include',
      'exclude',
      'include any of',
      'exclude if any of'
    ])

    const stored = toStoredClientFilters(panel)
    assert.deepEqual(stored, saved)
    assert.ok(stored.every(filter => isSavedClientOperator(filter.operator)))
  })

  it('guarda inclui todos como é qualquer um de, que é o operador que o salvamento aceita', () => {
    const stored = toStoredClientFilters([{
      columnId: 'tag',
      type: 'multiOption',
      operator: 'include all of',
      values: ['1', '2']
    }])

    assert.deepEqual(stored, [{ columnId: 'tag', operator: 'is any of', values: ['1', '2'] }])
    assert.equal(isSavedClientOperator('include all of'), false)
  })
})
