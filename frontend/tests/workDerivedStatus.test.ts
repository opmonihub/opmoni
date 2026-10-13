import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  derivedProcessStatus,
  derivedProcessStatusForGroup,
  derivedProcessStatusPresentation,
  isCascadeAdvanceLocked,
  isCascadeAdvanceLockedInProcess
} from '../app/utils/workDerivedStatus.ts'

describe('derivedProcessStatus', () => {
  it('returns empty when there are no tasks', () => {
    assert.equal(derivedProcessStatus([]), 'empty')
  })

  it('returns open when every task is todo', () => {
    assert.equal(derivedProcessStatus(['todo', 'todo', 'todo']), 'open')
  })

  it('returns in_progress when some tasks are done and others remain open', () => {
    assert.equal(derivedProcessStatus(['done', 'todo', 'todo']), 'in_progress')
  })

  it('returns in_progress when any task is doing', () => {
    assert.equal(derivedProcessStatus(['todo', 'doing', 'todo']), 'in_progress')
  })

  it('returns done when every task is done or dismissed', () => {
    assert.equal(derivedProcessStatus(['done', 'dismissed', 'done']), 'done')
  })

  it('returns done when every task is dismissed', () => {
    assert.equal(derivedProcessStatus(['dismissed', 'dismissed']), 'done')
  })

  it('ignores nullish statuses when deriving', () => {
    assert.equal(derivedProcessStatus(['todo', null, undefined, 'todo']), 'open')
  })
})

describe('derivedProcessStatusPresentation', () => {
  it('maps each derived status to label and color', () => {
    assert.deepEqual(derivedProcessStatusPresentation('empty'), {
      label: 'Sem tarefas',
      color: 'neutral'
    })
    assert.deepEqual(derivedProcessStatusPresentation('open'), {
      label: 'A fazer',
      color: 'info'
    })
    assert.deepEqual(derivedProcessStatusPresentation('in_progress'), {
      label: 'Em progresso',
      color: 'warning'
    })
    assert.deepEqual(derivedProcessStatusPresentation('done'), {
      label: 'Concluído',
      color: 'success'
    })
  })
})

describe('isCascadeAdvanceLocked', () => {
  it('is false when cascade is off', () => {
    assert.equal(
      isCascadeAdvanceLocked(false, 2, [{ order: 1, status: 'todo' }]),
      false
    )
  })

  it('is true when an earlier sibling is still open', () => {
    assert.equal(
      isCascadeAdvanceLocked(true, 2, [
        { order: 1, status: 'todo' },
        { order: 2, status: 'todo' }
      ]),
      true
    )
  })

  it('is false when earlier siblings are done or dismissed', () => {
    assert.equal(
      isCascadeAdvanceLocked(true, 3, [
        { order: 1, status: 'done' },
        { order: 2, status: 'dismissed' },
        { order: 3, status: 'todo' }
      ]),
      false
    )
  })
})

describe('group status and cascade from complete process data', () => {
  const tasks = [
    { processId: 1, clientId: 1, order: 1, status: 'todo' as const },
    { processId: 1, clientId: 1, order: 2, status: 'done' as const },
    { processId: 2, clientId: 2, order: 1, status: 'done' as const }
  ]

  it('keeps a group in progress when a status filter shows only its done task', () => {
    assert.equal(derivedProcessStatusForGroup(tasks, task => task.processId === 1), 'in_progress')
    assert.equal(derivedProcessStatusForGroup(tasks, task => task.processId === 2), 'done')
  })

  it('keeps the second task locked when the first is hidden by a filter', () => {
    assert.equal(isCascadeAdvanceLockedInProcess(true, 1, 2, tasks), true)
    assert.equal(isCascadeAdvanceLockedInProcess(true, 2, 2, tasks), false)
  })

  it('uses the server lock when earlier tasks fall outside the selected month', () => {
    const visible = [{ processId: 1, order: 2, status: 'todo' as const }]
    assert.equal(isCascadeAdvanceLockedInProcess(true, 1, 2, visible, true), true)
    assert.equal(isCascadeAdvanceLockedInProcess(true, 1, 2, visible, false), false)
  })
})
