// tests/monitoringModules.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  initialMonitoringModuleSelection,
  isServedObligationCategory,
  monitoringModuleGroups,
  monitoringModulesPayload,
  sortMonitoringModules
} from '../app/utils/monitoringModules.ts'
import type { MonitoringModuleItem } from '../app/types/serpro.ts'

function module(overrides: Partial<MonitoringModuleItem>): MonitoringModuleItem {
  return {
    slug: 'declaracoes/pgdas',
    label: 'PGDAS',
    category: 'direct',
    suggested: false,
    associated: false,
    ...overrides
  }
}

describe('isServedObligationCategory', () => {
  it('serves direct and derived only', () => {
    assert.equal(isServedObligationCategory('direct'), true)
    assert.equal(isServedObligationCategory('derived'), true)
    assert.equal(isServedObligationCategory('unavailable'), false)
    assert.equal(isServedObligationCategory('extinct'), false)
  })
})

describe('sortMonitoringModules', () => {
  it('drops obligations the provider does not serve', () => {
    const sorted = sortMonitoringModules([
      module({ slug: 'extinta', category: 'extinct' }),
      module({ slug: 'indisponivel', category: 'unavailable' }),
      module({ slug: 'servida', category: 'derived' })
    ])
    assert.deepEqual(sorted.map(item => item.slug), ['servida'])
  })

  it('puts suggested first, then alphabetical by label', () => {
    const sorted = sortMonitoringModules([
      module({ slug: 'z', label: 'Zebra', suggested: false }),
      module({ slug: 'b', label: 'Beta', suggested: true }),
      module({ slug: 'a', label: 'Alfa', suggested: true }),
      module({ slug: 'y', label: 'Amarelo', suggested: false })
    ])
    assert.deepEqual(sorted.map(item => item.slug), ['a', 'b', 'y', 'z'])
  })

  it('does not mutate the input list', () => {
    const input = [
      module({ slug: 'b', label: 'Beta', suggested: false }),
      module({ slug: 'a', label: 'Alfa', suggested: true })
    ]
    sortMonitoringModules(input)
    assert.deepEqual(input.map(item => item.slug), ['b', 'a'])
  })
})

describe('monitoringModuleGroups', () => {
  it('groups suggested before the others', () => {
    const groups = monitoringModuleGroups({
      obligations: [
        module({ slug: 'pgdas', label: 'PGDAS', suggested: true }),
        module({ slug: 'ecac', label: 'Caixa postal e-CAC', suggested: false })
      ]
    })
    assert.equal(groups.length, 2)
    assert.equal(groups[0]?.id, 'suggested')
    assert.equal(groups[1]?.id, 'available')
  })

  it('omits an empty group rather than drawing a header over nothing', () => {
    const onlyAvailable = monitoringModuleGroups({ obligations: [module({ slug: 'ecac' })] })
    assert.equal(onlyAvailable.length, 1)
    assert.equal(onlyAvailable[0]?.id, 'available')

    const onlySuggested = monitoringModuleGroups({ obligations: [module({ slug: 'pgdas', suggested: true })] })
    assert.equal(onlySuggested.length, 1)
    assert.equal(onlySuggested[0]?.id, 'suggested')
  })

  it('returns no groups when nothing is served', () => {
    const groups = monitoringModuleGroups({
      obligations: [module({ slug: 'extinta', category: 'extinct' })]
    })
    assert.equal(groups.length, 0)
  })
})

describe('initialMonitoringModuleSelection', () => {
  it('pre-checks suggested obligations', () => {
    const selected = initialMonitoringModuleSelection({
      obligations: [
        module({ slug: 'pgdas', suggested: true }),
        module({ slug: 'ecac', suggested: false })
      ]
    })
    assert.deepEqual([...selected], ['pgdas'])
  })

  it('keeps an already-associated obligation checked even when not suggested', () => {
    const selected = initialMonitoringModuleSelection({
      obligations: [
        module({ slug: 'pgdas', suggested: true }),
        module({ slug: 'antiga', associated: true })
      ]
    })
    assert.ok(selected.has('pgdas'))
    assert.ok(selected.has('antiga'))
  })

  it('never pre-checks an unserved obligation', () => {
    const selected = initialMonitoringModuleSelection({
      obligations: [module({ slug: 'extinta', category: 'extinct', suggested: true })]
    })
    assert.equal(selected.size, 0)
  })
})

describe('monitoringModulesPayload', () => {
  const obligations = [
    module({ slug: 'pgdas', label: 'PGDAS', suggested: true }),
    module({ slug: 'ecac', label: 'Caixa postal', suggested: true }),
    module({ slug: 'mei', label: 'PGMEI', suggested: false })
  ]

  it('sends the selected slugs in the displayed order', () => {
    const payload = monitoringModulesPayload({ obligations }, new Set(['mei', 'pgdas']))
    assert.deepEqual(payload, ['pgdas', 'mei'])
  })

  it('keeps an associated-but-not-suggested slug the member left checked', () => {
    const payload = monitoringModulesPayload({ obligations }, new Set(['mei']))
    assert.deepEqual(payload, ['mei'])
  })

  it('sends an empty list when nothing is selected', () => {
    assert.deepEqual(monitoringModulesPayload({ obligations }, new Set()), [])
  })
})
