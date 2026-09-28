// app/types/fiscal.ts
//
// O contrato do módulo Fiscal, como o backend o entrega. Cada campo abaixo
// espelha uma coluna ou uma chave do `FiscalDocumentResource`, do
// `FiscalDocumentDetailResource` ou do resumo de `FiscalCoverage` — nada aqui
// foi escolhido pelo frontend, e nada aqui pode ser "enriquecido" na chamada:
// se a tela precisa de um campo novo, é o Resource que tem de abrir mão de um.

/** O modelo do documento fiscal, o campo `mod` da chave de acesso. */
export type FiscalModel = 'nfe' | 'nfce' | 'cte' | 'nfse'

/**
 * Documento ou evento. Eixo diferente de `FiscalStage`: o resumo e o documento
 * autorizado são os dois `document`, e o que os separa é a etapa da distribuição.
 */
export type FiscalKind = 'document' | 'event'

/** Onde a entrega caiu na cadeia da distribuição do documento. */
export type FiscalStage = 'summary' | 'document' | 'event'

/** A distribuição que entregou a linha. */
export type FiscalSource = 'nfe_distribuicao' | 'cte_distribuicao'

/**
 * Por que este cliente precisa de alguém.
 *
 * A lista é fechada de propósito. São nove, e cada uma nomeia um próximo passo
 * diferente, não uma nota de gravidade: um A1 vencido se resolve reenviando o
 * certificado, uma consulta parada pelo fisco se resolve esperando, e uma falha
 * qualquer se resolve sozinha na próxima execução. Um décimo motivo aparecer
 * aqui é o sinal de que o painel precisa de um rótulo novo, e não de que o
 * rótulo existente serve para tudo.
 *
 * A ordem em que o backend avalia é a mesma, do que impede a consulta de existir
 * ao que se resolve sozinho — está em `FiscalCoverage::motivo()`.
 */
export type FiscalAttentionReason
  = | 'certificate_absent'
    | 'certificate_expired'
    | 'certificate_password_missing'
    | 'certificate_reupload'
    | 'gap_abandoned'
    | 'history_interrupted'
    | 'capture_blocked'
    | 'continuity_warning'
    | 'capture_failed'

/** A cobertura da carteira: quanto cliente dá para consultar hoje. */
export interface FiscalCoverage {
  total: number
  capturable: number
  not_capturable: number
}

/**
 * Um cliente que precisa de alguém, e por quê.
 *
 * `blocked_until` só tem valor em `capture_blocked` — nos outros motivos é
 * `null` de propósito, para que o painel aprenda a não mostrar uma hora que não
 * significa nada. A parada é por fonte e por tempo: no passado ela já passou.
 */
export interface FiscalAttentionItem {
  client_id: number
  client_name: string
  reason: FiscalAttentionReason
  blocked_until: string | null
}

/** Os agregados de documento do resumo, todos contados por conta. */
export interface FiscalSummaryDocuments {
  total: number
  /**
   * Volume por modelo, e só com o modelo que tem documento: um modelo ausente
   * do mapa é "nada capturado desse modelo ainda", não zero.
   */
  models: Record<string, number>
  /**
   * A série de emissão, em ordem cronológica e só com o mês que teve documento.
   * Um mês zerado é um dado que o fisco não produziu, e a série precisa poder
   * dizer "ainda não há o que mostrar" em vez de mostrar um zero bonito.
   */
  over_time: { month: string, total: number }[]
}

/** A última consulta que a conta fez, e o que ela deixou na coluna. */
export interface FiscalLastCapture {
  source: FiscalSource
  ran_at: string
  /**
   * Nome de classe, classificação do conector ou frase fixa — nunca a mensagem
   * do serviço, nunca XML, nunca a senha do certificado.
   */
  error: string | null
}

/** `GET /api/fiscal/summary`. */
export interface FiscalSummary {
  coverage: FiscalCoverage
  attention: FiscalAttentionItem[]
  documents: FiscalSummaryDocuments
  /**
   * `null` é "a conta nunca consultou", que é a primeira coisa que o painel
   * precisa distinguir de "consultou e não achou nada" — por isso não é um
   * cursor zerado.
   */
  last_capture: FiscalLastCapture | null
}

/** O dono do documento. */
export interface FiscalDocumentClient {
  id: number
  name: string
  tax_id: string | null
}

