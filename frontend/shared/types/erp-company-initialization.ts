export type ErpCompanyInitializationItem = {
  key: string
  status: 'create' | 'reuse' | 'skip' | 'conflict' | string
  reason: string
  before: unknown
  after: unknown
  effective_changed: boolean
}

export type ErpCompanyInitializationPreview = {
  preview_version: 'setup-v1' | string
  preset_version: string
  revision: number
  sale_numbering_adopted: boolean
  fingerprint: string
  has_conflicts: boolean
  items: ErpCompanyInitializationItem[]
}

export type ErpCompanyInitializationOutcome = Omit<ErpCompanyInitializationPreview, 'fingerprint'> & {
  completed: boolean
}

export type ErpCompanyInitializationOperationLookup = {
  operation: string
  state: string
  result?: ErpCompanyInitializationOutcome
}
