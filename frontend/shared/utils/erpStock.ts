export interface ErpStockAdjustmentInput {
  erp_establishment_id: string
  erp_product_variant_id: string
  quantity_delta: string
  reason: string
}

export interface ErpStockTransferInput {
  from_establishment_id: string
  to_establishment_id: string
  erp_product_variant_id: string
  quantity: string
}

export interface ErpStockReversalInput {
  quantity: string
  remaining_quantity: string
  reason: string
}

function decimalInteger(value: string, label: string, allowNegative = false): bigint {
  const pattern = allowNegative ? /^-?\d{1,18}(?:\.\d{1,4})?$/ : /^\d{1,18}(?:\.\d{1,4})?$/
  if (!pattern.test(value))
    throw new Error(`${label} deve usar até quatro casas decimais.`)
  const negative = value.startsWith('-')
  const unsigned = negative ? value.slice(1) : value
  const [whole = '0', fraction = ''] = unsigned.split('.')
  const scaled = BigInt(whole) * 10_000n + BigInt(fraction.padEnd(4, '0'))
  return negative ? -scaled : scaled
}

function decimalText(value: bigint): string {
  const negative = value < 0n
  const absolute = negative ? -value : value
  return `${negative ? '-' : ''}${absolute / 10_000n}.${String(absolute % 10_000n).padStart(4, '0')}`
}

function positiveId(value: string, label: string): number {
  if (!/^\d{1,15}$/.test(value))
    throw new Error(`Selecione ${label}.`)
  const id = Number(value)
  if (!Number.isSafeInteger(id) || id < 1)
    throw new Error(`Selecione ${label}.`)
  return id
}

function requiredReason(value: string): string {
  const reason = value.trim()
  if (!reason)
    throw new Error('Informe o motivo da operação.')
  if (reason.length > 255)
    throw new Error('O motivo deve ter até 255 caracteres.')
  return reason
}

const stockWriteActions = new Set([
  'erp.stock.adjustments.store', 'erp.stock.transfers.store', 'erp.stock.transfers.reverse', 'erp.stock.movements.reverse'
])

export function stockActionAllowed(grants: readonly string[] | undefined, role: string | undefined, action: string): boolean {
  if (!grants?.length || (stockWriteActions.has(action) && role === 'viewer')) return false
  return grants.includes('*') || grants.includes(action)
}

export function buildErpStockAdjustmentPayload(input: ErpStockAdjustmentInput) {
  const delta = decimalInteger(input.quantity_delta, 'Diferença', true)
  if (delta === 0n)
    throw new Error('Informe uma diferença de quantidade diferente de zero.')
  return {
    erp_establishment_id: positiveId(input.erp_establishment_id, 'um estabelecimento'),
    erp_product_variant_id: positiveId(input.erp_product_variant_id, 'uma variante'),
    quantity_delta: decimalText(delta),
    reason: requiredReason(input.reason)
  }
}

export function buildErpStockTransferPayload(input: ErpStockTransferInput) {
  const from = positiveId(input.from_establishment_id, 'o estabelecimento de origem')
  const to = positiveId(input.to_establishment_id, 'o estabelecimento de destino')
  const quantity = decimalInteger(input.quantity, 'Quantidade')
  if (from === to)
    throw new Error('Selecione estabelecimentos diferentes para origem e destino.')
  if (quantity <= 0n)
    throw new Error('Informe uma quantidade positiva.')
  return {
    from_establishment_id: from,
    to_establishment_id: to,
    erp_product_variant_id: positiveId(input.erp_product_variant_id, 'uma variante'),
    quantity: decimalText(quantity)
  }
}

export function buildErpStockReversalPayload(input: ErpStockReversalInput) {
  const remaining = decimalInteger(input.remaining_quantity, 'Saldo restante')
  if (remaining <= 0n)
    throw new Error('Não há saldo restante para corrigir.')
  const requested = input.quantity.trim() ? decimalInteger(input.quantity, 'Quantidade') : remaining
  if (requested <= 0n)
    throw new Error('Informe uma quantidade positiva.')
  if (requested > remaining)
    throw new Error('A quantidade excede o saldo restante da origem.')
  return { quantity: decimalText(requested), reason: requiredReason(input.reason) }
}

export function remainingStockQuantity(quantity: string, reversedQuantity: string): string {
  const total = decimalInteger(quantity, 'Quantidade original')
  const reversed = decimalInteger(reversedQuantity, 'Quantidade corrigida')
  if (total < 0n || reversed < 0n || reversed > total)
    throw new Error('O histórico de correção excede a quantidade original.')
  return decimalText(total - reversed)
}

export interface ErpStockCostSummary {
  cost_status: string | null
  total_cost: string | null
  known_cost: string | null
  unvalued_quantity: string | null
  currency: string | null
  cost_breakdown: Array<{ currency?: string | null, total_cost?: string | null, known_cost?: string | null }> | null
}

export function stockCostDescription(cost: ErpStockCostSummary): string {
  const status = cost.cost_status ?? 'unknown'
  const unvalued = cost.unvalued_quantity
  const hasUnvalued = unvalued !== null && decimalInteger(unvalued, 'Quantidade sem valoração') > 0n
  const unvaluedNote = hasUnvalued ? `; quantidade sem valor: ${unvalued}` : ''

  if (status === 'mixed_currency') {
    const breakdown = (cost.cost_breakdown ?? []).map((row) => {
      const amount = row.known_cost ?? row.total_cost
      return amount === null || amount === undefined
        ? `${row.currency ?? 'Moeda não identificada'}: custo desconhecido`
        : `${row.currency ?? 'Moeda não identificada'} conhecido ${amount}`
    })
    const summary = breakdown.length > 0
      ? `Custos em múltiplas moedas: ${breakdown.join(' · ')}`
      : 'Custos em múltiplas moedas; consulte as alocações'
    return `${summary}${unvaluedNote}`
  }

  if (status === 'known' && cost.total_cost !== null) {
    const summary = cost.currency ? `${cost.currency} ${cost.total_cost}` : `Custo conhecido sem moeda identificada: ${cost.total_cost}`
    return `${summary}${unvaluedNote}`
  }

  let summary = status === 'legacy_unknown'
    ? 'Custo legado desconhecido'
    : status === 'unvalued'
      ? 'Custo total desconhecido'
      : status === 'known'
        ? 'Custo classificado como conhecido, total não disponível'
        : 'Status de custo não reconhecido; consulte as alocações'
  if (cost.known_cost !== null)
    summary += `; parcela conhecida${cost.currency ? ` ${cost.currency}` : ' sem moeda identificada'} ${cost.known_cost}`
  return `${summary}${unvaluedNote}`
}
