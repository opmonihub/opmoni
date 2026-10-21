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
