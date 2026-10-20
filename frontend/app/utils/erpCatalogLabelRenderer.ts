import type { ErpCatalogLabelItem, ErpCatalogLabelResponse } from '#shared/types/erpCatalogs'
import { ErpCatalogLabelGeometryError, isExactErpLabelBarcode, planErpCatalogLabelPages } from '#shared/utils/erpCatalogLabels'

const svgNamespace = 'http://www.w3.org/2000/svg'
const barcodeModulePx = 1
const quietZoneModules = 10
const labelPaddingPx = 4
const barcodeHeightPx = 40

type PreparedCopy = {
  item: ErpCatalogLabelItem
  svg: SVGSVGElement | null
}

export type ErpCatalogLabelOutput = {
  blob: Blob
  filename: string
  copies: number
  pages: number
}

function barcodeFormat(type: ErpCatalogLabelItem['barcode_type']): string {
  return {
    C128: 'CODE128',
    C39: 'CODE39',
    EAN13: 'EAN13',
    EAN8: 'EAN8',
    UPCA: 'UPC',
    UPCE: 'UPCE'
  }[type]
}

function dimensionUnits(value: string): number {
  if (!/^\d+(?:\.\d{1,4})?$/.test(value))
    throw new ErpCatalogLabelGeometryError('A configuração de impressão contém uma dimensão inválida.')
  const [whole, fraction = ''] = value.split('.')
  return Number(whole) * 10_000 + Number(fraction.padEnd(4, '0'))
}

function itemName(item: ErpCatalogLabelItem): string {
  return item.variant_name ? `${item.product_name} · ${item.variant_name}` : item.product_name
}

function makeLabelNode(doc: Document, copy: PreparedCopy, barcodeEnabled: boolean, priceEnabled: boolean): HTMLElement {
  const label = doc.createElement('article')
  label.className = 'label'
  const content = doc.createElement('div')
  content.className = 'label-content'

  const name = doc.createElement('div')
  name.className = 'label-name'
  name.textContent = itemName(copy.item)
  content.append(name)

  if (copy.item.sku) {
    const sku = doc.createElement('div')
    sku.className = 'label-sku'
    sku.textContent = `SKU ${copy.item.sku}`
    content.append(sku)
  }

  if (barcodeEnabled) {
    if (!copy.svg || !copy.item.barcode)
      throw new Error(`${itemName(copy.item)}: código de barras ausente.`)
    const barcode = doc.createElement('div')
    barcode.className = 'label-barcode'
    barcode.append(doc.importNode(copy.svg, true))
    const text = doc.createElement('div')
    text.className = 'label-barcode-text'
    text.textContent = copy.item.barcode
    barcode.append(text)
    content.append(barcode)
  }

  if (priceEnabled) {
    const price = doc.createElement('div')
    price.className = 'label-price'
    price.textContent = copy.item.sale_price === null ? 'Preço não informado' : `R$ ${copy.item.sale_price.replace('.', ',')}`
    content.append(price)
  }

  label.append(content)
  return label
}

async function barcodeSvg(item: ErpCatalogLabelItem, isCurrent: () => boolean): Promise<SVGSVGElement> {
  if (!isExactErpLabelBarcode(item.barcode_type, item.barcode))
    throw new Error(`${itemName(item)}: simbologia ou código de barras inválido.`)
  const module = await import('jsbarcode')
  if (!isCurrent())
    throw new DOMException('Contexto alterado.', 'AbortError')
  const JsBarcode = module.default
  const svg = document.createElementNS(svgNamespace, 'svg')
  let libraryAccepted = true
  try {
    JsBarcode(svg, item.barcode!, {
      format: barcodeFormat(item.barcode_type),
      width: barcodeModulePx,
      height: barcodeHeightPx,
      displayValue: false,
      margin: 0,
      marginLeft: quietZoneModules,
      marginRight: quietZoneModules,
      marginTop: 0,
      marginBottom: 0,
      lineColor: '#000000',
      background: '#ffffff',
      valid: (valid: boolean) => { libraryAccepted = valid }
    })
  } catch {
    throw new Error(`${itemName(item)}: o código não pode ser renderizado na simbologia ${item.barcode_type}.`)
  }
  if (!libraryAccepted || !svg.getAttribute('viewBox'))
    throw new Error(`${itemName(item)}: o código não pode ser renderizado na simbologia ${item.barcode_type}.`)
  svg.setAttribute('role', 'img')
  svg.setAttribute('aria-label', `Código de barras ${item.barcode_type}`)
  svg.setAttribute('focusable', 'false')
  return svg
}

