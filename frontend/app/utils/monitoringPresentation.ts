import { isBefore, startOfToday } from 'date-fns'
import type {
  AttentionReasonCode,
  MonitoringAssessmentPeriod,
  MonitoringCounter,
  MonitoringObligationSummary,
  MonitoringSituacao,
  MonitoringSlipStatus,
  MonitoringSyncProgress,
  ObligationCategory,
  SerproAuthorizationTermState,
  SerproRunItemState,
  SerproSyncRunState
} from '~/types/serpro'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'error'

/**
 * The colour a status tab's icon takes, and the tinted box a header icon sits
 * in. Kept here so a template never spells a severity as a Tailwind class.
 */
export const monitoringTone: Record<Tone, { icon: string, box: string }> = {
  neutral: { icon: 'text-muted', box: 'bg-elevated text-muted ring-default' },
  info: { icon: 'text-info', box: 'bg-info/10 text-info ring-info/20' },
  success: { icon: 'text-success', box: 'bg-success/10 text-success ring-success/20' },
  warning: { icon: 'text-warning', box: 'bg-warning/10 text-warning ring-warning/20' },
  error: { icon: 'text-error', box: 'bg-error/10 text-error ring-error/20' }
}

/** The counter row's own label — the fifth reading, which is not a state. */
export const monitoringTotalLabel = 'Total'

export const monitoringTotalIcon = 'i-lucide-users'

export const monitoringActions = {
  associate: 'Adicionar clientes',
  refresh: 'Atualizar'
} as const

export const monitoringFilters = {
  search: 'Buscar por nome ou CNPJ'
} as const

export const monitoringEmpty = {
  noClients: 'Nenhum cliente nesta obrigação',
  noClientsDescription: 'Nenhum cliente da carteira tem registro sincronizado para esta obrigação.',
  noResults: 'Nenhum resultado com estes filtros',
  noResultsDescription: 'Ajuste a busca ou limpe os filtros aplicados.'
} as const

/**
 * What a reading the source did not supply is drawn as. Named rather than
 * written inline because "the source said nothing" must never read as a blank
 * cell, and every reader of this module has to agree on what that blank means:
 * it is not a zero, and not a value the office is expected to supply.
 */
export const monitoringMissingValue = '—'

/**
 * Staleness is an attribute of the synchronized data, never a situation. The
 * spec is explicit: a client whose power of attorney lapsed keeps its retained
 * data, and that data is labelled out of date while its situation stands.
 */
export const monitoringStalePresentation: { label: string, color: Tone, icon: string } = {
  label: 'Dado desatualizado',
  color: 'warning',
  icon: 'i-lucide-history'
}

export const monitoringCounterPresentation: Record<MonitoringCounter, { label: string, color: Tone, icon: string }> = {
  em_dia: { label: 'Em dia', color: 'success', icon: 'i-lucide-circle-check' },
  processando: { label: 'Processando', color: 'info', icon: 'i-lucide-repeat' },
  pendencias: { label: 'Pendências', color: 'warning', icon: 'i-lucide-clock' },
  atencao: { label: 'Atenção', color: 'error', icon: 'i-lucide-circle-alert' }
}

export const monitoringSituacaoPresentation: Record<MonitoringSituacao, { label: string, color: Tone, icon: string }> = {
  ...monitoringCounterPresentation,
  encerrado: { label: 'Encerrado', color: 'neutral', icon: 'i-lucide-lock' }
}

const attentionReasonPresentation: Record<AttentionReasonCode, { label: string, color: Tone, icon: string }> = {
  sem_declaracao: { label: 'Sem declaração', color: 'warning', icon: 'i-lucide-file-x' },
  sem_procuracao: { label: 'Sem procuração', color: 'error', icon: 'i-lucide-shield-off' },
  procuracao_invalida: { label: 'Procuração inválida', color: 'error', icon: 'i-lucide-shield-alert' },
  contam_debitos: { label: 'Contam débitos', color: 'warning', icon: 'i-lucide-file-warning' }
}

/**
 * Resolves by code, so a cause added server-side renders without a redeploy.
 * An unrecognised code falls back to the backend's own wording and then to the
 * code itself — never to a neutral blank, because a cause the operator cannot
 * read is worse than one that is missing.
 */
export function monitoringAttentionReasonPresentation(code: string, label?: string | null) {
  return attentionReasonPresentation[code as AttentionReasonCode]
    ?? { label: label || code, color: 'warning' as Tone, icon: 'i-lucide-circle-help' }
}

