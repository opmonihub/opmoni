export type ErpMoney = string

export interface ErpFinanceAttribution {
  posted_by_user_id: number | null
  actor: { id: number, name: string } | null
  posted_at: string | null
}

export interface ErpFinancialAccount {
  id: number
  name: string
  erp_establishment_id: number | null
  account_type: string
  currency: string
  status: 'active' | 'archived'
}

export interface ErpObligation extends ErpFinanceAttribution {
  id: number
  erp_establishment_id: number | null
  erp_financial_account_id: number | null
  amount: ErpMoney
  open_amount: ErpMoney
  allocated_amount: ErpMoney
  currency: string
  direction: 'receivable' | 'payable'
  status: 'open' | 'settled' | 'reversed'
  category: string
  due_on: string | null
  source_type: string
  source_id: number
  source_operation_key: string
  return_of_id: number | null
  return_credit_ids: number[]
}

export interface ErpPayment extends ErpFinanceAttribution {
  id: number
  erp_establishment_id: number | null
  erp_financial_account_id: number | null
  erp_cash_session_id: number | null
  amount: ErpMoney
  allocated_amount: ErpMoney
  available_amount: ErpMoney
  currency: string
  direction: 'in' | 'out' | null
  status: string
  effective_status: 'posted' | 'reversed' | 'compensation'
  method: string
  reason: string | null
  reversal_of_id: number | null
  reversal_id: number | null
  allocation_ids: number[]
  cash_movement_ids: number[]
  needs_reconciliation: boolean
  correction: {
    permission: string
    workflow: 'reconciliation' | 'payment_reversal' | 'unavailable'
    requires_cash_target: boolean
  }
}

export interface ErpPaymentAllocation extends ErpFinanceAttribution {
  id: number
  erp_payment_id: number
  erp_obligation_id: number
  amount: ErpMoney
  reversal_of_id: number | null
  remaining_reversible: ErpMoney
  reason: string | null
}

export interface ErpFinanceTotals {
  currency: string
  amount: ErpMoney
  open_amount: ErpMoney
  available_amount: ErpMoney
  receivable_open: ErpMoney
  payable_open: ErpMoney
  net_exposure: ErpMoney
  overdue_open: ErpMoney
}

export interface ErpFinancePage<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    totals: ErpFinanceTotals[]
  }
}

export interface ErpFinanceReceipt {
  version: 1
  reference: string
  company: { id: number, name: string }
  id: number
  amount: ErpMoney
  currency: string
  direction: 'in' | 'out' | null
  effective_status: string
  method: string
  posted_at: string | null
  actor: { id: number, name: string } | null
}

export type ErpCommercialKind = 'quote' | 'order' | 'sale'
export type ErpCommercialStatus = 'draft' | 'confirmed' | 'converted' | 'posted'

export interface ErpCommercialLine {
  id?: number
  erp_product_variant_id: number | null
  erp_unit_id: number | null
  description?: string
  quantity: ErpMoney
  unit_price?: ErpMoney
  discount_amount?: ErpMoney
  tax_amount?: ErpMoney
  unit_conversion_factor?: string
  erp_tax_rate_id?: number | null
  erp_discount_rule_id?: number | null
  line_total?: ErpMoney
  product_snapshot?: Record<string, unknown> | null
  [key: string]: unknown
}

export interface ErpCommercialDraftPayload {
  erp_establishment_id: number | null
  erp_contact_id?: number | null
  erp_price_group_id?: number | null
  erp_operation_nature_id?: number | null
  currency: 'BRL'
  quote_no?: string
  order_no?: string
  invoice_no?: string
  sale_no?: string
  discount?: { discount_type: 'fixed' | 'percentage', discount_amount: ErpMoney }
  erp_discount_rule_id?: number
  tax_rate_percent?: ErpMoney
  draft_revision?: number
  lines: ErpCommercialLine[]
}

export interface ErpSaleFinanceProjection {
  currency: string
  original_obligation_id: number | null
  gross_original_due: ErpMoney | null
  original_principal?: ErpMoney | null
  opposite_return_credit: ErpMoney | null
  open_return_credit: ErpMoney | null
  net_exposure: ErpMoney | null
  needs_reconciliation: boolean
  reconciliation_reason?: string | null
  automatic_setoff: false
  automatic_refund: false
  payment_ids?: number[]
  return_credits?: Array<{ id: number, source_type: string, source_id: number, amount: ErpMoney, open_amount: ErpMoney, due_on: string | null }>
  original_due_on?: string | null
}

