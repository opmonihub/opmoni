// tests/monitoringFormat.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  formatMonitoringCount,
  formatMonitoringDate,
  formatMonitoringDueOn,
  isMonitoringSlipColumn,
  latestSlipFor,
  monitoringDeadlinePassed,
  monitoringMissingValue,
  monitoringPaidPresentation,
  monitoringSlipColumnValue,
  monitoringSlipMissingPresentation,
  monitoringSlipStatusPresentation,
  slipStatusFor
} from '../app/utils/monitoringPresentation.ts'
import type { MonitoringAssessmentPeriod } from '../app/types/serpro.ts'

describe('formatMonitoringCount', () => {
  it('renders zero rather than hiding it', () => {
    assert.equal(formatMonitoringCount(0), '0')
  })

  it('groups thousands in pt-BR', () => {
    assert.equal(formatMonitoringCount(1234), '1.234')
  })
})

describe('formatMonitoringDate', () => {
  it('renders a date as dd/mm/yyyy', () => {
    assert.equal(formatMonitoringDate('2026-04-20T00:00:00Z'), '20/04/2026')
  })

  it('renders an absent value as an em dash', () => {
    assert.equal(formatMonitoringDate(null), '—')
    assert.equal(formatMonitoringDueOn(null), '—')
  })
})