export const monitoringCategoryPresentation: Record<ObligationCategory, { label: string, description: string, color: Tone, icon: string }> = {
  direct: {
    label: 'Leitura direta',
    description: 'O Integra Contador publica um serviço que devolve estes dados estruturados.',
    color: 'success',
    icon: 'i-lucide-plug'
  },
  derived: {
    label: 'Leitura derivada',
    description: 'Estes dados saem de outro serviço do provedor, ou de um filtro sobre o assunto de uma mensagem. Não é uma fonte independente.',
    color: 'info',
    icon: 'i-lucide-layers'
  },
  unavailable: {
    label: 'Não servido pelo provedor',
    description: 'O catálogo do Integra Contador não publica serviço para esta obrigação. A ausência é da obrigação, não de um cliente: nenhum cliente aparece como pendente por causa dela.',
    color: 'warning',
    icon: 'i-lucide-unplug'
  },
  extinct: {
    label: 'Obrigação extinta',
    description: 'Esta obrigação deixou de ser devida. A IN RFB 2.043/2021 a substituiu por EFD-Reinf e eSocial, e a IN RFB 2.181/2024 adiou isso a 1º/1/2025. Nenhum dos sistemas sucessores é exposto pela integração.',
    color: 'neutral',
    icon: 'i-lucide-ban'
  }
}

export const monitoringSlipStatusPresentation: Record<MonitoringSlipStatus, { label: string, color: Tone, icon: string }> = {
  paid: { label: 'Guia emitida e paga', color: 'success', icon: 'i-lucide-circle-check' },
  issued: { label: 'Guia emitida, não paga', color: 'warning', icon: 'i-lucide-receipt' },
  owed: { label: 'Período em aberto', color: 'warning', icon: 'i-lucide-clock' },
  none: { label: 'Sem guia', color: 'neutral', icon: 'i-lucide-circle-minus' }
}

/**
 * The guide column when the synchronized data mentions no period at all.
 *
 * Deliberately **not** `monitoringSlipStatusPresentation.none`: that says a
 * known period owes no guide, and a dash says the source told us nothing about
 * a period. Rendering the first for the second would claim a fact about the
 * office's own declaration that nobody has evidence for.
 */
export const monitoringSlipMissingPresentation: { label: string, color: Tone, icon: string } = {
  label: monitoringMissingValue,
  color: 'neutral',
  icon: 'i-lucide-minus'
}

/**
 * Whether an issued guide was paid, in words. `null` for a period that owes a
 * guide or has none — "unpaid" is a claim about a guide that exists, and
 * applying it to a period with no guide would invent an obligation to pay.
 */
export const monitoringPaidPresentation: Record<'paid' | 'unpaid', { label: string, color: Tone, icon: string }> = {
  paid: { label: 'Paga', color: 'success', icon: 'i-lucide-circle-check' },
  unpaid: { label: 'Não paga', color: 'warning', icon: 'i-lucide-circle-x' }
}

export const serproRunStatePresentation: Record<SerproSyncRunState, { label: string, color: Tone, icon: string }> = {
  queued: { label: 'Na fila', color: 'neutral', icon: 'i-lucide-clock' },
  running: { label: 'Em execução', color: 'info', icon: 'i-lucide-repeat' },
  completed: { label: 'Concluída', color: 'success', icon: 'i-lucide-circle-check' },
  partial: { label: 'Parcial', color: 'warning', icon: 'i-lucide-triangle-alert' },
  failed: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-alert' }
}

export const serproRunItemStatePresentation: Record<SerproRunItemState, { label: string, color: Tone, icon: string }> = {
  sincronizado: { label: 'Sincronizado', color: 'success', icon: 'i-lucide-circle-check' },
  ignorado: { label: 'Ignorado', color: 'neutral', icon: 'i-lucide-minus' },
  falhou: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-alert' },
  indeterminado: { label: 'Indeterminado', color: 'warning', icon: 'i-lucide-circle-help' },
  nao_processado: { label: 'Não processado', color: 'neutral', icon: 'i-lucide-minus-circle' }
}

export const serproTermStatePresentation: Record<SerproAuthorizationTermState, { label: string, color: Tone, icon: string }> = {
  ausente: { label: 'Termo não emitido', color: 'warning', icon: 'i-lucide-file-x' },
  /*
   * `Aguardando o provedor`, e não "Em validação": `pendente` é gravado antes do
   * envio e continua valendo quando o envio falha, e nesse caso ninguém está
   * validando nada — é o mesmo estado, com o provedor calado. Um rótulo que
   * descreve um trabalho em andamento afirma uma atividade que o backend não
   * relata, e o texto do estado, logo abaixo, é quem diz o que está de fato
   * acontecendo.
   */
  pendente: { label: 'Aguardando o provedor', color: 'info', icon: 'i-lucide-clock' },
  validado: { label: 'Validado', color: 'success', icon: 'i-lucide-circle-check' },
  autenticado: { label: 'Autenticado', color: 'success', icon: 'i-lucide-shield-check' },
  vencido: { label: 'Vencido', color: 'error', icon: 'i-lucide-circle-alert' },
  recusado: { label: 'Recusado', color: 'error', icon: 'i-lucide-ban' }
}

