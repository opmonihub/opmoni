import type { OfficeErpAllowance } from '../types/officeErpCommercial'
import { formatErpMoney } from './erpFinance.ts'

export function defaultOfficeErpAllowance(): OfficeErpAllowance {
  return { enabled: false, licence_limit: 0, module_keys: [] }
}

export const officeErpModuleOptions = [
  { value: 'purchases', label: 'Compras' },
  { value: 'add_sale', label: 'Vendas' },
  { value: 'pos_sale', label: 'Ponto de venda' },
  { value: 'stock_transfers', label: 'Transferências de estoque' },
  { value: 'stock_adjustment', label: 'Ajustes de estoque' },
  { value: 'expenses', label: 'Despesas' },
  { value: 'cte', label: 'CT-e' },
  { value: 'mdfe', label: 'MDF-e' }
]

const officeErpMoneyLimit = '999999999999999999'

function normalizeOfficeErpCents(integer: string, fraction = ''): string {
  const normalizedInteger = integer.replace(/^0+(?=\d)/, '')
  if (normalizedInteger.length > officeErpMoneyLimit.length
    || (normalizedInteger.length === officeErpMoneyLimit.length && normalizedInteger > officeErpMoneyLimit)) {
    throw new Error('Money amount exceeds the supported limit')
  }

  return `${normalizedInteger}.${fraction.padEnd(2, '0')}`
}

export function parseOfficeErpMoney(input: string): string {
  if (typeof input !== 'string' || input.length === 0 || input.startsWith('-'))
    throw new Error('Invalid money amount')

  const brazilianDecimal = /^(\d+),(\d{1,2})$/.exec(input)
  if (brazilianDecimal)
    return normalizeOfficeErpCents(brazilianDecimal[1]!, brazilianDecimal[2]!)

  const brazilianGrouped = /^(\d{1,3}(?:\.\d{3})+),(\d{1,2})$/.exec(input)
  if (brazilianGrouped)
    return normalizeOfficeErpCents(brazilianGrouped[1]!.replaceAll('.', ''), brazilianGrouped[2]!)

  const canonicalDecimal = /^(\d+)(?:\.(\d{1,2}))?$/.exec(input)
  if (canonicalDecimal)
    return normalizeOfficeErpCents(canonicalDecimal[1]!, canonicalDecimal[2] ?? '')

  throw new Error('Invalid money amount')
}

export function formatOfficeErpMoney(value: string): string {
  const canonical = parseOfficeErpMoney(value)
  return formatErpMoney(canonical, 'R$')
}
