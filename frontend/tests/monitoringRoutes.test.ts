// tests/monitoringRoutes.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  monitoringGroups,
  monitoringIntegrationLinks,
  monitoringListPath,
  monitoringObligations,
  monitoringObligationUnserved,
  parseMonitoringSlug
} from '../app/utils/monitoringNav.ts'
import { isMonitoringSlipColumn, monitoringSlipColumns } from '../app/utils/monitoringPresentation.ts'

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

  it('declares no provenance for an obligation that is not a projection', () => {
    for (const item of monitoringObligations) {
      if (item.category !== 'derived') {
        assert.equal(item.derivedFrom, undefined, `${item.slug} is ${item.category} and claims a source`)
      }
    }
  })

  it('declares only columns the panel can actually read', () => {
    const pgdas = monitoringObligations.find(item => item.slug === 'declaracoes/pgdas')
    assert.ok(pgdas)
    // The guide columns are read from the row's synchronized periods, so each
    // one has to be a column the presentation module knows how to read; the rest
    // resolve from the row itself or from `fields`.
    for (const column of pgdas.columns) {
      assert.ok(
        isMonitoringSlipColumn(column.id) || ['name', 'situacao', 'due_on', 'gi_declaracao'].includes(column.id),
        `${column.id} resolves to nothing`
      )
    }
    for (const id of ['guia', 'guia_numero', 'guia_emitida_em', 'guia_vencimento', 'guia_paga']) {
      assert.ok(
        pgdas.columns.some(column => column.id === id),
        `the ${id} reading is declared by no column`
      )
    }
  })

  it('declares no column the panel does not know where to read', () => {
    // Three sources, and a column has to name one of them: the row itself, the
    // guide derivation, or a `fields` key the backend populates. Listing the third
    // here is what makes it a contract — a new column id that is not in any of
    // the three fails here instead of rendering a blank column in production.
    const fromRow = new Set(['name', 'situacao', 'due_on'])
    const fromFields = new Set([
      'regime_escolhido', 'data_da_opcao', 'divida_ativa', 'gi_declaracao', 'receitas',
      'valor_apurado_1718', 'certidao', 'emissao', 'validade', 'nao_lidas', 'ultima'
    ])
    const readable = new Set([...fromRow, ...fromFields, ...Object.keys(monitoringSlipColumns)])

    for (const item of monitoringObligations) {
      for (const column of item.columns) {
        assert.ok(readable.has(column.id), `${item.slug}: ${column.id} is read by nothing`)
      }
    }
  })

  it('marks exactly the three obligations the provider does not serve as unserved', () => {
    const unserved = monitoringObligations.filter(item => monitoringObligationUnserved(item)).map(item => item.slug)
    assert.deepEqual(unserved, ['parcelamentos/pgfn', 'declaracoes/fgts', 'declaracoes/dirf'])
  })

  it('treats only unavailable and extinct as unserved, never a derived one', () => {
    for (const item of monitoringObligations) {
      const expected = item.category === 'unavailable' || item.category === 'extinct'
      assert.equal(monitoringObligationUnserved(item), expected, `${item.slug} is ${item.category}`)
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
