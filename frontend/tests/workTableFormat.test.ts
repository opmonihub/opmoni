import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  countWorkLeafTasks,
  formatWorkDueOn,
  workAssignMemberItems,
  workLeafCountLabel,
  workRatioLabel
} from '../app/utils/workTableFormat.ts'

describe('workTableFormat', () => {
  it('formats due dates in pt-BR and empty as em dash', () => {
    assert.equal(formatWorkDueOn(null), '—')
    assert.equal(formatWorkDueOn(undefined), '—')
    assert.equal(formatWorkDueOn('2026-01-15'), '15/01/2026')
  })

  it('formats ratios as rounded percentages', () => {
    assert.equal(workRatioLabel(0), '0%')
    assert.equal(workRatioLabel(0.5), '50%')
    assert.equal(workRatioLabel(0.996), '100%')
  })

  it('counts non-empty leaf tasks under a grouped row', () => {
    const row = {
      getLeafRows: () => [
        { original: { empty: false } },
        { original: { empty: true } },
        { original: { empty: false } }
      ]
    }
    assert.equal(countWorkLeafTasks(row), 2)
  })

  it('labels a grouped row leaf count with the pre-refactor wording', () => {
    const row = {
      getLeafRows: () => [
        { original: { empty: false } },
        { original: { empty: true } },
        { original: { empty: false } }
      ]
    }
    assert.equal(workLeafCountLabel(row), '2 tarefa(s)')
    assert.equal(workLeafCountLabel({ getLeafRows: () => [] }), '0 tarefa(s)')
  })

  it('prepends Sem responsável to assign items', () => {
    assert.deepEqual(
      workAssignMemberItems([{ label: 'Ana', value: 7 }]),
      [
        { label: 'Sem responsável', value: null },
        { label: 'Ana', value: 7 }
      ]
    )
  })
})
