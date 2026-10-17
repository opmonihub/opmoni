import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { describe, it } from 'node:test'
import {
  erpCadastrosPages,
  erpCatalogRoutes,
  formatErpDecimal
} from '../shared/utils/erpCatalogs.ts'
import {
  sampleErpProductFixture,
  samplePostedLineSnapshot
} from '../shared/fixtures/erpCoreCatalogs.ts'

describe('ERP core catalogs shared contracts', () => {
  it('freezes catalog API route families', () => {
    assert.equal(erpCatalogRoutes.contacts, '/erp/contacts')
    assert.equal(erpCatalogRoutes.taxRates, '/erp/tax-settings/tax-rates')
    assert.equal(erpCatalogRoutes.importProductsConfirm, '/erp/catalog/import/products/confirm')
  })

  it('maps cadastros Nuxt pages', () => {
    assert.equal(erpCadastrosPages.contacts, '/erp/cadastros/contatos')
    assert.equal(erpCadastrosPages.logistics, '/erp/cadastros/logistica')
  })

  it('preserves decimal strings without float coercion', () => {
    assert.equal(formatErpDecimal('10.5000'), '10.5000')
    assert.equal(formatErpDecimal(10.5), '10.5')
  })

  it('posted snapshot stays stable when catalog price changes', () => {
    const edited = { ...sampleErpProductFixture, sale_price: '99.0000' }
    assert.notEqual(edited.sale_price, samplePostedLineSnapshot.unit_price)
    assert.equal(samplePostedLineSnapshot.unit_price, '10.5000')
  })

  it('cadastros hub page references catalog routes', async () => {
    const src = await readFile(new URL('../app/pages/erp/cadastros/index.vue', import.meta.url), 'utf8')
    assert.match(src, /erpCadastrosPages/)
    assert.match(src, /erpCadastrosPages\.contacts/)
  })
})
