export type AccountRole = 'admin' | 'operador' | 'user'
export interface Membership {
  id: number
  name: string
  status: 'active' | 'suspended'
  role: AccountRole
}
export type CompanyAccessKind = 'direct' | 'delegated'
export interface CompanyMembership {
  id: number
  name: string
  status: 'active' | 'suspended'
  access_kind: CompanyAccessKind
  role: string
  permissions: string[]
  delegated_office_id: number | null
}
export interface Identity {
  id: number
  name: string
  email: string
  is_super_admin: boolean
  accounts: Membership[]
  current_account: Membership | null
  companies: CompanyMembership[]
  current_company: CompanyMembership | null
}
export interface PlatformAccount {
  id: number
  name: string
  status: 'active' | 'suspended'
  members_count: number
  pending_owner: {
    name: string
    email: string
    expires_at: string
  } | null
}
export interface AccountPage {
  data: PlatformAccount[]
  meta: {
    page: number
    per_page: number
    total: number
  }
}
export interface IssuedAccount {
  data: PlatformAccount
  first_access: {
    url: string
    expires_at: string
  }
}
