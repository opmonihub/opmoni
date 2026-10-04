// tests/serproSchedules.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { parseScheduleDay, scheduleDayMax, scheduleDayMin, schedulePayloadFromInputs } from '../app/utils/serproSchedules.ts'

describe('the scheduled-search day', () => {
  it('bounds the day to 1–28, the range every month has', () => {
    assert.equal(scheduleDayMin, 1)
    assert.equal(scheduleDayMax, 28)
  })

  it('reads empty as "sem agendamento" (null), not as a day', () => {
    assert.deepEqual(parseScheduleDay(''), { ok: true, day: null })
    assert.deepEqual(parseScheduleDay(null), { ok: true, day: null })
    assert.deepEqual(parseScheduleDay(undefined), { ok: true, day: null })
  })

  it('reads a whole day inside the range', () => {
    assert.deepEqual(parseScheduleDay('1'), { ok: true, day: 1 })
    assert.deepEqual(parseScheduleDay('28'), { ok: true, day: 28 })
    assert.deepEqual(parseScheduleDay(15), { ok: true, day: 15 })
  })

  it('refuses what no month could run, without clamping', () => {
    assert.deepEqual(parseScheduleDay('0'), { ok: false })
    assert.deepEqual(parseScheduleDay('29'), { ok: false })
    assert.deepEqual(parseScheduleDay('32'), { ok: false })
    assert.deepEqual(parseScheduleDay('-5'), { ok: false })
    assert.deepEqual(parseScheduleDay('2.5'), { ok: false })
    assert.deepEqual(parseScheduleDay('dia 5'), { ok: false })
  })
})

describe('the schedules form payload', () => {
  it('carries only the days the office typed, keyed by the obligation slug', () => {
    const { schedules, invalid } = schedulePayloadFromInputs({
      'simples-nacional': '5',
      'declaracoes/pgdas': '',
      'mei': null
    })
    assert.deepEqual(schedules, { 'simples-nacional': 5 })
    assert.deepEqual(invalid, [])
  })

  it('names the lines that would not convert instead of saving a day nobody chose', () => {
    const { schedules, invalid } = schedulePayloadFromInputs({
      'simples-nacional': '32',
      'mei': 'abc',
      'declaracoes/pgdas': '10'
    })
    assert.deepEqual(schedules, { 'declaracoes/pgdas': 10 })
    assert.deepEqual(invalid, ['simples-nacional', 'mei'])
  })

  it('answers an empty payload for an empty form', () => {
    const { schedules, invalid } = schedulePayloadFromInputs({})
    assert.deepEqual(schedules, {})
    assert.deepEqual(invalid, [])
  })
})