export interface ErpCommercialDocument {
  id: number
  erp_establishment_id: number
  erp_contact_id: number | null
  erp_price_group_id: number | null
  erp_quote_id?: number | null
  erp_order_id?: number | null
  quote_no?: string | null
  order_no?: string | null
  invoice_no?: string | null
  status: ErpCommercialStatus
  fiscal_status?: string | null
  currency: 'BRL'
  draft_revision: number | null
  subtotal_amount: ErpMoney
  discount_amount: ErpMoney
  tax_amount: ErpMoney
  total_amount: ErpMoney
  lines: ErpCommercialLine[]
  stock_reserved: false
  needs_reconciliation: boolean
  returnability?: { needs_reconciliation: boolean, reconciliation_reason?: string | null }
  returnable_lines?: Array<{
    source_line_id: number
    quantity: ErpMoney
    returned_quantity: ErpMoney | null
    remaining_quantity: ErpMoney | null
    unit_conversion_factor: string
    line_total: ErpMoney
    physical_eligibility: string
  }>
  finance?: ErpSaleFinanceProjection
  [key: string]: unknown
}

export interface ErpSaleReturn {
  id: number
  erp_sale_id: number
  erp_establishment_id: number
  status: 'draft' | 'posted'
  currency: 'BRL'
  reason: string
  total_amount: ErpMoney
  lines: Array<{ source_line_id: number, quantity: ErpMoney, [key: string]: unknown }>
  credit_id?: number | null
  financial_disposition?: string | null
  [key: string]: unknown
}

export interface ErpSalesPage<T> {
  data: T[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

export interface ErpSalesEstablishment { id: number, name: string, status: string }
export interface ErpSalesContact { id: number, name: string, trade_name?: string | null, is_customer?: boolean, status: string }
export interface ErpSalesUnit { id: number, name: string, symbol?: string | null, status: string }
export interface ErpSalesVariant { id: number, name: string, sku?: string | null, status: string, product?: { id: number, name: string, kind?: string, track_stock?: boolean } }

export interface ErpCashRegister {
  id: number
  name: string
  erp_establishment_id: number
  status: 'active' | 'archived'
}

export interface ErpCashLedgerTotals {
  movements_in: ErpMoney | null
  movements_out: ErpMoney | null
  movements_net: ErpMoney | null
  expected_balance: ErpMoney | null
  needs_reconciliation: boolean
}

export interface ErpCashSession extends ErpCashLedgerTotals {
  id: number
  erp_cash_register_id: number
  erp_establishment_id: number
  operator_user_id: number
  operator: { id: number, name: string } | null
  currency: string | null
  status: 'open' | 'closed'
  opening_amount: ErpMoney | null
  expected_closing_amount: ErpMoney | null
  declared_closing_amount: ErpMoney | null
  closing_difference: ErpMoney | null
  opened_at: string | null
  closed_at: string | null
  closed_by_user_id: number | null
  can_operate: boolean
}

export interface ErpCashMovement {
  id: number
  erp_cash_session_id: number
  erp_payment_id: number | null
  direction: 'in' | 'out'
  kind: string
  amount: ErpMoney
  reason: string | null
  posted_by_user_id: number | null
  actor: { id: number, name: string } | null
  posted_at: string | null
}

export interface ErpCashPage<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    totals: ErpFinanceTotals[]
  }
  totals?: ErpCashLedgerTotals
}