/**
 * What the term screen is allowed to ask the office for: nothing at all, or the
 * office's e-CNPJ. A union and not a boolean so that a template cannot render
 * "ask" without also having a name for what is being asked.
 */
export type SerproTermRequest = 'nenhuma' | 'certificado'

/**
 * What the state alone says, and it is what the screen shows above every
 * certificate delivery form. The wording lives here rather than in a template
 * because it is state-conditional and a single sentence that is true for a valid
 * term is false for an expired one: `tasks.md` 4.11 makes renewal the office's own
 * action when the term lapses, so a page that says "nothing to do" above an
 * expired badge is telling the office something false about its own position.
 *
 * **No state here asks anyone to sign.** The office delivers its e-CNPJ once and
 * that is the whole of its participation: the signature is the platform's, made
 * with the certificate the office handed over (D3, and the "Escritório não assina
 * nada" scenario). A sentence in this record that asked a member to sign — or to
 * "sign again" — would be the one instruction the product must never give, and
 * `tests/monitoringTermGuidance.test.ts` fails the record if one appears under
 * any reword.
 *
 * **Every sentence here is true whether or not the certificate is on file.** The
 * pair-dependent half is `serproTermComCertificado` below, and a sentence that
 * mentioned the certificate here would be a lie on the screen of an office that
 * had just removed it — which is a state the product produces itself, with its
 * own remove button and no state gate on it.
 *
 * **No sentence here says a term was issued or will be issued.** The issuance
 * gate is closed and the API does not publish it, so a screen that promised a
 * signed-and-sent term would be promising every office something the product
 * cannot do today. Every sentence names **who** acts — a validity that is the
 * office's to deliver, an emission that is the platform's — and none of them
 * claims the act is done or on its way. The two places where the office's
 * next step is named are `serproCertificateAsk` and this record, and both point
 * at the e-CNPJ rather than at a signature.
 */
const serproTermGuidanceByState: Record<SerproAuthorizationTermState, string> = {
  ausente: 'O escritório ainda não tem termo, e o termo é emissão da plataforma: o escritório não assina nada.',
  pendente: 'A resposta do provedor ainda não chegou. Não há nada a fazer.',
  validado: 'O provedor aceitou o documento do termo, mas o token que autoriza as chamadas do escritório ainda não está disponível. A renovação diária tenta de novo, e isso é da plataforma: não há nada a fazer.',
  autenticado: 'O termo está autorizado e a plataforma fala com o provedor em nome do escritório. A renovação é feita sozinha, sem nenhuma ação do escritório.',
  vencido: 'A vigência do termo acabou. Emitir outro é do escritório e depende de um certificado digital vigente — a plataforma não o faz sozinha.',
  recusado: 'O provedor recusou o termo. Para sair disso o escritório precisa entregar um certificado digital vigente, e a emissão do termo novo é da plataforma.'
}

/**
 * The one sentence that only exists when the e-CNPJ is on file, and `null` in the
 * four states where the state sentence already says everything that is true.
 *
 * **`ausente` is the one that matters today.** With the issuance gate closed,
 * every office that delivered its certificate reads back as `ausente` with the
 * certificate stored, and without this line that office sees a warning-coloured
 * "Termo não emitido" badge with nothing that says the certificate is already
 * with us and nothing is being asked — the reading that turns a product fact into
 * an office's homework. `pendente` gets the short version, and it is the only
 * other state where the stored certificate changes what the term card should say.
 */
const serproTermComCertificado: Record<SerproAuthorizationTermState, string | null> = {
  ausente: 'O certificado do escritório já está com a plataforma, e nada está sendo pedido ao escritório agora.',
  pendente: 'O e-CNPJ já está com a plataforma.',
  validado: null,
  autenticado: null,
  vencido: null,
  recusado: null
}

/**
 * The term screen's sentence, for the state **and** whether the e-CNPJ is stored.
 *
 * The pair and not the state, because the certificate's presence is what the
 * removal button changes and what the two reachable `ausente` readings differ by:
 * an office that never delivered one is waiting for it, and an office that
 * delivered one and lost the term is not. A function of the state alone has to
 * pick one of those sentences and be wrong in the other — which is how this
 * screen came to tell an office that had just removed its certificate that the
 * e-CNPJ "had already been delivered".
 */