/** Today in the runner's own timezone, so the assertions do not depend on it. */
function today() {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

describe('monitoringDeadlinePassed', () => {
  it('reports a deadline whose day is over', () => {
    assert.equal(monitoringDeadlinePassed('2020-04-20T00:00:00Z'), true)
  })

  it('does not report a deadline still ahead', () => {
    assert.equal(monitoringDeadlinePassed('2999-04-20T00:00:00Z'), false)
  })

  it('does not report today as passed, whatever hour the provider sent', () => {
    assert.equal(monitoringDeadlinePassed(today()), false)
    assert.equal(monitoringDeadlinePassed(`${today()}T23:59:59Z`), false)
  })

  it('reports no deadline as not passed', () => {
    assert.equal(monitoringDeadlinePassed(null), false)
    assert.equal(monitoringDeadlinePassed(undefined), false)
    assert.equal(monitoringDeadlinePassed(''), false)
  })

  it('reports a date it cannot read as not passed', () => {
    assert.equal(monitoringDeadlinePassed('quando o prazo vencer'), false)
  })
})

describe('the em dash is one value, not a literal per reader', () => {
  it('is what every absent reading is drawn as', () => {
    assert.equal(monitoringMissingValue, '—')
    assert.equal(formatMonitoringDate(null), monitoringMissingValue)
  })
})

function period(overrides: Partial<MonitoringAssessmentPeriod>): MonitoringAssessmentPeriod {
  return {
    period: '2026-03',
    declared_at: null,
    rectified: false,
    slip_number: null,
    slip_issued_at: null,
    due_on: '2026-04-20',
    slip_paid: null,
    ...overrides
  }
}

describe('slipStatusFor', () => {
  it('reads an issued and paid slip with both dates', () => {
    const result = slipStatusFor([
      period({ declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_issued_at: '2026-04-01T09:00:00Z', slip_paid: true })
    ], '2026-03')
    assert.equal(result.status, 'paid')
    assert.equal(result.slip_number, '0815')
    assert.equal(result.issued_on, '2026-04-01T09:00:00Z')
  })

  it('reads an issued and unpaid slip', () => {
    const result = slipStatusFor([
      period({ declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_paid: false })
    ], '2026-03')
    assert.equal(result.status, 'issued')
  })

  it('reads a declared period with no slip as owing', () => {
    const result = slipStatusFor([period({ declared_at: '2026-03-31T10:00:00Z' })], '2026-03')
    assert.equal(result.status, 'owed')
  })

  it('presents the rectified transmission, not the original', () => {
    const result = slipStatusFor([
      period({ declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_paid: true }),
      period({ declared_at: '2026-04-10T10:00:00Z', rectified: true, slip_number: '0821', slip_paid: false })
    ], '2026-03')
    assert.equal(result.status, 'issued')
    assert.equal(result.slip_number, '0821')
  })

  it('reports no slip for a period the data does not mention', () => {
    assert.equal(slipStatusFor([period({ period: '2026-02' })], '2026-03').status, 'none')
  })
})

describe('latestSlipFor', () => {
  it('reads the most recent period, not the first the array lists', () => {
    const result = latestSlipFor([
      period({ period: '2026-03', declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_paid: true }),
      period({ period: '2026-02', declared_at: '2026-02-28T10:00:00Z', slip_number: '0801', slip_paid: true })
    ])
    assert.equal(result?.slip_number, '0815')
    assert.equal(result?.status, 'paid')
  })

  it('reads the most recent transmission inside that period', () => {
    const result = latestSlipFor([
      period({ period: '2026-03', declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_paid: false }),
      period({ period: '2026-03', declared_at: '2026-04-10T10:00:00Z', slip_number: '0821', slip_paid: false })
    ])
    assert.equal(result?.slip_number, '0821')
  })

  it('is null — not "Sem guia" — when the data mentions no period', () => {
    assert.equal(latestSlipFor([]), null)
    assert.equal(latestSlipFor(null), null)
    assert.equal(latestSlipFor(undefined), null)
  })

  it('distinguishes a period that owes a guide from no period at all', () => {
    assert.equal(latestSlipFor([period({ declared_at: '2026-03-31T10:00:00Z' })])?.status, 'owed')
    assert.equal(latestSlipFor([])?.status, undefined)
  })
})

describe('monitoringSlipColumnValue', () => {
  const paid = period({
    period: '2026-03',
    declared_at: '2026-03-31T10:00:00Z',
    slip_number: '0815',
    slip_issued_at: '2026-04-01T09:00:00Z',
    due_on: '2026-04-20',
    slip_paid: true
  })

  it('presents the status, the number, both dates and the paid flag', () => {
    assert.equal(monitoringSlipColumnValue('guia', [paid]), monitoringSlipStatusPresentation.paid.label)
    assert.equal(monitoringSlipColumnValue('guia_numero', [paid]), '0815')
    assert.equal(monitoringSlipColumnValue('guia_emitida_em', [paid]), '01/04/2026')
    assert.equal(monitoringSlipColumnValue('guia_vencimento', [paid]), '20/04/2026')
    assert.equal(monitoringSlipColumnValue('guia_paga', [paid]), monitoringPaidPresentation.paid.label)
  })

  it('says a slip issued and unpaid is unpaid', () => {
    const unpaid = period({ ...paid, slip_paid: false })
    assert.equal(monitoringSlipColumnValue('guia', [unpaid]), monitoringSlipStatusPresentation.issued.label)
    assert.equal(monitoringSlipColumnValue('guia_paga', [unpaid]), monitoringPaidPresentation.unpaid.label)
  })

  it('gives a period with no slip no paid verdict', () => {
    const owed = period({ declared_at: '2026-03-31T10:00:00Z' })
    assert.equal(monitoringSlipColumnValue('guia', [owed]), monitoringSlipStatusPresentation.owed.label)
    assert.equal(monitoringSlipColumnValue('guia_paga', [owed]), monitoringMissingValue)
    assert.equal(monitoringSlipColumnValue('guia_numero', [owed]), monitoringMissingValue)
  })

  it('degrades to the em dash for a row with no periods, without throwing', () => {
    for (const id of ['guia', 'guia_numero', 'guia_emitida_em', 'guia_vencimento', 'guia_paga']) {
      assert.equal(monitoringSlipColumnValue(id, []), monitoringMissingValue, `${id} must not claim a guide`)
      assert.equal(monitoringSlipColumnValue(id, null), monitoringMissingValue, `${id} must not claim a guide`)
    }
  })

  it('never claims "Sem guia" for a row the source said nothing about', () => {
    assert.notEqual(monitoringSlipColumnValue('guia', []), monitoringSlipStatusPresentation.none.label)
    assert.equal(monitoringSlipMissingPresentation.label, monitoringMissingValue)
  })

  it('falls back to the em dash for a column nothing can read', () => {
    assert.equal(monitoringSlipColumnValue('guia_inexistente', [paid]), monitoringMissingValue)
  })

  it('does not claim a guide column for an id inherited from the prototype', () => {
    for (const id of ['toString', 'constructor', 'hasOwnProperty']) {
      assert.equal(isMonitoringSlipColumn(id), false, `${id} is not a guide column`)
      assert.equal(monitoringSlipColumnValue(id, [paid]), monitoringMissingValue, `${id} must not render a value`)
    }
    for (const id of ['guia', 'guia_numero', 'guia_emitida_em', 'guia_vencimento', 'guia_paga']) {
      assert.equal(isMonitoringSlipColumn(id), true, `${id} is a declared guide column`)
    }
  })
})
