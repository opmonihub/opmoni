import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  activeCount,
  applyColumnDraft,
  applyDrafts,
  chipOf,
  commitChoice,
  draftsFromModel,
  formatFilterValue,
  toPanelColumns,
  withoutColumn,
  type FilterPanelColumn
} from '../app/utils/filterPanel.ts'

const columns: FilterPanelColumn[] = [
  {
    id: 'model',
    label: 'Modelo',
    control: 'multi',
    options: [
      { label: 'NF-e', value: 'nfe' },
      { label: 'CT-e', value: 'cte' }
    ]
  },
  {
    id: 'kind',
    label: 'Tipo',
    control: 'select',
    options: [
      { label: 'Documento', value: 'document' },
      { label: 'Evento', value: 'event' }
    ]
  },
  {
    id: 'issuer',
    label: 'Emitente',
    control: 'text',
    validate: draft => /^\d*$/.test(draft.text.trim()) ? null : 'Só dígitos.'
  },
  {
    id: 'issued',
    label: 'Emissão',
    control: 'date-range'
  },
  {
    id: 'amount',
    label: 'Valor',
    control: 'number-range'
  },
  {
    id: 'status',
    label: 'Situação',
    control: 'select',
    operators: true,
    options: [
      { label: 'A fazer', value: 'todo' },
      { label: 'Concluída', value: 'done' }
    ]
  }
]

function drafts() {
  return draftsFromModel(columns, [])
}

