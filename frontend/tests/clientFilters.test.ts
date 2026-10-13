import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  activeFilterChips,
  applyClientFilters,
  countActiveFilters,
  defaultClientFilters,
  removeClientFilter,
  resetClientFilters,
  type ClientFilterState
} from '../app/utils/clientFilters.ts'

const labels = {
  status: { active: 'Ativo', inactive: 'Inativo' },
  regime: { simple_national: 'Simples Nacional', presumed_profit: 'Lucro presumido' },
  deadline: { missing: 'Sem cadastro', expired: 'Vencido' }
}

describe('client filters', () => {
  it('counts only categories that differ from the default', () => {
    assert.equal(countActiveFilters(defaultClientFilters), 0)
    assert.equal(countActiveFilters({ status: 'active', regime: 'all', deadline: 'expired' }), 2)
  })

  it('applies the draft without changing the previous selection until confirmed', () => {
    const applied: ClientFilterState = { ...defaultClientFilters }
    const draft: ClientFilterState = { status: 'inactive', regime: 'mei', deadline: 'all' }
    const next = applyClientFilters(draft)

    assert.deepEqual(applied, defaultClientFilters)
    assert.deepEqual(next, draft)
    assert.notEqual(next, draft)
  })

  it('discards an unconfirmed draft by keeping the applied filters', () => {
    const applied: ClientFilterState = { status: 'active', regime: 'all', deadline: 'all' }
    const draft: ClientFilterState = { status: 'inactive', regime: 'mei', deadline: 'expired' }

    assert.deepEqual(applied, { status: 'active', regime: 'all', deadline: 'all' })
    assert.notDeepEqual(draft, applied)
  })

  it('removes one chip and leaves the others', () => {
    const applied: ClientFilterState = { status: 'active', regime: 'simple_national', deadline: 'all' }
    const next = removeClientFilter(applied, 'regime')

    assert.deepEqual(next, { status: 'active', regime: 'all', deadline: 'all' })
    assert.deepEqual(applied.regime, 'simple_national')
  })

  it('clears every category back to the default', () => {
    const cleared = resetClientFilters()
    assert.deepEqual(cleared, defaultClientFilters)
    assert.equal(countActiveFilters(cleared), 0)
  })

  it('builds chips only for active categories', () => {
    const chips = activeFilterChips({
      status: 'active',
      regime: 'simple_national',
      deadline: 'all'
    }, labels)

    assert.deepEqual(chips, [
      { key: 'status', label: 'Ativo' },
      { key: 'regime', label: 'Simples Nacional' }
    ])
  })
})
