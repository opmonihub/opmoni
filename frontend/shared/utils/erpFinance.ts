export type ErpFinanceAction = 'erp.financial-accounts.index' | 'erp.financial-accounts.show' | 'erp.financial-accounts.store' | 'erp.obligations.index' | 'erp.obligations.show' | 'erp.obligations.store' | 'erp.payments.index' | 'erp.payments.show' | 'erp.payments.allocations.index' | 'erp.payments.store' | 'erp.payments.allocate' | 'erp.payments.reverse' | 'erp.payments.receipt' | 'erp.payment-allocations.reverse' | 'erp.establishments.index' | 'erp.cash-sessions.index'

export function erpFinanceAllowed(grants: readonly string[] | undefined, role: string | undefined, action: ErpFinanceAction): boolean {
  if (!grants?.length)
    return false
  const write = action.endsWith('.store') || action.endsWith('.allocate') || action.endsWith('.reverse')
  if (write && role === 'viewer')
    return false
  const family = action.slice(0, action.lastIndexOf('.'))
  const familyRoot = family.split('.').slice(0, 2).join('.')
  const scope = write ? 'write' : 'read'
  return grants.some(grant => grant === '*'
    || grant === action
    || grant === `${family}.*`
    || grant === `${familyRoot}.*`
    || grant === `${familyRoot}.${scope}`
    || grant === (write ? 'erp.write' : 'erp.read'))
}

export function formatErpMoney(value: string, currency: string): string {
  const [whole = '0', fraction = ''] = value.split('.')
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `${currency} ${grouped}${fraction ? `,${fraction}` : ''}`
}

export function decimalTextIsValid(value: string): boolean {
  return /^\d{1,18}(?:\.\d{1,4})?$/.test(value) && /[1-9]/.test(value)
}
