export type ClientPersonType = 'company' | 'individual'
export type ClientStatus = 'active' | 'inactive'
export type TaxRegime = 'mei' | 'simple_national' | 'presumed_profit' | 'actual_profit' | 'other' | 'not_applicable'
export type DeadlineStatus = 'missing' | 'valid' | 'expiring' | 'expired'
export type ClientTagColor = 'neutral' | 'primary' | 'success' | 'info' | 'warning' | 'error'

export interface ClientTag {
  id: number
  name: string
  color: ClientTagColor
}

export interface ClientSavedFilter {
  id: number
  name: string
  q: string | null
  filters: {
    columnId: string
    operator: string
    values: Array<string | number>
  }[]
}

export interface ClientCertificate {
  id: number
  subject: string
  serial_number: string
  valid_from: string
  valid_until: string
  original_filename: string
  status: DeadlineStatus
}

/**
 * Uma família de serviço que compõe a procuração e-CAC do cliente, como o
 * provedor a confirmou. `state` é o que `serpro_client_authorizations` gravou
 * (`established`, `rejected`, `expired`, `pending`); `expires_on` é a
 * `dtexpiracao` da outorga, e `null` é uma outorga sem validade — não "sem
 * data de hoje".
 */
export interface ClientPowerOfAttorneyFamily {
  family: string
  state: string
  expires_on: string | null
}

/**
 * A procuração e-CAC derivada das famílias autorizadas.
 *
 * Não há `id`, `starts_at`, `notes` nem nada digitado pelo Membro: a tabela da
 * procuração manual não existe mais, e o que a tela mostra é a menor validade
 * entre as famílias que a procuração exige. `expires_on` é a menor validade em
 * vigor entre as famílias consideradas, e `null` quando nenhuma família foi
 * confirmada — o `status` continua em `ecac_power_of_attorney_status` para o
 * filtro, o badge e a ordenação lerem o mesmo estado.
 */
export interface ClientEcacPowerOfAttorney {
  status: DeadlineStatus
  expires_on: string | null
  families: ClientPowerOfAttorneyFamily[]
}

export interface ClientSheet {
  id: number
  person_type: ClientPersonType | null
  tax_id: string | null
  name: string
  status: ClientStatus
  tax_regime: TaxRegime | null
  certificate: { valid_until: string } | null
  certificate_status: DeadlineStatus
  ecac_power_of_attorney: { expires_on: string | null } | null
  ecac_power_of_attorney_status: DeadlineStatus
  tags?: ClientTag[]
}

export interface Client {
  id: number
  person_type: ClientPersonType | null
  tax_id: string | null
  name: string
  trade_name: string | null
  status: ClientStatus
  tax_regime: TaxRegime | null
  registration_status: string | null
  registration_status_date: string | null
  opened_at: string | null
  company_size: string | null
  legal_nature: string | null
  primary_activity: { code: string | null, description: string | null }
  address: {
    street_type: string | null
    street: string | null
    number: string | null
    complement: string | null
    district: string | null
    postal_code: string | null
    city: string | null
    state: string | null
  }
  email: string | null
  phone: string | null
  certificate: ClientCertificate | null
  certificate_status: DeadlineStatus
  ecac_power_of_attorney: ClientEcacPowerOfAttorney | null
  ecac_power_of_attorney_status: DeadlineStatus
  tags?: ClientTag[]
  source_updated_at: string | null
  looked_up_at: string | null
  created_at: string
  updated_at: string
}

export interface CnpjPreview {
  tax_id: string
  name: string
  trade_name: string | null
  registration_status: string | null
  registration_status_date: string | null
  opened_at: string | null
  company_size: string | null
  legal_nature: string | null
  primary_activity_code: string | null
  primary_activity_description: string | null
  street_type: string | null
  street: string | null
  address_number: string | null
  address_complement: string | null
  district: string | null
  postal_code: string | null
  city: string | null
  state: string | null
  email: string | null
  phone: string | null
  mei: boolean
  simple_national: boolean
  source_updated_at: string | null
  looked_up_at: string
}

