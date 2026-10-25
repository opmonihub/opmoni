import type { Identity } from '../types/access'

export function tenantMembership(identity: Identity | null) {
  const current = identity?.current_account
  if (!current || current.status !== 'active') return null
  return identity?.accounts.find(account => account.id === current.id && account.status === 'active') ?? null
}

export function tenantPermissions(identity: Identity | null) {
  const role = tenantMembership(identity)?.role ?? null
  const canOperate = role === 'admin' || role === 'operador'
  return {
    role,
    canManageClients: canOperate,
    canManageWork: canOperate,
    canManageDepartments: canOperate,
    canManageMembers: role === 'admin'
  }
}

export function tenantApiPath(path: string) {
  return !/^\/(?:admin|csrf|me|login|logout|register|registration-status|first-access|account\/switch|erp-invitations|erp\/(?:company\/switch|companies|delegations)|serpro\/(?:connection|connectivity))(?:\/|\?|$)/.test(path)
    && !/^\/erp\//.test(path)
}
