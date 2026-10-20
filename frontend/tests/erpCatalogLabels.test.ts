import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { ErpCatalogLabelItem, ErpCatalogPrintLayout } from '../shared/types/erpCatalogs.ts'
import {
  ErpCatalogLabelGeometryError,
  erpLabelSelectionKey,
  isExactErpLabelBarcode,
  planErpCatalogLabelPages,
  setErpLabelSelection,
  totalErpLabelCopies,
  updateErpLabelQuantity
} from '../shared/utils/erpCatalogLabels.ts'

function layout(overrides: Partial<ErpCatalogPrintLayout> = {}): ErpCatalogPrintLayout {
  return {
    id: 9,
    name: 'Folha fracionada',
    description: null,
    label_width_mm: '45.5000',
    label_height_mm: '29.2500',
    paper_width_mm: '100.0000',
    paper_height_mm: '100.0000',
    top_margin_mm: '2.0000',
    left_margin_mm: '2.0000',
    row_spacing_mm: '1.2500',
    column_spacing_mm: '2.0000',
    columns_per_row: 2,
    labels_per_sheet: 4,
    is_continuous: false,
    is_default: false,
    ...overrides
  }
}

function item(id: number, quantity: number, overrides: Partial<ErpCatalogLabelItem> = {}): ErpCatalogLabelItem {
  return {
    id,
    variant_id: null,
    product_name: `Produto ${id}`,
    variant_name: null,
    sku: null,
    barcode: `A${id}`,
    barcode_type: 'C128',
    sale_price: '12.3400',
    quantity,
    ...overrides
  }
}

describe('ERP catalog label selection and physical layout', () => {
  it('keeps selection order and independent product and variant keys across page changes', () => {
    let selected = setErpLabelSelection([], { product_id: 12, quantity: 1 }, true)
    selected = setErpLabelSelection(selected, { product_id: 8, variant_id: 88, quantity: 3 }, true)
    selected = setErpLabelSelection(selected, { product_id: 7, quantity: 2 }, true)
    selected = updateErpLabelQuantity(selected, 'variant:88', 4)

    assert.deepEqual(selected.map(erpLabelSelectionKey), ['product:12', 'variant:88', 'product:7'])
    assert.equal(totalErpLabelCopies(selected), 7)
    assert.equal(setErpLabelSelection(selected, { product_id: 12, quantity: 1 }, false).length, 2)
  })

  it('plans copies in request order and starts a new sheet at the saved count', () => {
    const plan = planErpCatalogLabelPages(layout(), [item(11, 2), item(22, 2)], null)

    assert.deepEqual(plan.copies.map(copy => copy.id), [11, 11, 22, 22])
    assert.equal(plan.pages.length, 1)
    assert.deepEqual(plan.pages[0]?.copyIndexes, [0, 1, 2, 3])
    assert.equal(plan.pages[0]?.widthMm, '100.0000')
    assert.equal(plan.pages[0]?.heightMm, '100.0000')
  })

  it('checks continuous output height with exact fixed-point decimals', () => {
    const continuous = layout({
      label_width_mm: '40.0000',
      label_height_mm: '10.0000',
      paper_width_mm: '100.0000',
      paper_height_mm: null,
      top_margin_mm: '1.0000',
      left_margin_mm: '1.0000',
      row_spacing_mm: '0.2500',
      column_spacing_mm: '0.0000',
      columns_per_row: 2,
      labels_per_sheet: null,
      is_continuous: true
    })
    const plan = planErpCatalogLabelPages(continuous, [item(1, 3)], '21.2500')

    assert.equal(plan.continuous, true)
    assert.equal(plan.pages[0]?.heightMm, '21.2500')
    assert.deepEqual(plan.pages[0]?.copyIndexes, [0, 1, 2])
    assert.throws(() => planErpCatalogLabelPages(continuous, [item(1, 3)], '21.2499'), ErpCatalogLabelGeometryError)
  })

  it('rejects physically nonfitting layouts and output above the copy bound', () => {
    assert.throws(
      () => planErpCatalogLabelPages(layout({ paper_width_mm: '90.0000' }), [item(1, 1)], null),
      (error: unknown) => error instanceof ErpCatalogLabelGeometryError && error.field === 'paper_width_mm'
    )
    assert.throws(() => planErpCatalogLabelPages(layout(), [item(1, 251)], null), ErpCatalogLabelGeometryError)
  })
})

describe('exact ERP label barcode input', () => {
  it('rejects library normalization and synthesized check digits while preserving spaces and zeros', () => {
    for (const [type, code] of [
      ['C128', '00012345678905'], ['C128', ' A 01 '], ['C39', ' A-01 '],
      ['EAN13', '5901234123457'], ['EAN8', '96385074'], ['UPCA', '036000291452'], ['UPCE', '04252614']
    ]) assert.equal(isExactErpLabelBarcode(type, code), true, `${type} ${code}`)
    for (const [type, code] of [
      ['C39', 'abc'], ['C39', 'ABC\n'], ['C128', 'ABC\n'], ['EAN13', '590123412345'], ['EAN13', '5901234123458'], ['UPCA', '03600029145'], ['UPCE', '425261'], ['UPCE', '04252615'], ['C128', 'é'], ['QR', '123']
    ]) assert.equal(isExactErpLabelBarcode(type, code), false, `${type} ${code}`)
  })
})
