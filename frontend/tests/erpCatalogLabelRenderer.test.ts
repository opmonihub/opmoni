import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { ErpCatalogLabelResponse } from '../shared/types/erpCatalogs.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { installLabelDocument, labelResponse } from './helpers/erpLabelDocument.ts'

function renderer() {
  return loadAppComposable<{
    renderErpCatalogLabelOutput(response: ErpCatalogLabelResponse, isCurrent: () => boolean): Promise<{
      blob: Blob
      copies: number
      pages: number
      filename: string
    }>
  }>('utils/erpCatalogLabelRenderer.ts').renderErpCatalogLabelOutput
}
describe('ERP standalone label renderer with real pinned JsBarcode', () => {
  it('outputs three real SVG labels, exact text/money, escaped content and fractional page geometry without external resources', async (t) => {
    const dom = installLabelDocument()
    t.after(dom.restore)
    const output = await renderer()(labelResponse(), () => true)
    const html = await output.blob.text()
    assert.equal(output.copies, 3)
    assert.equal(output.pages, 1)
    assert.equal(output.filename, 'etiquetas-produtos.html')
    assert.equal((html.match(/class="label"/g) ?? []).length, 3)
    assert.equal((html.match(/<svg /g) ?? []).length, 3)
    assert.match(html, /<rect /)
    assert.match(html, /00012345678905/)
    assert.match(html, /R\$ 0,0000/)
    assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/)
    assert.match(html, /Caixa &amp; Chá/)
    assert.match(html, /@page \{ size: 180.5000mm 90.7500mm; margin: 0;/)
    assert.match(html, /--label-width:80.1250mm/)
    assert.doesNotMatch(html, /<script|<img|<iframe|<link|<[^>]+\ssrc=/)
    assert.equal(dom.doc.body.children.length, 0, 'measurement nodes are removed')
  })
  it('renders all six explicit symbologies and rejects normalization or insufficient physical width', async (t) => {
    const dom = installLabelDocument()
    t.after(dom.restore)
    for (const [barcode_type, barcode] of [['C128', ' A 01 '], ['C39', ' A-01 '], ['EAN13', '5901234123457'], ['EAN8', '96385074'], ['UPCA', '036000291452'], ['UPCE', '04252614']] as const) {
      const output = await renderer()(labelResponse({ barcode_type, barcode }), () => true)
      assert.match(await output.blob.text(), new RegExp(`<div class="label-barcode-text">${barcode}</div>`))
    }
    for (const [barcode_type, barcode] of [['C39', 'abc'], ['EAN13', '590123412345'], ['UPCE', '425261']] as const)
      await assert.rejects(renderer()(labelResponse({ barcode_type, barcode }), () => true), /simbologia ou código de barras inválido/)
    const response = labelResponse({ barcode: 'A'.repeat(64) })
    await assert.rejects(renderer()(response, () => true), /zona de silêncio/)
  })
  it('honors toggles and aborts late render jobs before returning bytes', async (t) => {
    const dom = installLabelDocument()
    t.after(dom.restore)
    const response = labelResponse({ barcode: null })
    response.meta.toggles = { show_price: false, show_barcode: false }
    const html = await (await renderer()(response, () => true)).blob.text()
    assert.doesNotMatch(html, /<svg|class="label-price"|class="label-barcode"/)
    let calls = 0
    await assert.rejects(renderer()(labelResponse(), () => ++calls < 2), { name: 'AbortError' })
  })
})
