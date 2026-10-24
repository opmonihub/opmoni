export type ErpFiscalModel = '55' | '65' | '57' | '58'
export type ErpFiscalEnvironment = 'homologation' | 'production'
export type ErpFiscalStatus = 'draft' | 'queued' | 'pending' | 'submitting' | 'indeterminate' | 'authorized' | 'rejected' | 'contingency_local' | 'attention_required' | 'artifact_pending' | 'closed'

export interface ErpFiscalAddress {
  street: string
  number: string
  district: string
  city_code: string
  city_name: string
  zip_code: string
  complement?: string
}

export interface ErpFiscalIssuer {
  tax_regime: number | null
  state_registration: string
  uf: string
  address: ErpFiscalAddress
}

export interface ErpFiscalReadiness {
  status: 'locally_configured' | 'blocked'
  blockers: string[]
}

export interface ErpFiscalModelConfiguration {
  series: number | null
  next_number: number | null
  initial_next_number: number | null
  operation_nature_id: number | null
  transport_prerequisites: string[]
  readiness: ErpFiscalReadiness
  nfce_qr?: { version?: number, mode?: 'online' | 'offline', consultation_url?: string }
}

export interface ErpFiscalConfiguration {
  establishment: { id: number, name: string, cnpj: string | null, status: string }
  revision_id: number | null
  revision: number | null
  environment: ErpFiscalEnvironment | null
  issuer: ErpFiscalIssuer | null
  models: Partial<Record<ErpFiscalModel, ErpFiscalModelConfiguration>>
  release_gates: { provider: string, production_transmission_enabled: boolean, production_blocker: string | null }
}

export interface ErpFiscalCertificate {
  id: number
  label: string
  cnpj: string | null
  erp_establishment_id: number
  certificate_fingerprint: string | null
  revision: number
  revision_id: number | null
  status: string
  valid_from: string | null
  valid_until: string | null
}

export interface ErpFiscalAttempt {
  id: number
  action: string
  status: 'accepted' | 'pending' | 'submitting' | 'indeterminate' | 'rejected' | 'attention_required' | 'artifact_pending' | 'superseded' | 'blocked_authority'
  safe_result: Record<string, unknown> | null
  model?: ErpFiscalModel
  series?: number
  number?: number
  idempotency_key?: string | null
}

export interface ErpFiscalDocument {
  id: number
  erp_establishment_id?: number
  erp_fiscal_certificate_id?: number
  erp_sale_id?: number | null
  model: ErpFiscalModel
  environment: ErpFiscalEnvironment
  series?: number
  number?: number
  access_key?: string | null
  status: ErpFiscalStatus
  idempotency_key?: string
  certificate_revision?: number
  artifact_ready: boolean
  claims_provider_authorization: boolean
  contingency?: Record<string, unknown> | null
  authorized_at?: string | null
  attempt?: ErpFiscalAttempt | null
  attempts?: ErpFiscalAttempt[]
  supported_events?: string[]
  legacy_attention_required?: boolean
}

export interface ErpFiscalEvent {
  id: number
  erp_fiscal_document_id: number
  event_type: string
  status: string
  idempotency_key: string
  justification?: string | null
  protocol?: string | null
  attempt?: Pick<ErpFiscalAttempt, 'id' | 'action' | 'status' | 'safe_result'> | null
}

export interface ErpFiscalInvalidation {
  id: number
  erp_establishment_id: number
  model: '55' | '65'
  year: number
  environment: ErpFiscalEnvironment
  series: number
  number_from: number
  number_to: number
  status: string
  idempotency_key: string
  justification: string
  protocol?: string | null
}

export interface ErpFiscalSale {
  id: number
  status: string
  erp_establishment_id: number
  fiscal_model?: '55' | '65' | null
  erp_sale_id?: number
  commercial_snapshot?: {
    version?: string
    fiscal_context?: Record<string, unknown> | null
    accepted?: unknown
    party?: Record<string, unknown> | null
    totals?: Record<string, string> | null
    lines?: ErpFiscalSaleLine[]
  } | null
  lines?: ErpFiscalSaleLine[]
}

export interface ErpFiscalSaleLine {
  id: number
  source_line_id?: number
  quantity?: string
  subtotal_amount?: string
  gross_amount?: string
  unit_price?: string
  erp_product_variant_id?: number
  product_snapshot?: {
    product?: { name?: string, sku?: string, ncm?: string, origin_code?: string, icms_cst?: string, pis_cst?: string, cofins_cst?: string }
    variant?: { sku?: string }
    unit?: { short_name?: string }
    operation_nature?: { cfop?: string }
    amounts?: { quantity?: string, unit_price?: string, gross_amount?: string }
    [key: string]: unknown
  }
  source_snapshot?: { line?: ErpFiscalSaleLine, [key: string]: unknown }
}

export interface ErpFiscalDocumentSource {
  identity: Record<string, string | number | boolean>
  issuer?: Record<string, unknown>
  recipient?: Record<string, unknown>
  totals?: Record<string, string>
  freight?: Record<string, string | number>
  items?: Array<Record<string, unknown>>
  payment?: Array<Record<string, string | number>>
  references?: Array<Record<string, unknown>>
  transport_service?: Record<string, unknown>
  transport_manifest?: Record<string, unknown>
  nfce_qr?: Record<string, unknown>
}

export interface ErpFiscalRecoveryRecord {
  version: 1
  actorId: number
  companyId: number
  action: string
  path: string
  idempotencyKey: string
  body: Record<string, unknown>
  resourceId: number | null
  recoveryMode: 'replay' | 'read'
  savedAt: string
}

export interface ErpFiscalOperationForm {
  erp_establishment_id: number
  model: ErpFiscalModel
  environment: ErpFiscalEnvironment
  series?: number
  number?: number
  idempotency_key: string
  erp_fiscal_certificate_id?: number
  erp_sale_id?: number
  erp_sale_return_id?: number
  source_document_id?: number
  document_kind?: 'posted_sale' | 'return' | 'complement' | 'substitution' | 'transport_service' | 'transport_manifest'
  source: ErpFiscalDocumentSource
}
