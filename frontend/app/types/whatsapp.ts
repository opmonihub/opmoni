/**
 * Wire contract do Canal WhatsApp da Account (gateway wzap).
 *
 * Os campos são os `snake_case` do `WhatsAppChannelController::channelPayload`
 * verbatim: o espelho do canal em `status`, o QR só quando a resposta do
 * gateway o carrega, e nunca a apikey da instância (cifrada no backend).
 */

/** Os quatro estados espelhados do canal. */
export type WhatsAppChannelStatus = 'disconnected' | 'pairing' | 'connected' | 'error'

export interface WhatsAppChannelOperationNotice {
  code: 'operation_continuation_required' | 'operation_reconciliation_required' | 'operation_manual_review_required' | 'operation_superseded'
  operation_id: string
  next_step: string
  message?: string
}

export interface WhatsAppChannel {
  operation?: WhatsAppChannelOperationNotice | null
  status: WhatsAppChannelStatus
  /**
   * O código de pareamento cru do whatsmeow (prefixo `2@…`), não uma imagem:
   * a tela desenha o QR a partir dele. Só vem na resposta do POST de
   * pareamento enquanto a instância aguarda a leitura.
   */
  qr_code: string | null
  /** Quando o QR deixa de valer — depois dele um novo pareamento emite outro. */
  qr_expires_at: string | null
  /** O JID do número pareado, quando o gateway já o conhece. */
  whatsapp_jid: string | null
  /** O motivo sanitizado da última falha do gateway; `null` sem falha. */
  last_error: string | null
  connected_at: string | null
  pairing_started_at: string | null
}

/**
 * Wire contract de uma linha de Rastreio — o payload do
 * `WhatsAppDispatchController::payload`, verbatim. Nunca telefone, apikey
 * ou conteúdo de documento: o destino fica no backend.
 */

/** Os tipos de documento PGDAS que o Disparo envia — mesmos do detalhe. */
export type WhatsAppArtifact = 'guia' | 'recibo' | 'declaracao'

/** A máquina do Rastreio: enfileirado → enviado → entregue → lido, ou falhou. */
export type WhatsAppDispatchStatus = 'queued' | 'sent' | 'delivered' | 'read' | 'failed'

/**
 * O canal de um Contato de envio: `whatsapp` recebe Disparo de guia nesta
 * versão; `email` é cadastrado e listado, mas nunca selecionável para envio.
 */
export type DispatchContactChannel = 'whatsapp' | 'email'

/**
 * Um Contato de envio do cliente (`ClientDispatchContactController`):
 * rótulo opcional + exatamente um canal + o valor do canal — telefone em
 * dígitos para `whatsapp`, e-mail em minúsculas para `email`. Não é o
 * "Contato" comercial da conta; a spec reserva esse nome.
 */
export interface ClientDispatchContact {
  id: number
  client_id: number
  /** O nome de guerra do destino ("Sócio 1"); `null` é contato sem rótulo. */
  label: string | null
  channel: DispatchContactChannel
  /** Telefone em dígitos ou e-mail em minúsculas — já normalizado no backend. */
  value: string
  created_at: string | null
  updated_at: string | null
}

/**
 * O destino gravado na linha de Rastreio. `null` no disparo legado
 * (telefone da carteira) e quando o contato foi removido depois do envio —
 * a referência sobrevive como id órfão e a leitura diz "contato removido".
 */
export type WhatsAppDispatchContact = Pick<ClientDispatchContact, 'id' | 'label' | 'channel' | 'value'>

export interface WhatsAppDispatch {
  id: number
  client_id: number
  obligation: string
  artifact_type: WhatsAppArtifact
  /** `YYYY-MM`. */
  competence: string
  /** O número do DAS enviado; `null` na guia gerada por declaração transmitida. */
  slip_number: string | null
  /** O Contato de envio de destino; `null` no fluxo legado e no contato removido. */
  contact: WhatsAppDispatchContact | null
  status: WhatsAppDispatchStatus
  retry_safe?: boolean
  send_attempted_at?: string | null
  /** O id da mensagem no gateway; `null` até o envio responder. */
  message_id: string | null
  /** Motivo seguro para a UI; `null` sem falha. */
  failure_reason: string | null
  /** O disparo partiu de um acesso de suporte auditado. */
  dispatched_in_support: boolean
  created_at: string | null
  updated_at: string | null
}

/**
 * O 202 do `send-guides`: a guia que o backend resolveu (`competence` +
 * `slip_number`), um Disparo por contato WhatsApp marcado e a lista dos
 * que ficaram de fora com o motivo legível (`skipped`).
 */
export interface WhatsAppSendGuidesResult {
  competence: string
  slip_number: string | null
  dispatches: WhatsAppDispatch[]
  skipped: { contact_id: number, reason: string }[]
}
