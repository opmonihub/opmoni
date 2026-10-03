import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { FiscalListFilters } from '../app/types/fiscal.ts'
import {
  fiscalAmountError,
  fiscalDocumentPanelModel,
  fiscalFiltersFromPanel,
  fiscalPrefixError
} from '../app/utils/fiscalDocumentPanel.ts'
import { fiscalQuery } from '../app/utils/fiscalFilters.ts'
import { emptyDraft } from '../app/utils/filterPanel.ts'

const issuer = { id: 'issuer', label: 'Emitente', control: 'text' as const }

describe('painel de documentos e a URL', () => {
  it('monta o modelo com as mesmas chaves que a URL já tinha', () => {
    const filters: FiscalListFilters = {
      model: ['nfe', 'cte'],
      kind: 'event',
      client_id: 2,
      issuer: '123',
      recipient: '456',
      issued_from: '2026-09-01',
      amount_min: 10,
      q: 'nota'
    }

    assert.deepEqual(fiscalDocumentPanelModel(filters), [
      { columnId: 'model', type: 'multiOption', operator: 'include any of', values: ['nfe', 'cte'] },
      { columnId: 'kind', type: 'option', operator: 'is', values: ['event'] },
      { columnId: 'client_id', type: 'option', operator: 'is', values: ['2'] },
      { columnId: 'issuer', type: 'text', operator: 'contains', values: ['123'] },
      { columnId: 'recipient', type: 'text', operator: 'contains', values: ['456'] },
      { columnId: 'issued', type: 'date', operator: 'is on or after', values: ['2026-09-01'] },
      { columnId: 'amount', type: 'number', operator: 'is greater than or equal to', values: [10] }
    ])
  })

  it('devolve a URL sem levar a busca embora quando o painel limpa', () => {
    const current: FiscalListFilters = {
      model: ['nfe'],
      issuer: '123',
      issued_from: '2026-09-01',
      issued_to: '2026-09-30',
      amount_min: 10,
      amount_max: 20,
      q: 'nota',
      page: 4
    }

    const next = fiscalFiltersFromPanel(current, [])

    assert.equal(next.q, 'nota')
    assert.equal(next.page, 4)
    assert.equal(next.model, undefined)
    assert.equal(next.issuer, undefined)
    assert.equal(next.issued_from, undefined)
    assert.equal(next.amount_min, undefined)
    assert.deepEqual(fiscalQuery(next), { q: 'nota', page: '4' })
  })

  it('escreve intervalo e prefixo pelo mesmo leitor da URL', () => {
    const next = fiscalFiltersFromPanel({ q: 'nota' }, [
      { columnId: 'model', type: 'multiOption', operator: 'include', values: ['nfe'] },
      { columnId: 'kind', type: 'option', operator: 'is not', values: ['event'] },
      { columnId: 'issuer', type: 'text', operator: 'contains', values: ['12.345'] },
      { columnId: 'issued', type: 'date', operator: 'is between', values: ['2026-09-01', '2026-09-30'] },
      { columnId: 'amount', type: 'number', operator: 'is between', values: [-5, 10] }
    ])

    assert.deepEqual(next.model, ['nfe'])
    assert.equal(next.kind, 'event')
    assert.equal(next.issuer, '12345')
    assert.equal(next.issued_from, '2026-09-01')
    assert.equal(next.issued_to, '2026-09-30')
    assert.equal(next.amount_min, undefined)
    assert.equal(next.amount_max, 10)
    assert.equal(next.q, 'nota')
  })

  it('recusa prefixo com letra e valor negativo no rascunho', () => {
    const draft = emptyDraft(issuer)
    draft.text = '12a'
    assert.equal(fiscalPrefixError(draft), 'Só dígitos, no máximo 14.')
    draft.text = '123'
    assert.equal(fiscalPrefixError(draft), null)

    const amount = { ...emptyDraft({ id: 'amount', label: 'Valor', control: 'number-range' }), from: '-1', to: '10' }
    assert.equal(fiscalAmountError(amount), 'O valor precisa ser maior ou igual a zero.')
  })
})
