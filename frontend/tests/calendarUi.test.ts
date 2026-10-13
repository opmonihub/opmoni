import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { accessibleDateLabel, calendarPriorityOptions, countActiveCalendarFilters, monthTitleParts } from '../app/utils/calendarUi.ts'

describe('calendar UI helpers', () => {
  it('counts only selected operational filters', () => {
    assert.equal(countActiveCalendarFilters({
      processId: null,
      clientId: null,
      assigneeId: null,
      departmentId: null,
      priority: ''
    }), 0)

    assert.equal(countActiveCalendarFilters({
      processId: 42,
      clientId: 7,
      assigneeId: null,
      departmentId: 3,
      priority: 'urgent'
    }), 4)
  })

  it('formats a calendar date as an accessible Portuguese label', () => {
    assert.equal(
      accessibleDateLabel('2026-11-02'),
      'segunda-feira, 2 de novembro de 2026'
    )
  })

  it('splits month title like the Nuxt calendar template', () => {
    assert.deepEqual(monthTitleParts('2026-09-25'), {
      months: 'setembro',
      year: '2026'
    })
  })

  it('keeps select options compatible with Reka UI', () => {
    assert.equal(calendarPriorityOptions.some(option => option.value === ''), false)
    assert.deepEqual(calendarPriorityOptions.map(option => option.value), [
      'low',
      'medium',
      'high',
      'urgent'
    ])
  })
})
