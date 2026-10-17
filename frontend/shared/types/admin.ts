import type { PlatformAccount as AccessAccount } from './access'

export interface Plan {
  id: number
  slug: string
  name: string
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
export interface SupportAccessLog {
  id: number
  action: string
  ip?: string | null
  metadata?: { resource?: string, resource_id?: number } | null
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
