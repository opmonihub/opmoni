import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { workSidebarChildren } from '../app/utils/workNav.ts'

describe('Work navigation', () => {
  it('marks the active Work child in sidebar children', () => {
    const items = workSidebarChildren('/work/calendario')
    const calendar = items.find(item => item.to === '/work/calendario')
    const tarefas = items.find(item => item.to === '/work/tarefas')

    assert.equal(calendar?.active, true)
    assert.equal(tarefas?.active, false)
  })
})