export function serproTermGuidance(state: SerproAuthorizationTermState, hasCertificate: boolean): string {
  const entrega = hasCertificate ? serproTermComCertificado[state] : null

  return entrega ? `${entrega} ${serproTermGuidanceByState[state]}` : serproTermGuidanceByState[state]
}

/**
 * What the screen is allowed to ask the office for, per state — the mapping
 * before the stored certificate bends it.
 *
 * `nenhuma` is not a filler: it is the answer that keeps a valid term from
 * becoming work for the office, which is the whole promise of the D3 design. The
 * three states that ask are the three where the platform has nothing left to try
 * on its own — no certificate to sign with (`ausente`), a document whose own
 * validity is over (`vencido`) and a document the provider read and refused
 * (`recusado`).
 */
const serproTermRequestByState: Record<SerproAuthorizationTermState, SerproTermRequest> = {
  ausente: 'certificado',
  pendente: 'nenhuma',
  validado: 'nenhuma',
  autenticado: 'nenhuma',
  vencido: 'certificado',
  recusado: 'certificado'
}

/**
 * Whether this screen asks the office to hand over its e-CNPJ. `hasCertificate` is
 * the second reading it takes, and it is the one that decides whether the office
 * has any work left to do at all.
 *
 * **`ausente` with a certificate on file asks for nothing, and that case is the
 * interesting one.** The issuance gate is closed: `issue()` refuses until
 * `term_format_proven_at` is recorded, the job the upload dispatches turns that
 * refusal into a log line without writing any state, and the upload's `200` is
 * unchanged by it — so a real office that delivered its certificate today reads
 * back as `ausente` with the certificate stored. Asking for the certificate again
 * would put the office in a loop it cannot leave, and would contradict the spec
 * scenario that says an office which stored a certificate has no further action.
 * There is no "waiting for the provider" state to draw here, because the backend
 * does not report one: what it reports is a term that does not exist yet, and
 * this is the reading of that.
 *
 * **`vencido` and `recusado` ask even when a certificate is stored, and the
 * asymmetry is the backend's.** `refresh()` gives up on a lapsed document instead
 * of resubmitting it, and it does not resend a refused one — resubmitting the same
 * bytes would draw the same refusal. The only lever that produces a *new* document
 * is the upload, because `AccountCertificateVault::replace()` is what dispatches
 * the issuance job. So what the office is asked for there is the re-delivery, and
 * the wording in `serproCertificateAsk` says exactly that.
 */
export function serproTermRequest(state: SerproAuthorizationTermState, hasCertificate: boolean): SerproTermRequest {
  if (state === 'ausente' && hasCertificate) return 'nenhuma'

  return serproTermRequestByState[state]
}

export interface SerproCertificateNotice {
  title: string
  description: string
  /**
   * The submit button's label. It belongs to the notice and not to the template
   * because "deliver" and "replace" are different claims about what the office has
   * already done, and a label picked in the template is a label that can be wrong.
   */
  label: string
  /** `warning` for what the office still owes, `neutral` for what only it can know. */
  color: 'neutral' | 'warning'
  icon: string
}

/**
 * The words for the request, or `null` when there is no request to make.
 *
 * The two flavours are the first delivery and the re-delivery, and the difference
 * is not cosmetic: the second one is a certificate the office already gave us, and
 * calling it "deliver your certificate" would read as though the first one had
 * never landed — which is the confusion this screen exists to avoid.
 *
 * Both descriptions end on the same fact, because it is the fact the office does
 * not have: from the e-CNPJ the platform signs, submits and renews the term, and
 * no member of the account signs anything at any point.
 */
export function serproCertificateAsk(state: SerproAuthorizationTermState, hasCertificate: boolean): SerproCertificateNotice | null {
  if (serproTermRequest(state, hasCertificate) !== 'certificado') return null

  if (!hasCertificate) {
    return {
      title: 'Entregar o certificado do escritório',
      /*
       * A consequência entra aqui e não numa nota separada de "certificado
       * ausente" porque este é o único texto que uma conta sem certificado e sem
       * termo vai ler: sem ele, a frase anterior diz como entregar e a nova diz o
       * que a entrega destrava — e o que a conta perde enquanto ela não
       * acontece.
       *
       * A segunda frase **descreve quem emite** e não promete que a emissão
       * acontece hoje: o gate de emissão está fechado, a API não publica se está
       * ou não, e uma tela que prometesse um termo emitido estaria mentindo para
       * todo escritório que entregou o e-CNPJ hoje.
       */
      description: 'É o certificado digital do escritório (e-CNPJ, arquivo .pfx ou .p12) e a senha que o abre, uma única vez. A partir dele quem monta, assina e renova o termo de autorização é a plataforma — e ninguém da conta assina nada. Sem ele não há termo, e sem termo a integração não fala com o provedor em nome de nenhum cliente.',
      label: 'Entregar certificado',
      color: 'warning',
      icon: 'i-lucide-upload'
    }
  }

  return {
    title: 'Entregar o certificado de novo',
    description: 'O termo guardado não serve mais para a plataforma: o provedor ou a vigência o encerraram, e nenhum dos dois se resolve com o documento que já está gravado. Reenvie o e-CNPJ — pode ser o mesmo arquivo — e a emissão do termo novo é da plataforma, sozinha.',
    label: 'Enviar o certificado de novo',
    color: 'warning',
    icon: 'i-lucide-upload'
  }
}

