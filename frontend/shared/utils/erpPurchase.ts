import type { ErpPurchaseReturnableLine, ErpPurchaseXmlMapping } from '#shared/types/erpBusiness'

export type ErpPurchaseReturnSource = {
  erp_purchase_id: number
  currency: string
  returnability?: { needs_reconciliation: boolean, reconciliation_reason?: string | null }
  returnable_lines: ErpPurchaseReturnableLine[]
}

function scaledInteger(value: string, scale: number, label: string): bigint {
  if (!new RegExp(`^\\d{1,18}(?:\\.\\d{1,${scale}})?$`).test(value))
    throw new Error(`${label} deve usar até ${scale} casas decimais.`)
  const [whole = '0', fraction = ''] = value.split('.')
  return BigInt(whole) * 10n ** BigInt(scale) + BigInt(fraction.padEnd(scale, '0') || '0')
}

function decimalString(value: bigint, scale: number): string {
  const unit = 10n ** BigInt(scale)
  const whole = value / unit
  const fraction = String(value % unit).padStart(scale, '0')
  return `${whole}.${fraction}`
}

export function formatPurchaseDecimal(value: string): string {
  const negative = value.startsWith('-')
  const scaled = scaledInteger(negative ? value.slice(1) : value, 4, 'Valor')
  const whole = String(scaled / 10_000n).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  const fraction = String(scaled % 10_000n).padStart(4, '0')
  return `${negative && scaled > 0n ? '-' : ''}${whole},${fraction}`
}

export function convertPurchaseQuantity(quantity: string, factor: string): string {
  const quantityScaled = scaledInteger(quantity, 4, 'Quantidade')
  const factorScaled = scaledInteger(factor, 8, 'Fator')
  if (factorScaled <= 0n)
    throw new Error('Informe um fator de conversão positivo.')
  const baseScaled = quantityScaled * factorScaled / 100_000_000n
  if (quantityScaled * factorScaled % 100_000_000n !== 0n)
    throw new Error('A conversão excede a precisão de quatro casas.')
  return decimalString(baseScaled, 4)
}

export function purchaseUnitFactor(unitId: number, baseUnitId: number, units: Array<{ id: number, base_unit_id: number | null, conversion_factor: string }>): string | null {
  if (unitId === baseUnitId) return '1.00000000'
  const byId = new Map(units.map(unit => [unit.id, unit]))
  let unit = byId.get(unitId)
  let factor = 100_000_000n
  const visited = new Set<number>()
  while (unit && !visited.has(unit.id) && visited.size < 100) {
    visited.add(unit.id)
    const next = unit.base_unit_id
    if (!next) return null
    const nextFactor = scaledInteger(unit.conversion_factor, 8, 'Fator')
    const product = factor * nextFactor
    if (product % 100_000_000n !== 0n) return null
    factor = product / 100_000_000n
    if (next === baseUnitId) return decimalString(factor, 8)
    unit = byId.get(next)
  }
  return null
}

export function calculatePurchaseLineTotal(quantity: string, unitCost: string, discount = '0.0000', tax = '0.0000'): string {
  const quantityScaled = scaledInteger(quantity, 4, 'Quantidade')
  const costScaled = scaledInteger(unitCost, 4, 'Custo unitário')
  const discountScaled = scaledInteger(discount, 4, 'Desconto')
  const taxScaled = scaledInteger(tax, 4, 'Imposto')
  const gross = quantityScaled * costScaled / 10_000n
  if (discountScaled > gross)
    throw new Error('O desconto não pode exceder o valor bruto.')
  return decimalString(gross - discountScaled + taxScaled, 4)
}

export function addPurchaseMoney(left: string, right: string): string {
  return decimalString(scaledInteger(left, 4, 'Valor') + scaledInteger(right, 4, 'Valor'), 4)
}

export function replacePurchaseTax(total: string, previousTax: string, nextTax: string): string {
  return decimalString(scaledInteger(total, 4, 'Valor') - scaledInteger(previousTax, 4, 'Imposto') + scaledInteger(nextTax, 4, 'Imposto'), 4)
}

export function samePurchaseDecimal(left: string, right: string, scale = 4): boolean {
  try {
    return scaledInteger(left, scale, 'Valor') === scaledInteger(right, scale, 'Valor')
  } catch {
    return false
  }
}

export function buildPurchaseReturnPayload(
  source: ErpPurchaseReturnSource,
  selected: Record<string, string>,
  reason: string
): { erp_purchase_id: number, reason: string, lines: Array<{ source_line_id: number, quantity: string }> } {
  if (source.returnability?.needs_reconciliation)
    throw new Error('O histórico exige conciliação antes de criar uma devolução.')
  const lines = source.returnable_lines.flatMap((line) => {
    const quantity = selected[String(line.source_line_id)]
    if (!quantity)
      return []
    const requested = scaledInteger(quantity, 4, 'Quantidade')
    const remaining = line.remaining_quantity === null ? null : scaledInteger(line.remaining_quantity, 4, 'Saldo disponível')
    if (remaining === null || requested <= 0n || requested > remaining)
      throw new Error('A quantidade excede o saldo disponível da linha de origem.')
    return [{ source_line_id: line.source_line_id, quantity: decimalString(requested, 4) }]
  })
  if (!reason.trim() || lines.length === 0)
    throw new Error('Informe o motivo e ao menos uma quantidade para devolução.')
  return { erp_purchase_id: source.erp_purchase_id, reason: reason.trim(), lines }
}

export function buildXmlConfirmationItems(lineCount: number, mappings: ErpPurchaseXmlMapping[]): ErpPurchaseXmlMapping[] {
  if (!Number.isSafeInteger(lineCount) || lineCount < 1 || mappings.length !== lineCount)
    throw new Error('Mapeie todos os itens do XML antes de confirmar.')
  const lines = new Set<number>()
  for (const mapping of mappings) {
    if (!Number.isSafeInteger(mapping.line) || mapping.line < 1 || mapping.line > lineCount || lines.has(mapping.line))
      throw new Error('Cada linha do XML deve ser mapeada uma única vez.')
    if (!Number.isSafeInteger(mapping.erp_product_variant_id) || mapping.erp_product_variant_id < 1)
      throw new Error('Selecione um produto ativo para cada linha do XML.')
    scaledInteger(mapping.unit_conversion_factor, 8, 'Fator de conversão')
    if (scaledInteger(mapping.unit_conversion_factor, 8, 'Fator de conversão') <= 0n)
      throw new Error('Informe um fator de conversão positivo.')
    if (mapping.erp_unit_id !== undefined && (!Number.isSafeInteger(mapping.erp_unit_id) || mapping.erp_unit_id < 1))
      throw new Error('Selecione uma unidade ativa para cada linha do XML.')
    lines.add(mapping.line)
  }
  return mappings.map(mapping => ({ ...mapping })).sort((left, right) => left.line - right.line)
}