/** Uma linha da tabela de documentos capturados. */
export interface FiscalDocumentRow {
  id: number
  /**
   * Sempre presente, e não opcional: a coluna é `NOT NULL` com cascade, e a
   * relação abre o cliente removido por logicamente (`withTrashed`), porque o
   * documento guardado é histórico legítimo e continua alcançável pelo id de
   * quem o emitiu.
   */
  client: FiscalDocumentClient
  model: FiscalModel
  kind: FiscalKind
  stage: FiscalStage
  chave_acesso: string
  emitente_cnpj: string | null
  destinatario_cnpj: string | null
  /**
   * Texto decimal, e nunca número: a coluna é `decimal(14,2)` e o Laravel a
   * devolve com o cast `decimal:2`, porque um float de JSON não representa
   * `0.01`. Quem exibe converte por parsing (`Number.parseFloat`) e nunca por
   * aritmética — somar em cents sobre float volta a errar no centavo.
   */
  valor_total: string | null
  emissao_at: string | null
  /**
   * Quantos eventos existem na linha do tempo desta chave de acesso, contando
   * as próprias linhas de evento — não há autoexclusão, e é essa contagem que
   * o detalhe repete para a mesma chave. Subtrair uma linha aqui colocaria dois
   * valores diferentes para a mesma chave na mesma tela.
   */
  event_count: number
  mascarado: boolean
  /**
   * `true` confere, `false` diverge e `null` é o terceiro estado: "a outra etapa
   * da distribuição ainda não chegou", que não é o mesmo que digest corrompido.
   */
  digval_confere: boolean | null
}

/** Uma linha da linha do tempo de uma chave de acesso. */
export interface FiscalDocumentEvent {
  id: number
  /** O código do evento do fisco, que é o que dá sentido à linha. */
  event_id: string
  evento_ocorrido_em_at: string | null
  /** A diferença entre o evento e a captura diz se a carteira rodava naquele dia. */
  captured_at: string
  mascarado: boolean
}

/**
 * `GET /api/fiscal/documents/{id}` — a linha da lista mais o que só o detalhe
 * tem. A linha vem do mesmo Resource, então o detalhe não pode divergir do que a
 * lista mostra.
 */
export interface FiscalDetail extends FiscalDocumentRow {
  source: FiscalSource
  /** A posição interna da distribuição. */
  nsu: number
  /** Vazio quando o documento não é um evento. */
  event_id: string
  schema: string | null
  sha256: string
  digval: string | null
  xml_bytes: number
  captured_at: string
  evento_ocorrido_em_at: string | null
  /** A linha do tempo, em ordem cronológica, com o mesmo `event_count` da linha. */
  events: FiscalDocumentEvent[]
  /**
   * O XML para exibição, com teto de 4096 bytes, ou `null` quando não há o que
   * mostrar: o arquivo sumiu do disco, ou o byte gravado é de uma codificação
   * que a projeção de exibição recusa em vez de adivinhar. Os dois casos deixam
   * o download — que serve o byte cru — funcionando.
   */
  xml_preview: string | null
}

/** Os campos ordenáveis, e só eles: o resto cai no padrão calado do backend. */
export type FiscalSort = 'emissao_at' | 'valor_total' | 'captured_at'

/** Os tamanhos de página que o backend aceita. Qualquer outro é 422. */
export type FiscalPerPage = 25 | 50 | 100

export type FiscalSortDirection = 'asc' | 'desc'

/**
 * Os filtros de `GET /api/fiscal/documents`.
 *
 * `issuer` e `recipient` são prefixo de CNPJ em dígitos, e são a única
 * responsabilidade do chamador aqui: vazio, `null` ou `undefined` é descartado
 * por `queryOf` e não vai na query, porque um filtro enviado vazio volta com a
 * lista sem filtro nenhum debaixo de uma barra que parece aplicada. Espaços e
 * qualquer caractere fora de dígito são 422 no backend — a limpeza é de quem
 * digita, e a tela de filtro é o lugar dela.
 */
export interface FiscalListFilters {
  model?: FiscalModel[]
  client_id?: number | null
  issuer?: string | null
  recipient?: string | null
  kind?: FiscalKind | null
  issued_from?: string | null
  issued_to?: string | null
  amount_min?: number | null
  amount_max?: number | null
  sort?: FiscalSort
  direction?: FiscalSortDirection
  page?: number
  per_page?: FiscalPerPage
}

/** `GET /api/fiscal/documents` — a página, e os modelos que o filtro oferece. */
export interface FiscalPage {
  data: FiscalDocumentRow[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
  /**
   * Os modelos disponíveis no filtro, e não os que a página tem.
   *
   * Um modelo selecionado continua na lista mesmo sem linha atrás dele, para que
   * o filtro que esvaziou a tabela possa ser tirado. Uma tela que assume "todo
   * chip tem documento" some justamente com o chip que o operador precisa
   * tocar.
   */
  available_models: string[]
}

/** O aceite da fila de `POST /api/fiscal/clients/{id}/capture`. */
export interface FiscalCaptureAccepted {
  queued: true
  client_id: number
}