function assertNaturalWidth(svg: SVGSVGElement, item: ErpCatalogLabelItem, labelWidthMm: string): void {
  const viewBox = svg.getAttribute('viewBox')?.split(/\s+/).map(Number)
  const naturalWidthPx = viewBox?.[2]
  if (!Number.isFinite(naturalWidthPx) || naturalWidthPx! <= 0)
    throw new Error(`${itemName(item)}: não foi possível medir o código de barras.`)

  // A CSS pixel is exactly 25.4/96 mm. Compare as integer ratios so a rounded
  // display conversion cannot silently squeeze the module or its quiet zone.
  const availableWidthUnits = dimensionUnits(labelWidthMm) * 96
  const requiredWidthUnits = (naturalWidthPx! + labelPaddingPx * 2) * 254_000
  if (requiredWidthUnits > availableWidthUnits) {
    throw new Error(`${itemName(item)}: o código completo com a zona de silêncio de 10 módulos não cabe na largura da etiqueta.`)
  }
}

function measureCopy(copy: PreparedCopy, response: ErpCatalogLabelResponse): void {
  const layout = response.meta.layout
  const measurementRoot = document.createElement('div')
  measurementRoot.style.position = 'fixed'
  measurementRoot.style.left = '-10000px'
  measurementRoot.style.top = '0'
  measurementRoot.style.visibility = 'hidden'
  measurementRoot.style.pointerEvents = 'none'
  const label = makeLabelNode(document, copy, response.meta.toggles.show_barcode, response.meta.toggles.show_price)
  label.style.width = `${layout.label_width_mm}mm`
  label.style.height = `${layout.label_height_mm}mm`
  label.style.boxSizing = 'border-box'
  const content = label.firstElementChild as HTMLElement
  content.style.width = '100%'
  content.style.height = '100%'
  content.style.boxSizing = 'border-box'
  content.style.padding = `${labelPaddingPx}px`
  content.style.display = 'flex'
  content.style.flexDirection = 'column'
  content.style.gap = '2px'
  const name = content.querySelector('.label-name') as HTMLElement
  name.style.flex = '0 0 auto'
  name.style.font = '600 12px/14px Arial, sans-serif'
  name.style.overflowWrap = 'anywhere'
  const sku = content.querySelector('.label-sku') as HTMLElement | null
  if (sku) {
    sku.style.flex = '0 0 auto'
    sku.style.font = '9px/10px Arial, sans-serif'
    sku.style.overflowWrap = 'anywhere'
  }
  const barcode = content.querySelector('.label-barcode') as HTMLElement | null
  if (barcode) {
    barcode.style.flex = '0 0 auto'
    barcode.style.display = 'flex'
    barcode.style.flexDirection = 'column'
    barcode.style.alignItems = 'center'
    barcode.style.gap = '1px'
    const svg = barcode.querySelector('svg') as SVGSVGElement
    svg.style.display = 'block'
    svg.style.maxWidth = 'none'
    assertNaturalWidth(svg, copy.item, layout.label_width_mm)
    const barcodeText = barcode.querySelector('.label-barcode-text') as HTMLElement
    barcodeText.style.font = '9px/10px monospace'
    barcodeText.style.overflowWrap = 'anywhere'
    barcodeText.style.whiteSpace = 'pre-wrap'
  }
  const price = content.querySelector('.label-price') as HTMLElement | null
  if (price) {
    price.style.flex = '0 0 auto'
    price.style.font = '600 12px/14px Arial, sans-serif'
    price.style.overflowWrap = 'anywhere'
  }
  measurementRoot.append(label)
  document.body.append(measurementRoot)
  try {
    const style = getComputedStyle(content)
    const verticalPadding = Number.parseFloat(style.paddingTop) + Number.parseFloat(style.paddingBottom)
    const availableHeight = content.getBoundingClientRect().height - verticalPadding
    const occupiedHeight = [...content.children].reduce((total, child) => total + child.getBoundingClientRect().height, 0)
      + Math.max(0, content.children.length - 1) * 2
    if (occupiedHeight > availableHeight + 0.25 || content.scrollWidth > content.clientWidth) {
      throw new Error(`${itemName(copy.item)}: o nome, o preço ou o código completo não cabe na altura da etiqueta.`)
    }
  } finally {
    measurementRoot.remove()
  }
}

function outputStyles(widthMm: string, heightMm: string): string {
  return `
    @page { size: ${widthMm}mm ${heightMm}mm; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; font-family: Arial, sans-serif; color: #111; background: #e8eaed; }
    .sheet { width: ${widthMm}mm; height: ${heightMm}mm; margin: 16px auto; padding: var(--top-margin) 0 0 var(--left-margin); display: grid; grid-template-columns: repeat(var(--columns), var(--label-width)); grid-auto-rows: var(--label-height); column-gap: var(--column-spacing); row-gap: var(--row-spacing); align-content: start; background: white; break-after: page; page-break-after: always; }
    .sheet:last-child { break-after: auto; page-break-after: auto; }
    .label { width: var(--label-width); height: var(--label-height); overflow: visible; border: 0; background: white; }
    .label-content { width: 100%; height: 100%; padding: ${labelPaddingPx}px; display: flex; flex-direction: column; gap: 2px; overflow: visible; }
    .label-name { flex: 0 0 auto; font: 600 12px/14px Arial, sans-serif; overflow-wrap: anywhere; }
    .label-sku { flex: 0 0 auto; font: 9px/10px Arial, sans-serif; overflow-wrap: anywhere; }
    .label-barcode { flex: 0 0 auto; display: flex; flex-direction: column; align-items: center; gap: 1px; }
    .label-barcode svg { display: block; max-width: none; }
    .label-barcode-text { white-space: pre-wrap; font: 9px/10px monospace; overflow-wrap: anywhere; }
    .label-price { flex: 0 0 auto; font: 600 12px/14px Arial, sans-serif; overflow-wrap: anywhere; }
    @media screen { body { overflow: auto; } .sheet { box-shadow: 0 1px 5px #0002; } }
    @media print { html, body { background: white; } .sheet { margin: 0; box-shadow: none; } }
  `
}