/**
 * Whether the platform holds a signed document for this state, keyed by state and
 * exhaustive on purpose: it is the fact that decides what the note below is
 * allowed to talk about, and a state added without an entry here would inherit a
 * sentence about a document nobody has.
 *
 * **`pendente` is `true`, and that is the subtle one.** `SerproTermManager::guardar()`
 * writes the signed XML and the `Pendente` state in the same statement, and it
 * runs before the submission — which is why `document_present` is true for every
 * existing term — so a `pendente` term is signed and held by the platform while
 * the provider has not answered. What it is not is *in force*:
 * `authorizesGateway()` accepts only `validado` and `autenticado`, so a `pendente`
 * term authorises no call. A sentence about a `pendente` term that said it
 * "continued in force" would be the false claim; one that said it was unsigned
 * would be false too.
 *
 * **This predicate is not "is it being sent", and the two are not the same
 * thing.** A document can exist and still never leave the platform again — that is
 * what `serproTermReenviado` below is for, and a sentence about one cannot be
 * chosen with this one.
 */
const serproTermDocumentoGravado: Record<SerproAuthorizationTermState, boolean> = {
  ausente: false,
  pendente: true,
  validado: true,
  autenticado: true,
  vencido: true,
  recusado: true
}

/**
 * Whether the daily `refresh()` still resubmits the stored document, which is what
 * "a plataforma continua cuidando do termo" means.
 *
 * **`false` for `vencido` and `recusado`, and the difference is the whole point
 * of the table.** `SerproTermManager::refresh()` returns before `enviar()` when the
 * document's own validity is over, and it returns before `enviar()` for a refused
 * document too — resending the same bytes would draw the same refusal. The
 * document is still stored in both cases, so `serproTermDocumentoGravado` is
 * `true` for them and a sentence chosen on that table would promise a submission
 * the product never makes. **A branch that asserts "continua sendo enviado" has
 * to read this table, not the other one.**
 */
const serproTermReenviado: Record<SerproAuthorizationTermState, boolean> = {
  ausente: false,
  pendente: true,
  validado: true,
  autenticado: true,
  vencido: false,
  recusado: false
}

/**
 * Whether the term authorises calls, which is `SerproAuthorizationTerm::authorizesGateway()`
 * verbatim: `validado` and `autenticado` only, and only once a usable token is on
 * the row. A sentence that says a term "continua valendo" has to read this table
 * and not its neighbour — a `pendente` term is signed, stored and resubmitted, and
 * it authorises nothing.
 */
const serproTermEmVigor: Record<SerproAuthorizationTermState, boolean> = {
  ausente: false,
  pendente: false,
  validado: true,
  autenticado: true,
  vencido: false,
  recusado: false
}

/**
 * The certificate card's notice when there is no certificate on file and nothing
 * is being asked about it — which is the office that delivered one, got a term,
 * and then removed the certificate, a state the product produces itself.
 *
 * **`null` in every case where this has no business speaking.** With a
 * certificate on file there is no missing certificate to describe; and in
 * `ausente`, `vencido` and `recusado` the office is being asked to deliver one,
 * which is `serproCertificateAsk`'s text and not this one's. Returning `null`
 * instead of a sentence is what makes the note impossible to point at a state it
 * knows nothing true about — `ausente` without a certificate used to get its own
 * wording here, unreachable behind the `ask` that the card renders first.
 *
 * **The two wordings that are left exist because the two consequences are
 * different, and only one of them is a broken integration.** With a term the
 * provider has not answered, the platform holds a signed document that
 * authorises nothing yet; with a term in force, `refresh()` resubmits that
 * document without ever asking for a certificate and `validToken()` serves the
 * token while it lasts. What the office lost either way is the ability to have a
 * *new* term issued, and nothing else. One sentence covering both would tell an
 * office whose term is still in flight that its term is in force — which is
 * false, and which is what this function used to say.
 *
 * **Each branch is chosen by the fact its own sentence asserts.** "Continua
 * valendo e sendo renovado" needs `serproTermEmVigor` **and** `serproTermReenviado`,
 * and the branch asks for both; the in-flight sentence is the one that holds for a
 * document the platform keeps resubmitting without it being in force, which today
 * is `pendente` alone. Anything else returns `null` instead of borrowing a
 * sentence.
 */
