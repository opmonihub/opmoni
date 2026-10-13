import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  clientSelectionCount,
  emptyClientSelection,
  headerCheckboxState,
  isClientInSelection,
  withClientSelected,
  withVisibleClientsSelected,
  type MatchingClientSelection
} from '../app/utils/clientSelection.ts'

describe('client selection', () => {
  it('keeps explicit ids independent of the row index', () => {
    const first = withClientSelected(emptyClientSelection(), 40, true, false)
    const both = withClientSelected(first, 12, true, false)
    const one = withClientSelected(both, 40, false, false)

    assert.deepEqual(one, { mode: 'explicit', ids: [12] })
    assert.equal(clientSelectionCount(one), 1)
    assert.equal(isClientInSelection(one, 12, false), true)
    assert.equal(isClientInSelection(one, 40, false), false)
  })

  it('selects and clears only the visible page', () => {
    const page = [4, 5, 6]
    const selected = withVisibleClientsSelected(emptyClientSelection(), page, true, () => false)
    const kept = withClientSelected(selected, 9, true, false)
    const cleared = withVisibleClientsSelected(kept, page, false, () => false)

    assert.deepEqual(selected, { mode: 'explicit', ids: page })
    assert.deepEqual(cleared, { mode: 'explicit', ids: [9] })
  })

  it('checks the header only when every filtered client is selected', () => {
    const matching: MatchingClientSelection = {
      mode: 'all_matching',
      id: 'snapshot',
      total: 2000,
      excluded: [],
      included: []
    }

    assert.equal(headerCheckboxState(emptyClientSelection(), 2000), false)
    assert.equal(headerCheckboxState(withClientSelected(emptyClientSelection(), 4, true, false), 2000), 'indeterminate')
    assert.equal(headerCheckboxState(matching, 2000), true)
    assert.equal(headerCheckboxState(withClientSelected(matching, 8, false, true), 2000), 'indeterminate')
    assert.equal(clientSelectionCount(withClientSelected(matching, 8, false, true)), 1999)
  })

  it('tracks exclusions without dropping the filtered snapshot', () => {
    const matching: MatchingClientSelection = {
      mode: 'all_matching',
      id: 'snapshot',
      total: 2000,
      excluded: [],
      included: []
    }
    const withoutOne = withClientSelected(matching, 8, false, true)
    const withExtra = withClientSelected(withoutOne, 9, true, false)

    assert.equal(clientSelectionCount(withExtra), 2000)
    assert.equal(isClientInSelection(withExtra, 8, true), false)
    assert.equal(isClientInSelection(withExtra, 9, false), true)
    assert.equal(isClientInSelection(withExtra, 10, true), true)
    assert.equal(withExtra.mode, 'all_matching')
  })

  it('returns to an empty explicit selection when every match is excluded', () => {
    const matching: MatchingClientSelection = {
      mode: 'all_matching',
      id: 'snapshot',
      total: 1,
      excluded: [],
      included: []
    }

    assert.deepEqual(withClientSelected(matching, 3, false, true), emptyClientSelection())
  })
})
