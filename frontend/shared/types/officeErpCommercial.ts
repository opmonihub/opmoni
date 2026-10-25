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

export interface OfficeErpOfferRevision {
  id: number
  revision_number: number
  name: string
  description: string | null
  price: string
  currency: 'BRL'
  interval_months: 1 | 3 | 6 | 12
  due_in_days: number
  module_keys: string[]
  published_at: string | null
  published: boolean
}

export interface OfficeErpOffer {
  id: number
  name: string
  description: string | null
  status: 'active' | 'archived'
  archived_at: string | null
  current_revision: OfficeErpOfferRevision | null
}

export interface OfficeErpOfferTerms {
  name: string
  description: string
  price: string
  currency: 'BRL'
  interval_months: 1 | 3 | 6 | 12
  due_in_days: number
  module_keys: string[]
  published: boolean
}

export interface OfficeErpOfferListResponse {
  data: OfficeErpOffer[]
  meta: { current_page: number, last_page: number, per_page: 25 | 50 | 100, total: number }
}
