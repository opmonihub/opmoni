import type {
  OfficeErpCommandResult,
  OfficeErpFailure,
  OfficeErpInvoiceBalanceEntry
} from '../../shared/types/officeErpCommercial'

const failure: OfficeErpFailure = {
  code: 'receipt_balance_conflict',
  message: 'O saldo atual da fatura não comporta esta alocação.',
  http_status: 409,
  data: {
    current_balances: [{
      invoice_id: 81,
      balance: {
        original_total: '100.00',
        adjusted_charge: '100.00',
        effective_received: '60.00',
        outstanding: '40.00',
        settlement_status: 'partial',
        overdue: false
      }
    }]
  }
}

const failedCommand: OfficeErpCommandResult<never> = { status: 'failed', failure }

const legacyFlatBalance = {
  original_total: '100.00',
  adjusted_charge: '100.00',
  effective_received: '60.00',
  outstanding: '40.00',
  settlement_status: 'partial',
  overdue: false
}

// @ts-expect-error server rows wrap the reusable balance with invoice_id and balance
const legacyFlatEntry: OfficeErpInvoiceBalanceEntry = legacyFlatBalance

void failedCommand
void legacyFlatEntry
