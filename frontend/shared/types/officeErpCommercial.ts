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

export interface OfficeErpPortfolioClient {
  id: number
  name: string
  trade_name: string | null
  tax_id: string | null
}

export interface OfficeErpPortfolioClientListResponse {
  data: OfficeErpPortfolioClient[]
  meta: { current_page: number, last_page: number, per_page: 25 | 50 | 100, total: number }
}

export interface OfficeErpCommercialSummary {
  contracted_licences: number
  reserved_licences: number
  invoiced_movement: string
  effective_received: string
  outstanding: string
  overdue: string
  from_on: string
  to_on: string
  due_from: string | null
  due_to: string | null
}

export interface OfficeErpCommercialSummaryResponse {
  data: OfficeErpCommercialSummary
}

export interface OfficeErpOnboardingPreview {
  client: OfficeErpPortfolioClient
  seller: { account_id: number, name: string }
  owner: { full_name: string, email: string }
  offer: {
    sales_plan_id: number
    revision_id: number
    name: string
    description: string | null
    price: string
    currency: 'BRL'
    interval_months: 1 | 3 | 6 | 12
    due_in_days: number
    module_keys: string[]
    published: boolean
  }
  service: { start_mode: string, billing_timezone: string, due_in_days: number }
  capacity: { reserved: number, occupied: number, available: number | null, can_issue: boolean, reason: string | null }
}

export interface OfficeErpInvitationOutcome {
  invitation_id: number
  expires_at: string
  status: 'pending' | 'accepted' | 'revoked' | 'expired'
  url?: string
}
