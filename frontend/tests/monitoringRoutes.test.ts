// tests/monitoringRoutes.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  monitoringGroups,
  monitoringListPath,
  monitoringObligations,
  monitoringObligationUnserved,
  monitoringPageActive,
  monitoringPages,
  monitoringSidebarChildren,
  monitoringTabs,
  parseMonitoringSlug
} from '../app/utils/monitoringNav.ts'
import { monitoringActions, monitoringSlipColumns } from '../app/utils/monitoringPresentation.ts'

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

  it('does not resolve the moved office-certificate screen', () => {
    // The e-CNPJ screen lives in the Painel Global now: no static route claims
    // `/monitoring/termos`, so the catch-all has to answer null.
    assert.equal(parseMonitoringSlug(['termos']), null)
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

  it('does not resolve the removed execution screens', () => {
    // The execuções screens were taken out with the MonitorHub redesign: no
    // obligation is spelled `execucoes`, so the catch-all answers null and the
    // registry is what says the slug leads nowhere.
    assert.equal(parseMonitoringSlug(['execucoes']), null)
    assert.equal(parseMonitoringSlug(['execucoes', '12']), null)
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
    for (const column of pgdas.columns) {
      assert.ok(
        ['name', 'situacao', 'ultima_consulta', 'ultima_declaracao'].includes(column.id),
        `${column.id} resolves to nothing`
      )
    }
  })

  it('declares no column the panel does not know where to read', () => {
    // Three sources, and a column has to name one of them: the row itself, the
    // guide derivation, or a `fields` key the backend populates. Listing the third
    // here is what makes it a contract — a new column id that is not in any of
    // the three fails here instead of rendering a blank column in production.
    const fromRow = new Set(['name', 'situacao', 'due_on', 'ultima_consulta', 'ultima_declaracao'])
    const fromFields = new Set([
      // `rbt12`: the mapper for CONSULTAROPCAOREGIME103 does not populate this key yet.
      'rbt12',
      'divida_ativa', 'receitas',
      'valor_apurado_1718', 'certidao', 'validade', 'nao_lidas', 'ultima'
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

describe('the module navigation', () => {
  it('offers Painel as the only module tab', () => {
    // The execuções screens are gone; the whole module is a drill-down from
    // Painel and the obligations are its children.
    assert.deepEqual(monitoringPages.map(page => page.to), ['/monitoring'])
  })

  it('keeps the office certificate out of the module', () => {
    // The e-CNPJ moved to the Painel Global; no module destination may still
    // point at the old screen.
    assert.equal(monitoringPages.some(page => page.to === '/monitoring/termos'), false)
  })

  it('lights exactly one tab per destination', () => {
    for (const path of [
      '/monitoring',
      '/monitoring/simples-nacional',
      '/monitoring/declaracoes/pgdas',
      '/monitoring/declaracoes/pgdas/atencao'
    ]) {
      const [group] = monitoringTabs(path)
      const active = group.filter(item => item.active)
      assert.equal(active.length, 1, `${path} lights ${active.map(i => i.label).join(', ') || 'nothing'}`)
    }
  })

  it('lights every obligation under Painel, the screen that indexes them', () => {
    // A tab bar with nothing lit on the obligation screens is the defect this
    // rule exists to prevent, so it is asserted over the whole registry rather
    // than on a sample.
    for (const obligation of monitoringObligations) {
      const [group] = monitoringTabs(monitoringListPath(obligation))
      const active = group.filter(item => item.active)
      assert.equal(active.length, 1, `${obligation.slug} lights ${active.length} tabs`)
      assert.equal(active[0]?.label, 'Painel')
    }
  })

  it('lists the sidebar in the same order as the tabs, obligations after Painel', () => {
    const children = monitoringSidebarChildren('/monitoring')
    assert.equal(children[0]?.label, 'Painel')
    // Painel + the eight obligation groups, and nothing after them: the
    // integration screens the list used to close with are gone.
    assert.equal(children.length, monitoringPages.length + monitoringGroups.length)
    assert.equal(children.every(child => child.icon == null), true)
    assert.equal(children[children.length - 1]?.label, 'Declarações')
  })

  it('names exactly one position in the sidebar, on every screen', () => {
    // The tab bar answers "which part of the module"; the rail answers "exactly
    // where am I". Inside an obligation the group is the position, so Painel has
    // to go dark there — the two rules are deliberately different.
    const paths = [
      '/monitoring',
      ...monitoringObligations.map(item => monitoringListPath(item)),
      ...monitoringObligations.map(item => `${monitoringListPath(item)}/atencao`)
    ]
    for (const path of paths) {
      const active = monitoringSidebarChildren(path).filter(child => child.active)
      assert.equal(active.length, 1, `${path} lights ${active.map(i => i.label).join(', ') || 'nothing'}`)
    }
  })

  it('hands the position to the group inside an obligation, not to Painel', () => {
    const children = monitoringSidebarChildren('/monitoring/declaracoes/pgdas')
    assert.equal(children.find(child => child.label === 'Declarações')?.active, true)
    assert.equal(children.find(child => child.label === 'Painel')?.active, false)
  })

  it('keeps Painel lit on every module path, the drill-downs included', () => {
    // With Painel as the only tab, every path under /monitoring is its
    // drill-down — the rule that used to dim it for the integration screens
    // has nothing left to dim it for.
    for (const path of ['/monitoring', ...monitoringObligations.map(item => monitoringListPath(item))]) {
      assert.equal(monitoringPageActive(path, monitoringPages[0]!), true, `${path} leaves Painel dark`)
    }
  })

  it('keeps the search action label spelled once', () => {
    // The toolbar button and the modal's confirm button say the same words;
    // the label lives in the presentation module both read.
    assert.ok(monitoringActions.searchDocuments.length > 0)
  })
})
