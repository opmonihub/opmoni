import type { CompanyMembership, Identity } from '../types/access'

/** Frozen consumer fixtures for ERP foundation wire contracts (no secrets). */
export const erpErrorCodes = {
  companyContextChanged: 'company_context_changed',
  validationError: 'validation_error',
  membershipRequired: 'membership_required',
  erpOperatingDisabled: 'erp_operating_disabled',
  unauthenticated: 'unauthenticated'
} as const satisfies Record<string, string>

export const sampleDirectCompanyMembership = {
  id: 42,
  name: 'Empresa Alpha',
  status: 'active',
  access_kind: 'direct',
  role: 'owner',
  permissions: ['erp.read', 'erp.settings.manage'],
  delegated_office_id: null
} satisfies CompanyMembership

export const sampleDelegatedCompanyMembership = {
  id: 43,
  name: 'Empresa Beta',
  status: 'active',
  access_kind: 'delegated',
  role: 'delegated',
  permissions: ['erp.read'],
  delegated_office_id: 7
} satisfies CompanyMembership

export const sampleCompanyOnlyIdentity = {
  id: 1,
  name: 'Owner',
  email: 'owner@example.test',
  is_super_admin: false,
  accounts: [],
  current_account: null,
  companies: [sampleDirectCompanyMembership],
  current_company: sampleDirectCompanyMembership
} satisfies Identity

export const sampleOfficeFirstIdentity = {
  id: 2,
  name: 'Operator',
  email: 'operator@example.test',
  is_super_admin: false,
  accounts: [{ id: 7, name: 'Escritório', status: 'active', role: 'admin' }],
  current_account: { id: 7, name: 'Escritório', status: 'active', role: 'admin' },
  companies: [sampleDelegatedCompanyMembership],
  current_company: null
} satisfies Identity

export const erpRoutePrefixes = {
  operating: '/erp/establishments',
  switch: '/erp/company/switch',
  settings: '/erp/company/settings',
  delegations: '/erp/delegations'
} as const satisfies Record<string, string>
