// tests/monitoringRoutes.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  monitoringGroups,
  monitoringIntegrationLinks,
  monitoringListPath,
  monitoringObligations,
  parseMonitoringSlug
} from '../app/utils/monitoringNav.ts'

describe('monitoring routes', () => {
  it('resolves an obligation slug', () => {
    const listing = parseMonitoringSlug(['simples-nacional'])
    assert.equal(listing?.obligation.slug, 'simples-nacional')
    assert.equal(listing?.situacao, null)
  })

  it('resolves a two-segment obligation slug', () => {
    const listing = parseMonitoringSlug(['parcelamentos', 'pgfn'])
    assert.equal(listing?.obligation.slug, 'parcelamentos/pgfn')
  })

  it('resolves a situation segment and returns it', () => {
    const listing = parseMonitoringSlug(['caixas-postais', 'det', 'atencao'])
    assert.equal(listing?.obligation.slug, 'caixas-postais/det')
    assert.equal(listing?.situacao, 'atencao')
  })

  it('returns null for an unknown obligation', () => {
    assert.equal(parseMonitoringSlug(['nao-existe']), null)
  })

  it('treats an unknown trailing segment as part of the body, not a situation', () => {
    assert.equal(parseMonitoringSlug(['simples-nacional', 'nao-existe']), null)
  })

  it('returns null for a situation with no obligation', () => {
    assert.equal(parseMonitoringSlug(['atencao']), null)
  })

  it('does not claim the integration screens, which resolve as static routes', () => {
    for (const link of monitoringIntegrationLinks) {
      const slug = link.to.replace('/monitoring/', '').split('/')
      assert.equal(parseMonitoringSlug(slug), null, `${link.to} must resolve ahead of the catch-all`)
    }
  })
})

describe('the obligation registry', () => {
  it('resolves all nineteen obligations', () => {
    assert.equal(monitoringObligations.length, 19)
  })

  it('has no duplicate slug', () => {
    const slugs = monitoringObligations.map(item => item.slug)
    assert.equal(new Set(slugs).size, slugs.length)
  })

  it('declares a catalogue source for every served obligation', () => {
    for (const item of monitoringObligations) {
      if (item.category === 'direct' || item.category === 'derived') {
        assert.ok(item.service, `${item.slug} is ${item.category} with no service`)
      } else {
        assert.equal(item.service, null, `${item.slug} is ${item.category} with a service`)
        assert.deepEqual(item.columns, [], `${item.slug} is ${item.category} and must declare no column`)
      }
    }
  })

  it('names what a derived obligation projects over', () => {
    for (const item of monitoringObligations) {
      if (item.category === 'derived') assert.ok(item.derivedFrom, `${item.slug} does not name its source`)
    }
  })

  it('groups into eight top-level items, four with sub-tabs', () => {
    assert.equal(monitoringGroups.length, 8)
    assert.equal(monitoringGroups.filter(group => group.pages.length > 1).length, 4)
  })

  it('builds a list path carrying the situation', () => {
    const simples = monitoringObligations.find(item => item.slug === 'simples-nacional')
    assert.ok(simples)
    assert.equal(monitoringListPath(simples), '/monitoring/simples-nacional')
    assert.equal(monitoringListPath(simples, 'atencao'), '/monitoring/simples-nacional/atencao')
  })
})
