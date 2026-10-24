export type ErpNativeDefaultStatus = 'available' | 'unset' | 'unresolved' | 'unavailable'

export type ErpNativeReferenceDefault = {
  status: ErpNativeDefaultStatus
  id: number | null
  label: string | null
}

export type ErpNativeDefaults = {
  revision: number
  preset_version: string | null
  currency: { status: ErpNativeDefaultStatus, code: 'BRL' | null }
  unit: ErpNativeReferenceDefault
  customer: ErpNativeReferenceDefault
}
