import type { ErpCommercialDocument, ErpCommercialDraftPayload } from '#shared/types/erpBusiness'
import { erpSavedTaxContext } from './erpCommercialTaxReview.ts'

export function erpSalePostPayload(row: Pick<ErpCommercialDocument, 'draft_revision' | 'total_amount'>, payment?: Record<string, unknown>): Record<string, unknown> {
  return {
    draft_revision: row.draft_revision,
    expected_total: row.total_amount,
    ...(payment ? { payment } : {})
  }
}

export function erpReturnLineError(
  sourceLineId: number,
  submittedSourceLineIds: number[],
  errors: Record<string, string>
): string {
  const submittedIndex = submittedSourceLineIds.indexOf(sourceLineId)
  return submittedIndex < 0 ? '' : errors[`lines.${submittedIndex}.quantity`] ?? ''
}

const writeActions = new Set([
  'erp.quotes.store', 'erp.quotes.update', 'erp.quotes.convert',
  'erp.orders.store', 'erp.orders.update', 'erp.orders.convert',
  'erp.sales.store', 'erp.sales.update', 'erp.sales.post',
  'erp.sale-returns.store', 'erp.sale-returns.post',
  'erp.payments.store', 'erp.payments.allocate', 'erp.cash-sessions.movements.store',
  'erp.sales.price.override', 'erp.sales.discount.override'
])
const exactOverrideActions = new Set(['erp.sales.price.override', 'erp.sales.discount.override'])
const readActions = new Set([
  'erp.quotes.index', 'erp.quotes.show', 'erp.orders.index', 'erp.orders.show',
  'erp.sales.index', 'erp.sales.show', 'erp.sales.representation', 'erp.sale-returns.index', 'erp.sale-returns.show',
  'erp.establishments.index', 'erp.contacts.index', 'erp.units.index', 'erp.products.index', 'erp.operation-natures.index',
  'erp.payments.index', 'erp.payments.show', 'erp.payments.receipt',
  'erp.obligations.index', 'erp.obligations.show', 'erp.fiscal.documents.index'
])

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Record<string, unknown> : {}
}

function id(value: unknown): number | null {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : null
}

function snapshotDescription(snapshot: Record<string, unknown>, line: ErpCommercialDocument['lines'][number]): string {
  if (typeof snapshot.description === 'string' && snapshot.description.trim()) return snapshot.description
  const product = record(snapshot.product)
  const variant = record(snapshot.variant)
  const unit = record(snapshot.unit)
  const names = [product.name, variant.name].filter((value): value is string => typeof value === 'string' && value.trim().length > 0)
  if (names.length) return names.join(' / ')
  if (typeof unit.short_name === 'string') return `Item em ${unit.short_name}`
  return line.id ? `Linha #${line.id}` : 'Item comercial'
}

export function erpCommercialDocumentToDraft(document: ErpCommercialDocument): ErpCommercialDraftPayload {
  const firstSnapshot = record(document.lines[0]?.product_snapshot)
  const headerDiscount = record(firstSnapshot.header_discount)
  const headerTax = record(firstSnapshot.header_tax)
  const headerDiscountRule = record(headerDiscount.rule)
  const headerRuleId = id(headerDiscountRule.id)
  const discountInput = record(headerDiscount.submitted)
  const hasHeaderDiscount = headerDiscount.mode === 'manual' || headerDiscount.mode === 'rule'
  const hasHeaderTax = typeof headerTax.rate_percent === 'string'
  const frozenNatureIds = document.lines.map(line => id(record(record(line.product_snapshot).operation_nature).id))
  const headerNatureId = id(document.erp_operation_nature_id)
  const frozenNatureId = frozenNatureIds.length && frozenNatureIds.every(value => value === frozenNatureIds[0]) ? frozenNatureIds[0] ?? null : null
  const operationNatureId = headerNatureId && (!frozenNatureIds.length || frozenNatureId === headerNatureId) ? headerNatureId : headerNatureId ? null : frozenNatureId
  const draft: ErpCommercialDraftPayload = {
    erp_establishment_id: document.erp_establishment_id,
    erp_contact_id: document.erp_contact_id,
    erp_price_group_id: document.erp_price_group_id,
    erp_operation_nature_id: operationNatureId,
    currency: 'BRL',
    draft_revision: document.draft_revision ?? undefined,
    quote_no: document.quote_no ?? undefined,
    order_no: document.order_no ?? undefined,
    invoice_no: document.invoice_no ?? undefined,
    lines: document.lines.map((line) => {
      const snapshot = record(line.product_snapshot)
      const taxRate = id(record(snapshot.tax_rate).id)
      const discountRule = id(record(snapshot.discount_rule).id)
      return {
        id: line.id,
        erp_product_variant_id: line.erp_product_variant_id,
        erp_unit_id: line.erp_unit_id,
        description: snapshotDescription(snapshot, line),
        quantity: line.quantity,
        unit_price: line.unit_price,
        discount_amount: hasHeaderDiscount ? '0.0000' : line.discount_amount ?? '0.0000',
        tax_amount: hasHeaderTax ? '0.0000' : line.tax_amount ?? '0.0000',
        unit_conversion_factor: line.unit_conversion_factor,
        erp_tax_rate_id: taxRate,
        erp_discount_rule_id: hasHeaderDiscount ? null : discountRule
      }
    })
  }
  const context = erpSavedTaxContext(document)
  if (context) draft.tax_context = context
  if (Object.hasOwn(document, 'fiscal_model')) draft.fiscal_model = document.fiscal_model ?? null
  if (headerDiscount.mode === 'manual') {
    const kind = discountInput.discount_type === 'percentage' ? 'percentage' : 'fixed'
    const amount = discountInput.discount_amount
    if (typeof amount === 'string') draft.discount = { discount_type: kind, discount_amount: amount }
  } else if (headerDiscount.mode === 'rule' && headerRuleId) {
    draft.erp_discount_rule_id = headerRuleId
  }
  if (hasHeaderTax) draft.tax_rate_percent = headerTax.rate_percent as string
  return draft
}

export function erpSalesActionAllowed(grants: readonly string[] | undefined, role: string | undefined, action: string): boolean {
  if (!grants?.length) return false
  if (!readActions.has(action) && !writeActions.has(action)) return false
  const write = writeActions.has(action)
  if (write && role === 'viewer') return false
  if (exactOverrideActions.has(action)) return grants.some(grant => grant === '*' || grant === action)
  const separator = action.lastIndexOf('.')
  if (separator < 1) return false
  const family = action.slice(0, separator)
  const scope = write ? 'write' : 'read'
  return grants.some(grant => grant === '*' || grant === action || grant === `${family}.*` || grant === `${family}.${scope}` || grant === (write ? 'erp.write' : 'erp.read'))
}