export interface ErpStockPage<T> {
  data: T[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

export interface ErpStockReconciliation {
  observed_quantity?: string
  signed_history_quantity?: string
  watermark_movement_id?: number
  provenance?: string
  needs_reconciliation?: boolean
}

export interface ErpStockBalance {
  id: number
  erp_establishment_id: number
  erp_product_variant_id: number
  quantity_on_hand: string
  ledger_initialized_at: string | null
  reconciliation: ErpStockReconciliation | null
  average_cost: string | null
}

export interface ErpStockCostBreakdown {
  currency: string | null
  total_cost?: ErpMoney | null
  known_cost: ErpMoney | null
  unvalued_quantity: string
  cost_status?: string
}

export interface ErpStockMovement {
  id: number
  erp_establishment_id: number
  erp_product_variant_id: number
  direction: 'in' | 'out'
  quantity: string
  unit_cost: ErpMoney | null
  source_type: string
  source_id: number
  source_operation_key: string | null
  posted_by_user_id: number | null
  posted_at: string | null
  source_line_id: number | null
  reversal_of_id: number | null
  valuation_method: 'fifo' | 'lifo' | null
  currency: string | null
  source_currency: string | null
  cost_breakdown: ErpStockCostBreakdown[] | null
  total_cost: ErpMoney | null
  known_cost: ErpMoney | null
  unvalued_quantity: string | null
  cost_status: string | null
  reason: string | null
}

export interface ErpStockAllocation {
  id: number
  erp_company_id: number
  erp_establishment_id: number
  erp_product_variant_id: number
  erp_stock_movement_id: number | null
  opening_balance_id: number | null
  erp_stock_lot_id: number | null
  original_allocation_id: number | null
  ordinal: number
  quantity: string
  cost: ErpMoney | null
  returned_quantity: string
  returned_cost: ErpMoney
  settled_quantity: string
  currency: string | null
  kind: string
  cost_status: string
  issued_at: string
  created_at: string | null
  updated_at: string | null
}

export interface ErpStockCostSettlement {
  id: number
  erp_company_id: number
  erp_establishment_id: number
  erp_product_variant_id: number
  erp_stock_allocation_id: number
  erp_stock_lot_id: number
  erp_stock_movement_id: number
  reversal_of_id: number | null
  quantity: string
  cost: ErpMoney | null
  returned_quantity: string
  returned_cost: ErpMoney
  currency: string | null
  posted_at: string
  created_at: string | null
  updated_at: string | null
}

export interface ErpStockLot {
  id: number
  erp_company_id: number
  erp_establishment_id: number
  erp_product_variant_id: number
  erp_stock_movement_id: number | null
  opening_balance_id: number | null
  root_lot_id: number | null
  parent_lot_id: number | null
  parent_allocation_id: number | null
  parent_settlement_id: number | null
  quantity: string
  available_quantity: string
  total_cost: ErpMoney | null
  currency: string | null
  consumed_quantity: string
  consumed_cost: ErpMoney | null
  cost_status: string
  received_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface ErpStockReversalPolicy {
  workflow: 'standalone' | 'transfer' | 'sale_return' | 'purchase_return' | 'reconciliation' | string
  remaining_quantity: string
  reason_required: boolean
  source_id: number
  permission: string | null
}

export interface ErpStockMovementDetail extends ErpStockMovement {
  allocations: ErpStockAllocation[]
  reversal: ErpStockReversalPolicy
  reversals: ErpStockMovement[]
  lots: ErpStockLot[]
  settlements: ErpStockCostSettlement[]
}

export interface ErpStockAdjustment {
  id: number
  erp_establishment_id: number
  erp_product_variant_id: number
  quantity_delta: string
  movement_ids: number[]
  reason: string
  status: string
  source_operation_key: string | null
  posted_by_user_id: number | null
  posted_at: string | null
}

export interface ErpStockTransfer {
  id: number
  movement_ids: number[]
  from_establishment_id: number
  to_establishment_id: number
  erp_product_variant_id: number
  quantity: string
  status: string
  source_operation_key: string | null
  posted_by_user_id: number | null
  posted_at: string | null
}

export interface ErpStockTransferDetail extends ErpStockTransfer {
  returned_quantity: string
  movements: ErpStockMovement[]
  reversals: ErpStockMovement[]
}

export interface ErpStockEstablishment {
  id: number
  name: string
  status: 'active' | 'archived'
}

export interface ErpStockVariant {
  id: number
  name: string
  sku: string | null
  status: 'active' | 'archived'
  product: { id: number, name: string, kind: string, track_stock: boolean }
}

export interface ErpPurchaseLine {
  id: number
  erp_product_variant_id: number | null
  erp_unit_id: number | null
  description: string
  quantity: string
  unit_cost: string
  unit_conversion_factor: string
  line_total: string
  discount_amount?: string | null
  tax_amount?: string | null
  product_snapshot?: {
    amounts?: {
      quantity?: string
      unit_cost?: string
      discount_amount?: string
      tax_amount?: string
      line_total?: string
    }
    tax_rate?: { id?: number | null } | null
    operation_nature?: { id?: number | null } | null
    [key: string]: unknown
  } | null
}

export interface ErpPurchaseDraftLine {
  id?: number
  erp_product_variant_id: number | null
  erp_unit_id: number | null
  description: string
  quantity: string
  unit_cost: string
  unit_conversion_factor: string
  discount_amount: string
  tax_amount?: string
  erp_tax_rate_id?: number | null
  erp_operation_nature_id?: number | null
  accepted_amounts?: {
    quantity: string
    unit_cost: string
    discount_amount: string
    tax_amount: string
    line_total: string
  }
}

export interface ErpPurchaseDraftPayload {
  erp_establishment_id: number | null
  erp_contact_id: number | null
  currency: string
  document_no: string
  draft_revision?: number
  expected_total?: ErpMoney
  lines: ErpPurchaseDraftLine[]
}

export interface ErpPurchaseReturnableLine {
  source_line_id: number
  quantity: string
  returned_quantity: string | null
  remaining_quantity: string | null
  unit_conversion_factor: string
  line_total: string
  physical_eligibility: string
}

export interface ErpPurchaseReturnCredit {
  id: number
  source_type: string
  source_id: number
  amount: ErpMoney
  open_amount: ErpMoney
  due_on: string | null
}

export interface ErpPurchaseFinanceProjection {
  currency: string
  original_obligation_id: number | null
  gross_original_due: ErpMoney | null
  original_principal?: ErpMoney
  original_due_on?: string | null
  opposite_return_credit: ErpMoney | null
  open_return_credit: ErpMoney | null
  net_exposure: ErpMoney | null
  return_credits?: ErpPurchaseReturnCredit[]
  needs_reconciliation: boolean
  reconciliation_reason: string | null
  automatic_setoff: false
  automatic_refund: false
}

export interface ErpPurchase {
  id: number
  erp_establishment_id: number
  erp_contact_id: number
  document_no: string | null
  status: 'draft' | 'posted'
  total_amount: ErpMoney
  currency: string
  draft_revision: number | null
  supplier_snapshot: Record<string, unknown> | null
  source_access_key: string | null
  source_sha256: string | null
  source_byte_length: number | null
  source_operation_key: string | null
  source_snapshot?: Record<string, unknown> | null
  needs_reconciliation: boolean
  finance?: ErpPurchaseFinanceProjection
  returnability?: { needs_reconciliation: boolean, reconciliation_reason: string | null }
  returnable_lines?: ErpPurchaseReturnableLine[]
  lines: ErpPurchaseLine[]
}

export interface ErpPurchasePage {
  data: ErpPurchase[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

export interface ErpPurchaseSupplier {
  id: number
  name: string
  trade_name: string | null
  tax_id: string | null
  is_supplier: boolean
  status: 'active' | 'archived'
}

export interface ErpPurchaseEstablishment {
  id: number
  name: string
  status: 'active' | 'archived'
}

export interface ErpPurchaseVariant {
  id: number
  name: string
  sku: string | null
  status: 'active' | 'archived'
  product: { id: number, name: string, kind: string, track_stock: boolean, erp_unit_id: number }
}

export interface ErpPurchaseUnit {
  id: number
  actual_name: string
  short_name: string
  allow_decimal: boolean
  conversion_factor: string
  base_unit_id: number | null
  status: 'active' | 'archived'
}

export interface ErpPurchaseXmlLine {
  line: number
  codigo: string
  xProd: string
  qCom: string
  uCom: string
  vUnCom: string
  amounts?: { gross_amount: ErpMoney, discount_amount: ErpMoney, net_amount: ErpMoney, tax_amount: ErpMoney, line_total: ErpMoney }
}

export interface ErpPurchaseXmlMapping {
  line: number
  erp_product_variant_id: number
  erp_unit_id?: number
  unit_conversion_factor: string
}

export interface ErpPurchaseXmlPreview {
  token: string
  chave: string
  emit: { name: string, cpf_cnpj: string }
  dest: { name: string, cpf_cnpj: string }
  det: ErpPurchaseXmlLine[]
  total: Record<string, string | null>
  duplicatas: Array<Record<string, unknown>>
  errors: Array<{ code?: string, message?: string, line?: number }>
  duplicate: boolean
  duplicate_purchase_id: number | null
  expires_at: string
  state: 'pending' | 'blocked' | 'expired' | 'completed'
  result?: ErpPurchase | null
}

export interface ErpPurchaseReturn {
  id: number
  erp_purchase_id: number
  status: 'draft' | 'posted'
  currency: string
  reason: string
  subtotal_amount: ErpMoney
  discount_amount: ErpMoney
  tax_amount: ErpMoney
  total_amount: ErpMoney
  credit_id: number | null
  financial_disposition: 'receivable' | 'zero_consideration' | null
  erp_establishment_id?: number
  commercial_snapshot?: Record<string, unknown> | null
  lines?: Array<{ id: number, source_line_id: number, quantity: string, total_amount: ErpMoney }>
}
