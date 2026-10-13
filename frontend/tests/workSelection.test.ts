import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  isWorkSelectableLeaf,
  workLeafSelectionState,
  workTaskIdsFromSelection,
  withWorkLeavesSelected
} from '../app/utils/workSelection.ts'

describe('selection shared by client and process views', () => {
  const leaves = [
    { id: '11', taskId: 11, empty: false },
    { id: '12', taskId: 12, empty: false },
    { id: 'process-3-empty', taskId: null, empty: true }
  ]

  it('selects visible task leaves without selecting placeholders', () => {
    const selection = withWorkLeavesSelected({}, ['11', '12'], true)
    assert.equal(workLeafSelectionState(selection, ['11', '12']), true)
    assert.deepEqual(workTaskIdsFromSelection(selection, leaves), [11, 12])
    assert.equal(isWorkSelectableLeaf(leaves[2]!), false)
  })

  it('clears one group while preserving selection outside it', () => {
    const selection = withWorkLeavesSelected({ 11: true, 12: true, 30: true }, ['11'], false)
    assert.deepEqual(selection, { 12: true, 30: true })
    assert.equal(workLeafSelectionState(selection, ['11', '12']), 'indeterminate')
  })
})
