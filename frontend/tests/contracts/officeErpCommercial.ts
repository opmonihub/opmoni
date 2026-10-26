import type {
  OfficeErpCommandResult,
  OfficeErpFailure,
  OfficeErpInvoiceBalanceEntry
} from '../../shared/types/officeErpCommercial'
import type { OfficeErpCapabilities } from '../../shared/types/officeErpCommercial'

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

const capabilities: OfficeErpCapabilities = {
  can_read: true,
  can_maintain: true,
  can_configure: true,
  can_sell_new: false,
  can_provision: true,
  can_start_trial: true,
  can_manage_trial_link: true,
  capability_reasons: {
    can_configure: [],
    can_sell_new: ['commercial_resale_disabled'],
    can_provision: [],
    can_start_trial: ['trial_registration_disabled'],
    can_manage_trial_link: []
  },
  capability_key: 'a'.repeat(64),
  reasons: {},
  allowance: { enabled: false, licence_limit: 3, module_keys: ['purchases'] },
  usage: { reserved: 0, occupied: 0 }
}

void capabilities
