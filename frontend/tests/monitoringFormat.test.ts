// tests/monitoringFormat.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  formatMonitoringCount,
  formatMonitoringDate,
  formatMonitoringDueOn,
  monitoringDeadlinePassed,
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
