export interface OfficeErpAllowance {
  enabled: boolean
  licence_limit: number | null
  module_keys: string[]
}

export interface ContextToken {
  key: string
  generation: number
}

export interface OfficeErpCapabilities {
  can_read: boolean
  can_maintain: boolean
  can_configure: boolean
  can_sell_new: boolean
  capability_key: string
  reasons: Record<string, string[]>
  allowance: OfficeErpAllowance
  usage: { reserved: number, occupied: number }
}

export interface OfficeErpInvoiceBalance {
  original_total: string
  adjusted_charge: string
  effective_received: string
  outstanding: string
  settlement_status: 'unpaid' | 'partial' | 'settled'
  overdue: boolean
}

export interface OfficeErpFailure {
  code: string
  message: string
  errors?: Record<string, string[]>
  /** HTTP status from the transport, kept outside the server's error envelope. */
  http_status?: number
  data?: { current_balances?: OfficeErpInvoiceBalance[] }
}

export type OfficeErpCommandResult<T>
  = | { status: 'confirmed', data: T }
    | { status: 'unknown', key: string }
    | { status: 'failed', failure: OfficeErpFailure }
    | { status: 'stale' }
