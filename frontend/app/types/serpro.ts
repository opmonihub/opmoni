/**
 * Wire contract for the Integra Contador (SERPRO) integration.
 *
 * Field names are the backend's `snake_case` verbatim. `interface` describes a
 * shape, `type` a union — there is no barrel for domain types, so consumers
 * import this file by module path.
 */

/** What the provider's catalogue actually serves for an obligation (D21). */
export type ObligationCategory = 'direct' | 'derived' | 'unavailable' | 'extinct'

/** The four states that partition the total. `encerrado` sits outside them. */
export type MonitoringCounter = 'em_dia' | 'processando' | 'pendencias' | 'atencao'

/** The five states a row can be in. */
export type MonitoringSituacao = MonitoringCounter | 'encerrado'

/**
 * The causes behind an `atencao` aggregate, declared as their own union so the
 * compiler rejects a cause used where a counter belongs. A cause is never a
 * free-standing state: it refines `atencao` on the row.
 */
export type AttentionReasonCode
  = | 'sem_declaracao'
    | 'sem_procuracao'
    | 'procuracao_invalida'
    | 'contam_debitos'

/** Collection-slip status, derived from synchronized data with no extra call. */
export type MonitoringSlipStatus = 'paid' | 'issued' | 'owed' | 'none'

export type SerproSyncRunState = 'queued' | 'running' | 'completed' | 'partial' | 'failed'

/**
 * `nao_processado` has not happened yet; `ignorado` happened and was skipped.
 * The obligation vocabulary does not apply here.
 */
export type SerproRunItemState
  = | 'sincronizado'
    | 'ignorado'
    | 'falhou'
    | 'indeterminado'
    | 'nao_processado'

export type SerproAuthorizationTermState
  = | 'ausente'
    | 'pendente'
    | 'validado'
    | 'autenticado'
    | 'vencido'
    | 'recusado'

export interface AttentionReason {
  /**
   * The backend's code. Typed as the union widened with `string` on purpose:
   * a cause added server-side must reach this client without a redeploy, and
   * the compiler must not reject the unknown code at the boundary.
   */
  code: AttentionReasonCode | string
  count: number
  /** The backend's own wording — the fallback when this client cannot resolve `code`. */
  label?: string | null
}

export interface MonitoringMessageStub {
  id: number
  assunto: string
  received_at: string
  lida_em: string | null
  /** The moment the office came to know. Starts the legal deadline (D19). */
  ciencia_em: string | null
  prazo_limite: string | null
  unread: boolean
}

export interface MonitoringClient {
  client_id: number
  name: string
  tax_id: string | null
  situacao: MonitoringSituacao
  cause: AttentionReasonCode | null
  due_on: string | null
  /** The power of attorney lapsed: the retained data is out of date. */
  stale: boolean
  power_of_attorney_expires_on: string | null
  /** Values for the obligation's declared `columns`, keyed by column id. */
  fields: Record<string, string | number | null>
  /**
   * The assessment periods behind the guide, from already synchronized data.
   * Read for the collection-slip columns through `latestSlipFor` — no extra
   * provider call. A row with none renders the em dash, which is a claim about
   * the data and not a claim that no guide exists.
   */
  periods: MonitoringAssessmentPeriod[]
  /** Caixas Postais rows are messages; reading one is a legal act (D19). */
  message: MonitoringMessageStub | null
}

export interface MonitoringMessage {
  id: number
  codigo: string | null
  assunto: string
  corpo: string
  lida_em: string | null
  ciencia_em: string | null
  prazo_limite: string | null
}

export interface MonitoringAssessmentPeriod {
  /** `YYYY-MM`. */
  period: string
  /** When the declaration was transmitted; `null` when the period owes. */
  declared_at: string | null
  rectified: boolean
  slip_number: string | null
  slip_issued_at: string | null
  due_on: string | null
  slip_paid: boolean | null
}

/**
 * The synchronization on its own axis: how many clients have been transmitted
 * out of how many were requested.
 *
 * A reading of the synchronization, never a state of any client — it therefore
 * sits beside the counters and never inside them, and `total` stays the sum of
 * the four. `null` until the backend reports the pair: the absence of the
 * reading is not a reading of zero.
 */
export interface MonitoringSyncProgress {
  transmitted: number
  requested: number
}

export interface MonitoringObligationSummary {
  obligation: string
  category: ObligationCategory
  /** The sum of the four. `encerrado` is not part of it. */
  total: number
  em_dia: number
  processando: number
  pendencias: number
  atencao: number
  /** Outside the partition: a closed obligation never inflates an action state. */
  encerrado: number
  /** The synchronization's own axis, beside the counters and outside them. */
  progress: MonitoringSyncProgress | null
  current_page: number
  attention_reasons: AttentionReason[]
}

export interface MonitoringOverview {
  portfolio_total: number
  attention: Record<string, number>
}

export interface SerproConnectionMetadata {
  configured: boolean
  consumer_key_hint: string | null
  contracting_document: string | null
  certificate_subject: string | null
  certificate_serial: string | null
  certificate_not_before: string | null
  certificate_not_after: string | null
  updated_at: string | null
}

export interface SerproConnectionPayload {
  consumer_key: string
  consumer_secret?: string
  certificate?: File
  password?: string
}

export interface SerproConnectivityResult {
  ok: boolean
  /** `configuracao`, `certificado`, `credencial`, `provedor` — what to name. */
  failed_element: string | null
  message: string | null
  checked_at: string
}

export interface SerproAuthorizationTerm {
  state: SerproAuthorizationTermState
  expires_on: string | null
  signed_at: string | null
  /** The signed document is kept verbatim and is never rendered. */
  document_present: boolean
}

export interface SerproSyncRun {
  id: number
  state: SerproSyncRunState
  total: number
  synchronized: number
  skipped: number
  failed: number
  started_at: string | null
  finished_at: string | null
}

export interface SerproSyncRunItem {
  client_id: number
  name: string
  tax_id: string | null
  state: SerproRunItemState
  /** Present when `state` is `ignorado` or `falhou`. */
  reason: string | null
  /** The obligation slug this item covers. */
  obligation: string | null
  updated_at: string | null
}

export interface SerproSyncRunDetail extends SerproSyncRun {
  items: SerproSyncRunItem[]
}

export interface SerproAssociateResult {
  associated: number
  already: number
}
