import type { Identity } from '../types/access'

export function erpCompanySelection(identity: Identity | null) {
  const current = identity?.current_company
  if (!current || current.status !== 'active')
    return null
  return identity?.companies.find(company => company.id === current.id && company.status === 'active') ?? null
}

export function erpOperatingApiPath(path: string) {
  return /^\/erp\//.test(path)
    && !/^\/erp\/(?:company\/switch|companies|delegations)(?:\/|\?|$)/.test(path)
}