export function serproCertificateMissingNotice(
  state: SerproAuthorizationTermState,
  hasCertificate: boolean
): SerproCertificateNotice | null {
  if (hasCertificate) return null
  if (serproTermRequest(state, hasCertificate) !== 'nenhuma') return null

  if (serproTermEmVigor[state] && serproTermReenviado[state]) {
    return {
      title: 'O certificado do escritório não está mais guardado',
      description: 'O termo já assinado continua valendo e sendo renovado pela plataforma, que reenvia o documento guardado sem precisar do certificado. O que a remoção tira é a emissão de um termo novo — nenhum estado de tela afirma que a integração parou, porque ela não parou.',
      /*
       * O rótulo ainda é o da primeira entrega, e é o certo: o formulário existe,
       * a conta está sem certificado nenhum, e "substituir" seria mentira sobre o
       * que está guardado. O que a nota acima diz é que a entrega não é urgente
       * agora — e o botão não precisa prometer urgência para dizer a verdade.
       */
      label: 'Entregar certificado',
      color: 'neutral',
      icon: 'i-lucide-shield-off'
    }
  }

  if (serproTermReenviado[state]) {
    return {
      title: 'O certificado do escritório não está mais guardado',
      description: 'O termo já está assinado e na mão da plataforma, mas o provedor ainda não respondeu e ele ainda não autoriza nenhuma chamada. A plataforma continua cuidando dele; o que a remoção tira é a emissão de um termo novo.',
      label: 'Entregar certificado',
      color: 'neutral',
      icon: 'i-lucide-shield-off'
    }
  }

  return null
}

/**
 * The buttons in front of a removal. The sentence that carries the promise is
 * {@link serproCertificateRemovalText}, and it is a function because the promise
 * depends on whether a term was ever signed.
 */
export const serproCertificateRemoval = {
  action: 'Remover certificado',
  title: 'Remover o certificado do escritório?',
  keep: 'Manter certificado',
  confirm: 'Confirmar remoção'
} as const

/**
 * The confirmation's text, and it says what the removal does **not** do — that is
 * the sentence that matters. An office that removes a compromised certificate
 * reasonably expects the integration to stop; whether it stops depends on the
 * term, and a confirmation that implied one answer or the other would be wrong for
 * half the states. The vault blanks the two encrypted columns and keeps the
 * metadata, and what is really lost is the ability to issue a new term.
 *
 * **Three sentences, and each is chosen by the fact it asserts.** The middle one
 * promises a submission, so it is gated on `serproTermReenviado` — the fact about
 * `refresh()` — and **not** on `serproTermDocumentoGravado`, the fact about
 * storage. The two are different, and they differ in exactly `vencido` and
 * `recusado`: the document is stored in both and sent in neither, because
 * `refresh()` returns before `enviar()` in both. An office that removed its
 * certificate with a lapsed term would be told, por esta frase, que a plataforma
 * continua enviando um documento que ela não envia.
 *
 * **The `ausente` wording is the other false one.** The remove button appears
 * whenever a certificate exists, with no state gate on it, and with the issuance
 * gate closed "certificate delivered, no term yet" is the commonest state this
 * product has. Telling that office that the "already-signed document keeps being
 * sent" describes a document nobody has.
 *
 * **`hasCertificate` changes the first branch only.** Without a certificate there
 * is no row to mark as removed, and `AccountCertificateVault::remove()` answers
 * `204` having erased nothing — so the confirmation must not claim it erased.
 */
export function serproCertificateRemovalText(state: SerproAuthorizationTermState, hasCertificate: boolean): string {
  if (!hasCertificate) {
    // O par inteiro, e não um atalho: sem certificado não há linha para marcar
    // como removida, e `AccountCertificateVault::remove()` devolve `null` num
    // `204` que não apagou nada. Uma confirmação dizendo que o conteúdo foi
    // apagado descreveria um certificado que não existe.
    return 'Nenhum certificado está guardado nesta conta, e não há termo assinado. Não há o que remover.'
  }

  const abertura = 'O conteúdo cifrado do e-CNPJ é apagado e fica só o metadado.'

  if (!serproTermDocumentoGravado[state]) {
    return `${abertura} Ainda não há termo assinado: o que a remoção tira é a capacidade de a plataforma emitir um, e não havia nada em vigor para ser interrompido.`
  }

  if (!serproTermReenviado[state]) {
    return `${abertura} A remoção não revoga o termo: ele continua gravado, mas a plataforma não o está reenviando — a vigência do documento acabou, ou o provedor recusou o que foi enviado, e nenhuma das duas se resolve com o certificado. O que a remoção tira é a capacidade de a plataforma emitir um termo novo.`
  }

  return `${abertura} A remoção não revoga o termo: o documento já assinado continua gravado e continua sendo enviado pela plataforma, e o que ela perde é a capacidade de emitir um termo novo.`
}