describe('painel de filtros', () => {
  it('monta o rascunho a partir do modelo vigente', () => {
    const drafts = draftsFromModel(columns, [
      { columnId: 'issuer', type: 'text', operator: 'contains', values: ['123'] },
      { columnId: 'issued', type: 'date', operator: 'is between', values: ['2026-01-01', '2026-01-31'] },
      { columnId: 'amount', type: 'number', operator: 'is between', values: [10, 20] }
    ])

    assert.equal(drafts.issuer?.text, '123')
    assert.equal(drafts.issued?.from, '2026-01-01')
    assert.equal(drafts.issued?.to, '2026-01-31')
    assert.equal(drafts.amount?.from, '10')
    assert.equal(drafts.amount?.to, '20')
  })

  it('grava o select na hora e tira o campo quando a escolha esvazia', () => {
    const selected = commitChoice(columns[1]!, [], ['document'])

    assert.deepEqual(selected, [
      { columnId: 'kind', type: 'option', operator: 'is', values: ['document'] }
    ])
    assert.deepEqual(commitChoice(columns[1]!, selected, []), [])
  })

  it('grava vários valores de multi como inclusão', () => {
    const selected = commitChoice(columns[0]!, [], ['nfe', 'cte'])

    assert.deepEqual(selected, [
      { columnId: 'model', type: 'multiOption', operator: 'include any of', values: ['nfe', 'cte'] }
    ])
  })

  it('aplica texto e intervalo e preserva o select já gravado', () => {
    const next = drafts()
    next.issuer!.text = '123'
    next.issued!.from = '2026-01-01'
    next.issued!.to = '2026-01-31'
    next.amount!.from = '10'
    next.amount!.to = '20'

    const result = applyDrafts(columns, [
      { columnId: 'kind', type: 'option', operator: 'is', values: ['document'] }
    ], next)

    assert.equal(result.ok, true)
    if (!result.ok) return
    assert.deepEqual(result.model, [
      { columnId: 'kind', type: 'option', operator: 'is', values: ['document'] },
      { columnId: 'issuer', type: 'text', operator: 'contains', values: ['123'] },
      { columnId: 'issued', type: 'date', operator: 'is between', values: ['2026-01-01', '2026-01-31'] },
      { columnId: 'amount', type: 'number', operator: 'is between', values: [10, 20] }
    ])
  })

  it('bloqueia o Aplicar quando a validação da página recusa o rascunho', () => {
    const current = [
      { columnId: 'kind', type: 'option' as const, operator: 'is' as const, values: ['document'] }
    ]
    const next = drafts()
    next.issuer!.text = '12a'

    const result = applyDrafts(columns, current, next)

    assert.equal(result.ok, false)
    if (result.ok) return
    assert.equal(result.errors.issuer, 'Só dígitos.')
    assert.deepEqual(result.model, current)
  })

  it('bloqueia intervalo incompleto, número invertido e data invertida', () => {
    const partial = drafts()
    partial.issued!.from = '2026-01-01'
    const invertedDate = drafts()
    invertedDate.issued!.from = '2026-02-01'
    invertedDate.issued!.to = '2026-01-01'
    const invertedAmount = drafts()
    invertedAmount.amount!.from = '20'
    invertedAmount.amount!.to = '10'

    const missingEnd = applyDrafts(columns, [], partial)
    const badDate = applyDrafts(columns, [], invertedDate)
    const badAmount = applyDrafts(columns, [], invertedAmount)

    assert.equal(missingEnd.ok, false)
    assert.equal(badDate.ok, false)
    assert.equal(badAmount.ok, false)
    if (missingEnd.ok || badDate.ok || badAmount.ok) return
    assert.equal(missingEnd.errors.issued, 'Preencha os dois limites.')
    assert.equal(badDate.errors.issued, 'A data inicial é posterior à final.')
    assert.equal(badAmount.errors.amount, 'O valor inicial é maior que o final.')
  })

  it('limpa um texto em branco sem consultar a validação', () => {
    const rejecting: FilterPanelColumn = {
      id: 'issuer',
      label: 'Emitente',
      control: 'text',
      validate: () => 'recusa tudo'
    }
    const next = draftsFromModel([rejecting], [])
    const result = applyDrafts([rejecting], [
      { columnId: 'issuer', type: 'text', operator: 'contains', values: ['123'] }
    ], next)

    assert.equal(result.ok, true)
    if (!result.ok) return
    assert.deepEqual(result.model, [])
  })

  it('resume o chip com o rótulo da coluna e o valor legível', () => {
    const kind = chipOf(columns[1]!, {
      columnId: 'kind',
      type: 'option',
      operator: 'is',
      values: ['document']
    })
    const issued = chipOf(columns[3]!, {
      columnId: 'issued',
      type: 'date',
      operator: 'is between',
      values: ['2026-01-01', '2026-01-31']
    })

    assert.deepEqual(kind, { columnId: 'kind', label: 'Tipo', value: 'Documento' })
    assert.deepEqual(issued, { columnId: 'issued', label: 'Emissão', value: '2026-01-01 – 2026-01-31' })
  })

  it('inclui o operador no chip quando a coluna pede o seletor', () => {
    const chip = chipOf(columns[5]!, {
      columnId: 'status',
      type: 'option',
      operator: 'is not',
      values: ['todo']
    })

    assert.equal(chip.value, 'não é A fazer')
    assert.equal(formatFilterValue(columns[5]!, {
      columnId: 'status',
      type: 'option',
      operator: 'is not',
      values: ['todo']
    }), 'A fazer')
  })

  it('aplica o rascunho de uma coluna e deixa o resto no lugar', () => {
    const issuer = columns.find(column => column.id === 'issuer')!
    const model = [
      { columnId: 'kind', type: 'option' as const, operator: 'is' as const, values: ['document'] },
      { columnId: 'issued', type: 'date' as const, operator: 'is between' as const, values: ['2026-01-01', '2026-01-31'] }
    ]
    const draft = draftsFromModel(columns, []).issuer!
    draft.text = '123'

    const result = applyColumnDraft(issuer, model, draft)

    assert.equal(result.ok, true)
    if (!result.ok) return
    assert.deepEqual(result.model, [
      ...model,
      { columnId: 'issuer', type: 'text', operator: 'contains', values: ['123'] }
    ])
  })

  it('recusa o rascunho da coluna e texto vazio tira só ela', () => {
    const issuer = columns.find(column => column.id === 'issuer')!
    const model = [
      { columnId: 'kind', type: 'option' as const, operator: 'is' as const, values: ['document'] },
      { columnId: 'issuer', type: 'text' as const, operator: 'contains' as const, values: ['123'] }
    ]
    const invalid = draftsFromModel(columns, []).issuer!
    invalid.text = '12a'
    const refused = applyColumnDraft(issuer, model, invalid)

    assert.equal(refused.ok, false)
    if (refused.ok) return
    assert.equal(refused.errors.issuer, 'Só dígitos.')
    assert.deepEqual(refused.model, model)

    const cleared = applyColumnDraft(issuer, model, draftsFromModel(columns, []).issuer!)
    assert.equal(cleared.ok, true)
    if (!cleared.ok) return
    assert.deepEqual(cleared.model, [model[0]])
  })

  it('conta o que está ativo e remove um campo', () => {
    const model = [
      { columnId: 'kind', type: 'option' as const, operator: 'is' as const, values: ['document'] },
      { columnId: 'issuer', type: 'text' as const, operator: 'contains' as const, values: ['123'] }
    ]

    assert.equal(activeCount(model), 2)
    assert.deepEqual(withoutColumn(model, 'issuer'), [model[0]])
  })

  it('leva coluna de opção para o painel com operador quando a tela pede', () => {
    const columns = toPanelColumns([
      { id: 'status', label: 'Status', icon: 'i-lucide-circle-dot', options: [{ label: 'A fazer', value: 'todo' }] }
    ], { operators: true })

    assert.deepEqual(columns, [{
      id: 'status',
      label: 'Status',
      icon: 'i-lucide-circle-dot',
      control: 'multi',
      options: [{ label: 'A fazer', value: 'todo' }],
      operators: true
    }])
  })
})
