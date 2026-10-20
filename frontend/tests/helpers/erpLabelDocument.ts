import type { ErpCatalogLabelResponse } from '../../shared/types/erpCatalogs.ts'
/** Minimal DOM boundary for renderer logic tests. It does not claim browser layout or pixel evidence. */
export class LabelElement {
  nodeName: string
  attributes: Record<string, string> = {}
  children: LabelElement[] = []
  parentNode: LabelElement | null = null
  textContent = ''
  style: Record<string, unknown> = { setProperty: (key: string, value: string) => {
    this.style[key] = value
  } }

  clicked = false
  constructor(name: string) {
    this.nodeName = name
  }

  set className(value: string) {
    this.attributes.class = value
  }

  get className() {
    return this.attributes.class ?? ''
  }

  setAttribute(key: string, value: unknown) {
    this.attributes[key] = String(value)
  }

  getAttribute(key: string) {
    return this.attributes[key] ?? null
  }

  hasAttribute(key: string) {
    return Object.hasOwn(this.attributes, key)
  }

  append(...children: LabelElement[]) {
    for (const child of children) {
      child.remove()
      child.parentNode = this
      this.children.push(child)
    }
  }

  appendChild(child: LabelElement) {
    this.append(child)
    return child
  }

  removeChild(child: LabelElement) {
    child.remove()
  }

  remove() {
    if (this.parentNode)
      this.parentNode.children.splice(this.parentNode.children.indexOf(this), 1)
    this.parentNode = null
  }

  get firstChild() {
    return this.children[0] ?? null
  }

  get firstElementChild() {
    return this.firstChild
  }

  querySelector(selector: string): LabelElement | null {
    for (const child of this.children) {
      if (selector.startsWith('.') ? child.className.split(' ').includes(selector.slice(1)) : child.nodeName === selector)
        return child
      const nested = child.querySelector(selector)
      if (nested)
        return nested
    }
    return null
  }

  getBoundingClientRect(): {
    height: number
  } {
    if (this.style.height === '100%')
      return this.parentNode!.getBoundingClientRect()
    if (typeof this.style.height === 'string' && this.style.height.endsWith('mm'))
      return { height: Number.parseFloat(this.style.height) * 96 / 25.4 }
    return { height: this.className === 'label-barcode' ? 51 : this.className === 'label-name' || this.className === 'label-price' ? 14 : 10 }
  }

  get scrollWidth() {
    return 100
  }

  get clientWidth() {
    return 100
  }

  click() {
    this.clicked = true
  }

  get outerHTML(): string {
    const escape = (value: string) => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;')
    const attrs = Object.entries(this.attributes).map(([key, value]) => ` ${key}="${escape(value)}"`).join('')
    const css = Object.entries(this.style).filter(([, value]) => typeof value === 'string').map(([key, value]) => `${key}:${value}`).join(';')
    return `<${this.nodeName}${attrs}${css ? ` style="${escape(css)}"` : ''}>${this.nodeName === 'style' ? this.textContent : escape(this.textContent)}${this.children.map(child => child.outerHTML).join('')}</${this.nodeName}>`
  }
}
export function labelDocument() {
  const html = new LabelElement('html')
  const head = new LabelElement('head')
  const body = new LabelElement('body')
  html.append(head, body)
  const imported: LabelElement[] = []
  const clone = (node: LabelElement): LabelElement => {
    const copy = new LabelElement(node.nodeName)
    copy.attributes = { ...node.attributes }
    copy.textContent = node.textContent
    copy.style = { ...node.style }
    node.children.forEach(child => copy.append(clone(child)))
    imported.push(copy)
    return copy
  }
  return {
    documentElement: html, head, body, imported,
    createElement: (name: string) => new LabelElement(name), createElementNS: (_namespace: string, name: string) => new LabelElement(name),
    importNode: clone, implementation: { createHTMLDocument: () => labelDocument() }
  }
}
export function installLabelDocument() {
  const doc = labelDocument()
  const previous = new Map(['document', 'window', 'getComputedStyle'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]))
  Object.defineProperty(globalThis, 'document', { configurable: true, value: doc })
  Object.defineProperty(globalThis, 'window', { configurable: true, value: {} })
  Object.defineProperty(globalThis, 'getComputedStyle', { configurable: true, value: () => ({ paddingTop: '4px', paddingBottom: '4px' }) })
  return { doc, restore() {
    for (const [key, descriptor] of previous) {
      if (descriptor)
        Object.defineProperty(globalThis, key, descriptor)
      else
        Reflect.deleteProperty(globalThis, key)
    }
  } }
}
export function labelResponse(overrides: Partial<ErpCatalogLabelResponse['data'][number]> = {}): ErpCatalogLabelResponse {
  return {
    data: [{ id: 8, variant_id: 81, product_name: '<img src=x onerror=alert(1)>', variant_name: 'Caixa & Chá', sku: '0', barcode: '00012345678905', barcode_type: 'C128', sale_price: '0.0000', quantity: 3, ...overrides }],
    meta: {
      layout: { id: 1, name: 'Layout', description: null, label_width_mm: '80.1250', label_height_mm: '40.2500', paper_width_mm: '180.5000', paper_height_mm: '90.7500', top_margin_mm: '2.0000', left_margin_mm: '3.0000', row_spacing_mm: '1.0000', column_spacing_mm: '2.0000', columns_per_row: 2, labels_per_sheet: 4, is_continuous: false, is_default: false },
      toggles: { show_price: true, show_barcode: true }, compatibility_fallback: false, total_copies: 3, output_page_height_mm: null
    }
  }
}