/**
 * The delivery form when there is nothing to ask for — a valid term with a
 * certificate on file, which is the state an office sits in for most of the year.
 *
 * **The form is not gated on `serproTermRequest`, and this is why.** The e-CNPJ has
 * an expiry of its own, independent of the term's thirty days, and a term in force
 * says nothing about the certificate behind it: when the office's e-CNPJ lapses,
 * `issue()` refuses to sign a new term with it. An office that could only deliver
 * a certificate while something was pending would then have no way to hand over
 * the new one, and the only other way to change it is a removal, which is also a
 * write. Delivering is therefore always available to whoever may write, and what
 * `serproTermRequest` decides is only whether the office is *being asked* to.
 */
export const serproCertificateReplacement = {
  title: 'Substituir o certificado do escritório',
  description: 'O e-CNPJ do escritório expira, e é ele que assina o termo. Entregue o novo antes de o antigo expirar: quem monta, assina e renova o termo é a plataforma, e a integração não fica para trás.',
  label: 'Substituir certificado'
} as const

/** The total is the sum of the four; `encerrado` is deliberately not in it. */
export function monitoringCountersTotal(summary: Pick<MonitoringObligationSummary, 'em_dia' | 'processando' | 'pendencias' | 'atencao'>) {
  return summary.em_dia + summary.processando + summary.pendencias + summary.atencao
}

export function formatMonitoringCount(value: number) {
  return new Intl.NumberFormat('pt-BR').format(value)
}

/** What a projection has to name before the office can read it as one. */
export const monitoringProvenanceLabels: { origin: string, service: string } = {
  origin: 'Deriva de',
  service: 'Serviço no provedor'
}

/**
 * The provenance of a `derived` obligation, as the office reads it.
 *
 * A derived obligation is a projection: the same provider call as another
 * obligation, or a filter over a message that call already returned. Naming what
 * it projects over — and on which service — is what keeps it from being read as
 * an independent source, so the panel declares it on the obligation page and
 * the overview flags the card before the member clicks.
 *
 * `null` for anything that is not `derived`. A direct obligation has no
 * projection to declare, and an unserved one is replaced by its own alert
 * explaining why there is nothing here.
 */
export function monitoringProvenance(obligation: {
  category: ObligationCategory
  derivedFrom?: string
  service: string | null
}): { origin: string | null, service: string | null } | null {
  if (obligation.category !== 'derived') return null
  return {
    origin: obligation.derivedFrom ?? null,
    service: obligation.service
  }
}

/**
 * The progress axis, and the sentence that reads it.
 *
 * How many clients have been transmitted out of how many were requested is a
 * reading of the synchronization, not a state of any client — so it is labelled
 * as one and never joins the four counters, whose `total` it does not touch. The
 * sentence is built here so the panel cannot render "3 de" with nothing after
 * it, and `null` while the backend does not report the pair: no reading is not a
 * reading of zero.
 */
export const monitoringProgressPresentation: { label: string, color: Tone, icon: string, note: string } = {
  label: 'Transmitidos',
  color: 'info',
  icon: 'i-lucide-send',
  note: 'leitura da sincronização, e não o estado de nenhum cliente'
}

export function formatMonitoringProgress(progress: MonitoringSyncProgress | null | undefined): string | null {
  if (!progress) return null
  return `${monitoringProgressPresentation.label}: ${formatMonitoringCount(progress.transmitted)} de ${formatMonitoringCount(progress.requested)} — ${monitoringProgressPresentation.note}.`
}

export function formatMonitoringDate(value: string | null | undefined) {
  if (!value) return monitoringMissingValue
  const [date] = value.split('T')
  const [year, month, day] = (date ?? '').split('-')
  if (!year || !month || !day) return monitoringMissingValue
  return `${day}/${month}/${year}`
}

export function formatMonitoringDueOn(value: string | null | undefined) {
  return formatMonitoringDate(value)
}

/**
 * Whether the deadline a message opened is already over (D19).
 *
 * The provider publishes a day, and a deadline is missed when that day is over —
 * so the time of day is discarded before comparing. `new Date('2026-05-20')` is
 * midnight UTC, which in Brasília is 21h on the 19th: it would report a
 * deadline as missed an hour before the day it names.
 *
 * One function for the row and the detail, because a message cannot be both
 * within and beyond its prazo depending on which screen the office is reading.
 */
