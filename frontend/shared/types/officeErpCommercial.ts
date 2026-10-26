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
  can_provision: boolean
  can_start_trial: boolean
  can_manage_trial_link: boolean
  capability_reasons: Record<string, string[]>
  capability_key: string
  reasons: Record<string, string[]>
  allowance: OfficeErpAllowance
  usage: { reserved: number, occupied: number }
}

export interface OfficeErpEnrollmentPlan {
  id: number
  slug: string
  name: string
  module_keys: string[]
}

export interface OfficeErpEnrollmentPlansResponse {
  data: OfficeErpEnrollmentPlan[]
  eligibility: {
    can_provision: boolean
    reasons: string[]
    licence_limit: number | null
    used_licences: number
    available_module_keys: string[]
  }
}

export interface OfficeErpNewClientInput {
  person_type: 'company'
  tax_id: string
  status: 'active'
  tax_regime: 'mei' | 'simple_national' | 'presumed_profit' | 'actual_profit' | 'other'
  name?: string
  email?: string
  phone?: string
}

export interface OfficeErpEnrollmentTerms {
  erp_plan_id: number
  price: string
  currency: 'BRL'
  interval_months: 1 | 3 | 6 | 12
  due_in_days: number
  start_on: string
  billing_timezone: string
}

export type OfficeErpEnrollmentInput = OfficeErpEnrollmentTerms & (
  | { client_id: number, new_client?: never }
  | { new_client: OfficeErpNewClientInput, client_id?: never }
)

export interface OfficeErpEnrollmentOutcome {
  client_id: number
  company_id: number
  subscription_id: number
  contract_id: number
  invoice_id: number
  owner_setup_status: 'unclaimed' | 'claimed'
}

export interface OfficeErpOwnerSetupOutcome {
  invitation_id: number
  contract_id: number
  company_id: number
  owner_setup_status: 'unclaimed' | 'claimed'
  expires_at: string
  status: 'pending' | 'accepted' | 'revoked' | 'expired'
  url?: string
}

export interface OfficeErpInvoiceBalance {
  original_total: string
  adjusted_charge: string
  effective_received: string
  outstanding: string
  settlement_status: 'unpaid' | 'partial' | 'settled'
  overdue: boolean
}

export interface OfficeErpInvoiceBalanceEntry {
  invoice_id: number
  balance: OfficeErpInvoiceBalance
}

export interface OfficeErpFailureData {
  current_balances?: OfficeErpInvoiceBalanceEntry[]
}

export interface OfficeErpFailure {
  code: string
  message: string
  errors?: Record<string, string[]>
  /** HTTP status from the transport, kept outside the server's error envelope. */
  http_status?: number
  data?: OfficeErpFailureData
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

export type ErpCompanyTaxRegime = 'mei' | 'simple_national' | 'presumed_profit' | 'actual_profit' | 'other'

export interface TrialRegistrationInput {
  name: string
  email: string
  password?: string
  company_name: string
  cnpj: string
  tax_regime: ErpCompanyTaxRegime
  confirm_existing_association?: boolean
}

export interface TrialRegistrationOutcome {
  client_id: number
  company_id: number
  subscription_id: number
  contract_id: number
  trial_id: number
  status: 'trialing'
  started_at: string
  ends_at: string
}

export interface TrialRegistrationResponse {
  identity: import('./access').Identity
  data: TrialRegistrationOutcome
}

export interface TrialRegistrationAvailability {
  sponsor: { name: string }
  plan: { name: string, description?: string | null, module_keys?: string[] }
  trial_duration_hours: 168
  available: boolean
  reason?: string | null
}

export interface TrialRegistrationAvailabilityResponse {
  data: TrialRegistrationAvailability
}

export interface TrialRegistrationOutcomeResponse {
  data: TrialRegistrationOutcome
}
