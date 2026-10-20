import type { ErpCatalogLabelItem, ErpCatalogPrintLayout } from '../types/erpCatalogs'

const scale = 10_000

export type SelectedErpCatalogLabel = {
  product_id: number
  variant_id?: number
  quantity: number
}

export type ErpCatalogLabelPage = {
  index: number
  copyIndexes: number[]
  widthMm: string
  heightMm: string
  columns: number
  topMarginMm: string
  leftMarginMm: string
  rowSpacingMm: string
  columnSpacingMm: string
}

export type ErpCatalogLabelPagePlan = {
  copies: ErpCatalogLabelItem[]
  pages: ErpCatalogLabelPage[]
  continuous: boolean
}

export class ErpCatalogLabelGeometryError extends Error {
  readonly field: string

  constructor(message: string, field: string = 'layout_id') {
    super(message)
    this.name = 'ErpCatalogLabelGeometryError'
    this.field = field
  }
}

export function erpLabelSelectionKey(item: Pick<SelectedErpCatalogLabel, 'product_id' | 'variant_id'>): string {
  return item.variant_id === undefined
    ? `product:${item.product_id}`
    : `variant:${item.variant_id}`
}

export function setErpLabelSelection(
  current: SelectedErpCatalogLabel[],
  item: SelectedErpCatalogLabel,
  selected: boolean
): SelectedErpCatalogLabel[] {
  const key = erpLabelSelectionKey(item)
  const index = current.findIndex(candidate => erpLabelSelectionKey(candidate) === key)
  if (selected) {
    if (index >= 0)
      return current.map((candidate, currentIndex) => currentIndex === index ? { ...item } : candidate)
    return [...current, { ...item }]
  }
  return index < 0 ? current : current.filter((_, currentIndex) => currentIndex !== index)
}

export function updateErpLabelQuantity(
  current: SelectedErpCatalogLabel[],
  key: string,
  quantity: number
): SelectedErpCatalogLabel[] {
  if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 250)
    return current
  return current.map(item => erpLabelSelectionKey(item) === key ? { ...item, quantity } : item)
}

export function totalErpLabelCopies(items: readonly SelectedErpCatalogLabel[]): number {
  return items.reduce((total, item) => total + item.quantity, 0)
}

function decimalUnits(value: string | null, field: string): number {
  if (value === null || !/^\d+(?:\.\d{1,4})?$/.test(value))
    throw new ErpCatalogLabelGeometryError('A configuração de impressão contém uma dimensão inválida.', field)
  const [whole, fraction = ''] = value.split('.')
  const units = Number(whole) * scale + Number(fraction.padEnd(4, '0'))
  if (!Number.isSafeInteger(units))
    throw new ErpCatalogLabelGeometryError('A configuração de impressão contém uma dimensão inválida.', field)
  return units
}

function assertRange(value: number, minimum: number, maximum: number, field: string): void {
  if (value < minimum * scale || value > maximum * scale)
    throw new ErpCatalogLabelGeometryError('A dimensão da configuração está fora dos limites.', field)
}

function normalizedMm(units: number): string {
  const whole = Math.floor(units / scale)
  const fraction = String(units % scale).padStart(4, '0')
  return `${whole}.${fraction}`
}