export function monitoringDeadlinePassed(deadline: string | null | undefined) {
  const [day] = (deadline ?? '').split('T')
  if (!day) return false
  // A date the client cannot parse is not a deadline it may call missed.
  return isBefore(new Date(`${day}T00:00:00`), startOfToday())
}

export type MonitoringSlip = {
  status: MonitoringSlipStatus
  slip_number: string | null
  issued_on: string | null
  due_on: string | null
}

const NO_SLIP: MonitoringSlip = { status: 'none', slip_number: null, issued_on: null, due_on: null }

/**
 * The most recent transmission wins: a rectified declaration for a period that
 * already had an original is the one that is current, and the earlier one must
 * not be shown as such. Derived from synchronized data — no provider call.
 */
export function slipStatusFor(periods: readonly MonitoringAssessmentPeriod[], period: string): MonitoringSlip {
  const candidates = periods.filter(item => item.period === period)
  if (!candidates.length) return NO_SLIP

  const latest = candidates.reduce<MonitoringAssessmentPeriod | null>((chosen, item) => {
    if (!chosen) return item
    if (!item.declared_at) return chosen
    if (!chosen.declared_at) return item
    return item.declared_at > chosen.declared_at ? item : chosen
  }, null)

  if (!latest) return NO_SLIP
  if (!latest.declared_at || !latest.slip_number) {
    return { status: latest.declared_at ? 'owed' : 'none', slip_number: null, issued_on: null, due_on: latest.due_on }
  }
  return {
    status: latest.slip_paid ? 'paid' : 'issued',
    slip_number: latest.slip_number,
    issued_on: latest.slip_issued_at,
    due_on: latest.due_on
  }
}

/**
 * The guide a row's synchronized periods describe: the most recent period, with
 * the most recent transmission of it. `period` is `YYYY-MM`, so the greatest
 * string is the most recent period without a date parse.
 *
 * `null` — never `slipStatusFor`'s `none` — when the data mentions no period at
 * all. The two are different claims and must not read the same on a screen: one
 * is about a known period, the other is about the data.
 */
export function latestSlipFor(periods: readonly MonitoringAssessmentPeriod[] | null | undefined): MonitoringSlip | null {
  if (!periods?.length) return null
  const period = periods.reduce((latest, item) => (!latest || item.period > latest ? item.period : latest), '')
  if (!period) return null
  return slipStatusFor(periods, period)
}

function paidKeyFor(slip: MonitoringSlip): 'paid' | 'unpaid' | null {
  if (slip.status === 'paid') return 'paid'
  if (slip.status === 'issued') return 'unpaid'
  return null
}

/**
 * The guide columns, read from the row's synchronized periods instead of
 * `fields`. A provider string could not say which period a guide belonged to,
 * when it was issued, or whether it was paid — and reading it from `fields`
 * would render whatever opaque text arrived, under a header promising a status.
 *
 * Keyed by the column ids the registry declares, so the two are edited together
 * and an id nobody can read falls back to the em dash rather than to `fields`.
 */
export const monitoringSlipColumns: Record<string, (slip: MonitoringSlip) => string> = {
  guia: slip => monitoringSlipStatusPresentation[slip.status].label,
  guia_numero: slip => slip.slip_number ?? monitoringMissingValue,
  guia_emitida_em: slip => formatMonitoringDate(slip.issued_on),
  guia_vencimento: slip => formatMonitoringDate(slip.due_on),
  guia_paga: (slip) => {
    const paid = paidKeyFor(slip)
    return paid ? monitoringPaidPresentation[paid].label : monitoringMissingValue
  }
}

/**
 * Whether this column id is one the guide derivation reads.
 *
 * `Object.hasOwn`, not `in`: a plain `in` would answer `true` for an id like
 * `toString` inherited from `Object.prototype`, and the row's cell would render
 * `[object Object]` under a column header.
 */
export function isMonitoringSlipColumn(id: string): boolean {
  return Object.hasOwn(monitoringSlipColumns, id)
}

/**
 * What one guide column shows for a row, or the em dash when the synchronized
 * data says nothing about a period. The dash is not "no guide exists": it is the
 * source having reported no period at all, which is what a row looks like
 * before the backend populates `periods`.
 */
export function monitoringSlipColumnValue(id: string, periods: readonly MonitoringAssessmentPeriod[] | null | undefined): string {
  if (!isMonitoringSlipColumn(id)) return monitoringMissingValue
  const slip = latestSlipFor(periods)
  return slip ? monitoringSlipColumns[id]!(slip) : monitoringMissingValue
}