function buildStandaloneDocument(
  plan: ReturnType<typeof planErpCatalogLabelPages>,
  prepared: PreparedCopy[],
  response: ErpCatalogLabelResponse
): Blob {
  const firstPage = plan.pages[0]!
  const doc = document.implementation.createHTMLDocument('Etiquetas de produtos')
  const meta = doc.createElement('meta')
  meta.setAttribute('charset', 'utf-8')
  doc.head.append(meta)
  doc.documentElement.lang = 'pt-BR'
  const policy = doc.createElement('meta')
  policy.setAttribute('http-equiv', 'Content-Security-Policy')
  policy.setAttribute('content', 'default-src \'none\'; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'')
  doc.head.append(policy)
  const style = doc.createElement('style')
  style.textContent = outputStyles(firstPage.widthMm, firstPage.heightMm)
  doc.head.append(style)

  for (const page of plan.pages) {
    const sheet = doc.createElement('section')
    sheet.className = 'sheet'
    sheet.setAttribute('aria-label', `Folha ${page.index + 1}`)
    sheet.style.setProperty('--top-margin', `${page.topMarginMm}mm`)
    sheet.style.setProperty('--left-margin', `${page.leftMarginMm}mm`)
    sheet.style.setProperty('--columns', String(page.columns))
    sheet.style.setProperty('--label-width', `${response.meta.layout.label_width_mm}mm`)
    sheet.style.setProperty('--label-height', `${response.meta.layout.label_height_mm}mm`)
    sheet.style.setProperty('--row-spacing', `${page.rowSpacingMm}mm`)
    sheet.style.setProperty('--column-spacing', `${page.columnSpacingMm}mm`)
    for (const copyIndex of page.copyIndexes) {
      const copy = prepared[copyIndex]!
      sheet.append(makeLabelNode(doc, copy, response.meta.toggles.show_barcode, response.meta.toggles.show_price))
    }
    doc.body.append(sheet)
  }

  return new Blob([`<!doctype html>${doc.documentElement.outerHTML}`], { type: 'text/html;charset=utf-8' })
}

export async function renderErpCatalogLabelOutput(
  response: ErpCatalogLabelResponse,
  isCurrent: () => boolean
): Promise<ErpCatalogLabelOutput> {
  if (typeof document === 'undefined' || typeof window === 'undefined')
    throw new Error('A prévia e o arquivo de etiquetas só podem ser gerados no navegador.')
  if (!Array.isArray(response.data) || !response.data.length || response.meta.total_copies < 1 || response.meta.total_copies > 250)
    throw new ErpCatalogLabelGeometryError('A resposta de etiquetas está fora dos limites permitidos.')

  const plan = planErpCatalogLabelPages(response.meta.layout, response.data, response.meta.output_page_height_mm)
  if (plan.copies.length !== response.meta.total_copies)
    throw new ErpCatalogLabelGeometryError('A quantidade total da resposta não corresponde às linhas selecionadas.')

  const prepared: PreparedCopy[] = []
  const renderedCodes = new Map<string, SVGSVGElement>()
  for (const item of plan.copies) {
    if (!isCurrent())
      throw new DOMException('Contexto alterado.', 'AbortError')
    let svg: SVGSVGElement | null = null
    if (response.meta.toggles.show_barcode) {
      const key = `${item.barcode_type}\u0000${item.barcode ?? ''}`
      svg = renderedCodes.get(key) ?? null
      if (!svg) {
        svg = await barcodeSvg(item, isCurrent)
        assertNaturalWidth(svg, item, response.meta.layout.label_width_mm)
        renderedCodes.set(key, svg)
      }
    }
    const copy = { item, svg }
    measureCopy(copy, response)
    prepared.push(copy)
  }
  if (!isCurrent())
    throw new DOMException('Contexto alterado.', 'AbortError')

  const blob = buildStandaloneDocument(plan, prepared, response)
  if (!isCurrent())
    throw new DOMException('Contexto alterado.', 'AbortError')
  return { blob, filename: 'etiquetas-produtos.html', copies: prepared.length, pages: plan.pages.length }
}
