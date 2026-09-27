import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  APP_CALENDAR_TIMEZONE,
  isCalendarToday,
  todayKey
} from '../app/utils/workCalendar.ts'

describe('work calendar date keys', () => {
  it('pins the product calendar timezone to America/Sao_Paulo', () => {
    assert.equal(APP_CALENDAR_TIMEZONE, 'America/Sao_Paulo')
  })

  it('resolves today via an explicit timezone, not process local TZ', () => {
    const utcDay = todayKey('UTC')
    const saoPauloDay = todayKey('America/Sao_Paulo')
    assert.match(utcDay, /^\d{4}-\d{2}-\d{2}$/)
    assert.match(saoPauloDay, /^\d{4}-\d{2}-\d{2}$/)
    assert.equal(todayKey(), saoPauloDay)
  })

  it('compares calendar-today against the pinned today key', () => {
    const today = todayKey()
    assert.equal(isCalendarToday(today), true)
    assert.equal(isCalendarToday('1999-01-01'), false)
  })
})