export function planErpCatalogLabelPages(
  layout: ErpCatalogPrintLayout,
  items: readonly ErpCatalogLabelItem[],
  outputPageHeight: string | null
): ErpCatalogLabelPagePlan {
  const width = decimalUnits(layout.label_width_mm, 'label_width_mm')
  const height = decimalUnits(layout.label_height_mm, 'label_height_mm')
  const paperWidth = decimalUnits(layout.paper_width_mm, 'paper_width_mm')
  const topMargin = decimalUnits(layout.top_margin_mm, 'top_margin_mm')
  const leftMargin = decimalUnits(layout.left_margin_mm, 'left_margin_mm')
  const rowSpacing = decimalUnits(layout.row_spacing_mm, 'row_spacing_mm')
  const columnSpacing = decimalUnits(layout.column_spacing_mm, 'column_spacing_mm')
  const columns = layout.columns_per_row

  assertRange(width, 20, 200, 'label_width_mm')
  assertRange(height, 10, 200, 'label_height_mm')
  assertRange(paperWidth, 20, 500, 'paper_width_mm')
  assertRange(topMargin, 0, 100, 'top_margin_mm')
  assertRange(leftMargin, 0, 100, 'left_margin_mm')
  assertRange(rowSpacing, 0, 100, 'row_spacing_mm')
  assertRange(columnSpacing, 0, 100, 'column_spacing_mm')
  if (!Number.isSafeInteger(columns) || columns < 1 || columns > 20)
    throw new ErpCatalogLabelGeometryError('O número de colunas está fora dos limites.', 'columns_per_row')

  const usedWidth = leftMargin + columns * width + (columns - 1) * columnSpacing
  if (usedWidth > paperWidth)
    throw new ErpCatalogLabelGeometryError('A largura da folha não comporta as etiquetas.', 'paper_width_mm')

  const copies: ErpCatalogLabelItem[] = []
  for (const item of items) {
    if (!Number.isSafeInteger(item.quantity) || item.quantity < 1 || item.quantity > 250)
      throw new ErpCatalogLabelGeometryError('A quantidade da etiqueta está fora dos limites.', 'quantity')
    if (copies.length + item.quantity > 250)
      throw new ErpCatalogLabelGeometryError('A seleção pode gerar no máximo 250 etiquetas.', 'quantity')
    for (let index = 0; index < item.quantity; index++)
      copies.push(item)
  }
  if (copies.length < 1)
    throw new ErpCatalogLabelGeometryError('Selecione ao menos uma etiqueta.')

  const pageHeightUnits = layout.is_continuous
    ? (() => {
        if (layout.paper_height_mm !== null || layout.labels_per_sheet !== null)
          throw new ErpCatalogLabelGeometryError('A configuração contínua deve deixar a altura e a quantidade por folha vazias.')
        const rows = Math.ceil(copies.length / columns)
        return topMargin + rows * height + Math.max(0, rows - 1) * rowSpacing
      })()
    : (() => {
        if (layout.paper_height_mm === null || layout.labels_per_sheet === null || !Number.isSafeInteger(layout.labels_per_sheet) || layout.labels_per_sheet < 1 || layout.labels_per_sheet > 250)
          throw new ErpCatalogLabelGeometryError('A configuração em folhas precisa da altura e da quantidade por folha.', 'labels_per_sheet')
        const paperHeight = decimalUnits(layout.paper_height_mm, 'paper_height_mm')
        assertRange(paperHeight, 10, 2000, 'paper_height_mm')
        const rows = Math.ceil(layout.labels_per_sheet / columns)
        const usedHeight = topMargin + rows * height + Math.max(0, rows - 1) * rowSpacing
        if (usedHeight > paperHeight)
          throw new ErpCatalogLabelGeometryError('A altura da folha não comporta as etiquetas.', 'paper_height_mm')
        if (outputPageHeight !== null)
          throw new ErpCatalogLabelGeometryError('A resposta de impressão contém uma altura contínua inesperada.')
        return paperHeight
      })()

  if (layout.is_continuous) {
    if (pageHeightUnits > 2000 * scale)
      throw new ErpCatalogLabelGeometryError('A altura contínua ultrapassa o limite de 2.000 mm.')
    if (outputPageHeight === null || decimalUnits(outputPageHeight, 'output_page_height_mm') !== pageHeightUnits)
      throw new ErpCatalogLabelGeometryError('A altura de impressão contínua não corresponde à seleção.')
  }

  const labelsPerPage = layout.is_continuous ? copies.length : layout.labels_per_sheet!
  const pages: ErpCatalogLabelPage[] = []
  for (let start = 0; start < copies.length; start += labelsPerPage) {
    pages.push({
      index: pages.length,
      copyIndexes: copies.slice(start, start + labelsPerPage).map((_, index) => start + index),
      widthMm: normalizedMm(paperWidth),
      heightMm: normalizedMm(pageHeightUnits),
      columns,
      topMarginMm: normalizedMm(topMargin),
      leftMarginMm: normalizedMm(leftMargin),
      rowSpacingMm: normalizedMm(rowSpacing),
      columnSpacingMm: normalizedMm(columnSpacing)
    })
  }

  return { copies, pages, continuous: layout.is_continuous }
}

export function isBarcodeType(value: unknown): value is ErpCatalogLabelItem['barcode_type'] {
  return value === 'C128' || value === 'C39' || value === 'EAN13' || value === 'EAN8' || value === 'UPCA' || value === 'UPCE'
}

/** Validate the full source value before JsBarcode can normalize case or add check digits. */
export function isExactErpLabelBarcode(type: unknown, value: unknown): boolean {
  if (typeof value !== 'string' || value.length < 1 || value.length > 64)
    return false
  const checkDigit = (code: string, length: number, leftWeight: number) => {
    if (code.length !== length || !/^\d+$/.test(code))
      return false
    const sum = [...code.slice(0, -1)].reduce((total, digit, index) => total + Number(digit) * (index % 2 === 0 ? leftWeight : (leftWeight === 1 ? 3 : 1)), 0)
    return String((10 - sum % 10) % 10) === code.at(-1)
  }
  switch (type) {
    case 'C128': return !/[^\x20-\x7E]/.test(value)
    case 'C39': return !/[^A-Z0-9 .$/+%-]/.test(value)
    case 'EAN13': return checkDigit(value, 13, 1)
    case 'EAN8': return checkDigit(value, 8, 3)
    case 'UPCA': return checkDigit(value, 12, 3)
    case 'UPCE': {
      if (!/^[01]\d{7}$/.test(value))
        return false
      const digits = value.slice(1, 7)
      const last = digits[5]!
      const expanded = ['0', '1', '2'].includes(last)
        ? `${value[0]}${digits.slice(0, 2)}${last}0000${digits.slice(2, 5)}`
        : last === '3'
          ? `${value[0]}${digits.slice(0, 3)}00000${digits.slice(3, 5)}`
          : last === '4'
            ? `${value[0]}${digits.slice(0, 4)}00000${digits[4]}`
            : `${value[0]}${digits.slice(0, 5)}0000${last}`
      return checkDigit(expanded + value[7], 12, 3)
    }
    default: return false
  }
}