export interface CnpjRefreshPreview {
  current: CnpjPreview
  incoming: CnpjPreview
  changes: Record<string, { from: unknown, to: unknown }>
}

export type ClientPortfolioDocument = 'certificate' | 'poa'

export type ClientPortfolioView
  = | 'certificate_missing'
    | 'certificate_valid'
    | 'certificate_expiring'
    | 'certificate_expired'
    | 'poa_missing'
    | 'poa_valid'
    | 'poa_expiring'
    | 'poa_expired'

export interface ClientPortfolioSummary {
  total: number
  active: number
  inactive: number
  certificate: Record<DeadlineStatus, number>
  poa: Record<DeadlineStatus, number>
}

export interface PortfolioBucket {
  key: string
  count: number
  label?: string | null
}

export interface PortfolioAttentionItem {
  id: number
  name: string
  tax_id: string | null
  status: Extract<DeadlineStatus, 'expired' | 'expiring'>
  expires_at: string | null
}

export interface ClientPortfolioAnalytics {
  by_state: PortfolioBucket[]
  by_region: PortfolioBucket[]
  by_city: PortfolioBucket[]
  by_tax_regime: PortfolioBucket[]
  by_legal_nature: PortfolioBucket[]
  by_activity: Array<PortfolioBucket & { label: string | null }>
  growth_by_month: PortfolioBucket[]
  attention: {
    certificate: PortfolioAttentionItem[]
    poa: PortfolioAttentionItem[]
  }
}

export interface ClientListParams {
  page?: number
  per_page?: number
  all?: 1
  sheet?: 1
  q?: string
  status?: ClientStatus | ClientStatus[]
  tax_regime?: TaxRegime | TaxRegime[]
  deadline_status?: DeadlineStatus | DeadlineStatus[]
  certificate_status?: DeadlineStatus | DeadlineStatus[]
  poa_status?: DeadlineStatus | DeadlineStatus[]
  tag_id?: number | number[]
  view?: ClientPortfolioView
  sort: 'name' | 'tax_id' | 'status' | 'tax_regime' | 'created_at' | 'certificate' | 'poa'
  direction: 'asc' | 'desc'
}

export interface ClientWritePayload {
  person_type: ClientPersonType
  tax_id: string
  name?: string
  status: ClientStatus
  tax_regime: TaxRegime
  email?: string
  phone?: string
  street_type?: string
  street?: string
  address_number?: string
  address_complement?: string
  district?: string
  postal_code?: string
  city?: string
  state?: string
}

export type ClientUpdatePayload = Partial<Omit<ClientWritePayload, 'person_type' | 'tax_id'>>

/**
 * O `meta.capture` da resposta do upload do A1 (`POST clients/{id}/certificate`).
 *
 * Diz o que o pedido fez com a captura imediata, e nunca o que o lote vai
 * trazer: `queued` enfileirou um job por fonte habilitada, `blocked` não
 * enfileirou nada porque a janela do fisco ainda corre (`blocked_until` é o
 * fim dela), `not_capturable` não enfileirou porque o certificado novo não
 * serve para consulta (`reason` é o código, sem senha nem conteúdo do
 * certificado).
 */
export type ClientCertificateCaptureStatus = 'queued' | 'blocked' | 'not_capturable'

export interface ClientCertificateCapture {
  status: ClientCertificateCaptureStatus
  sources: string[]
  blocked_until: string | null
  reason: string | null
}

export interface ClientCertificateUpload {
  data: Client
  meta?: { capture?: ClientCertificateCapture }
}

export interface PaginatedResponse<T> {
  data: T[]
  links: Record<string, string | null>
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

export interface ClientSelectionSnapshot {
  id: string
  count: number
  ids: number[]
}

export interface ClientBulkDeletion {
  id: string
  status: 'queued' | 'completed' | 'failed'
  total: number
  deleted?: number
  skipped?: number
  failed?: number
}

export interface ClientTagAssignment {
  ids?: number[]
  selection_id?: string
  excluded_ids?: number[]
  tag_ids: number[]
  action: 'attach' | 'detach'
}
