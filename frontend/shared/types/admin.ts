import type { OfficeErpAllowance } from './officeErpCommercial'
import type { PlatformAccount as AccessAccount, IssuedAccount } from './access'

export interface Plan {
  id: number
  slug: string
  name: string
  erp_commercial?: OfficeErpAllowance | null
  limits?: { users?: number | null, clients?: number | null, monitorings?: number | null } | null
}
export interface Subscription {
  id: number
  status: 'active' | 'past_due' | 'canceled'
  account?: { id: number, name: string } | null
  plan?: Pick<Plan, 'id' | 'slug' | 'name'> | null
}
export interface ExtendedPlatformAccount extends AccessAccount {
  phone: string | null
  phone_whatsapp: boolean
  state: string | null
  city: string | null
  subscription: Subscription | null
}
export interface PlatformUser {
  id: number
  name: string
  email: string
  is_super_admin: boolean
  account_links?: { role: string, account?: { id: number, name: string } | null }[]
}
/** Exact flat read projection emitted by SupportAccessLogMetadata::sanitize. */
export interface SupportLogMetadata {
  resource?: 'account_certificates' | 'client_dispatch_contacts' | 'client_saved_filters' | 'client_selections' | 'clients' | 'departments' | 'fiscal' | 'members' | 'process_templates' | 'processes' | 'serpro_enablement' | 'serpro_manual_searches' | 'serpro_messages' | 'serpro_monitorings' | 'serpro_obligation_schedules' | 'serpro_sync_runs' | 'tags' | 'tasks' | 'whatsapp_channels' | 'whatsapp_dispatches'
  resource_id?: number
  name?: string
  email?: string
  document?: string | null
  role?: string
  status?: string
  person_type?: string | null
  action?: string
  client_id?: number
  certificate_id?: number | null
  previous_run_id?: number
  count?: number
  clients?: number
  tags?: number
  enabled?: boolean
  power_refresh_queued?: boolean
}
export type AccountIssueResponse = IssuedAccount
export type AccountReissueResponse = Pick<IssuedAccount, 'first_access'>
export interface SupportAccessLog {
  id: number
  action: string
  ip?: string | null
  metadata?: SupportLogMetadata | null
  created_at: string
  super_admin?: { id: number, name: string, email: string } | null
  account?: { id: number, name: string, status: 'active' | 'suspended' } | null
}
export interface AdminSummary {
  totals: { accounts: number, plans: number, subscriptions: number, users: number }
  recent_support_logs: SupportAccessLog[]
}
export interface AccountListResponse {
  data: ExtendedPlatformAccount[]
  meta: { page: number, last_page: number, per_page: number, total: number }
}
export interface ResourceListResponse<T> {
  data: T[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}
export interface DetailResponse<T> { data: T }
/** Separate settings-backed commercial response, never an issuance response. */
export interface AccountCommercialDetail {
  id: number
  name: string
  status: 'active' | 'suspended'
  platform_billing_contact?: { phone?: string | null, phone_whatsapp?: boolean, state?: string | null, city?: string | null } | null
  subscription?: Subscription | null
}
