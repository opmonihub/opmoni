import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { priorityPresentation, statusPresentation } from '../app/composables/useWorkPresentation.ts'
import { calendarStatusPresentation } from '../app/utils/workCalendar.ts'

describe('statusPresentation', () => {
  it('labels not-started tasks as A fazer', () => {
    assert.deepEqual(statusPresentation('todo'), { label: 'A fazer', color: 'info' })
  })

  it('keeps the remaining task status labels', () => {
    assert.deepEqual(statusPresentation('doing'), { label: 'Em progresso', color: 'warning' })
    assert.deepEqual(statusPresentation('done'), { label: 'Concluída', color: 'success' })
    assert.deepEqual(statusPresentation('dismissed'), { label: 'Dispensada', color: 'neutral' })
  })
})

describe('calendarStatusPresentation', () => {
  it('reuses the shared A fazer label for todo chips', () => {
    assert.equal(calendarStatusPresentation('todo').label, 'A fazer')
    assert.equal(calendarStatusPresentation('todo').color, 'info')
    assert.equal(calendarStatusPresentation('todo').dotClass, 'bg-info')
  })
})

describe('priorityPresentation', () => {
  it('maps each priority to label and color', () => {
    assert.deepEqual(priorityPresentation('low'), { label: 'Baixa', color: 'neutral' })
    assert.deepEqual(priorityPresentation('urgent'), { label: 'Urgente', color: 'error' })
  })
})
