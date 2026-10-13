import type { MonitoringAssessmentPeriod, MonitoringWhatsAppSummary } from '~/types/serpro'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import type {
  ClientDispatchContact,
  WhatsAppArtifact,
  WhatsAppChannelStatus,
  WhatsAppDispatch,
  WhatsAppDispatchContact,
  WhatsAppDispatchStatus
} from '~/types/whatsapp'

/**
 * O lado da folha PGDAS do Disparo WhatsApp: quem pode, quando o botão
 * acende e como cada estado do Rastreio lê na linha.
 *
 * Regra de bloqueio espelha o `WhatsAppDispatchService` do backend: canal
 * primeiro (estado do escritório), papel (quem pede), telefone (destino) e
 * artefato (o que vai), nessa ordem — o motivo que a UI expõe é o primeiro
 * que barra, porque é o que o operador resolve primeiro.
 */

/** Papel do membro na Account corrente; `super_admin` dispara como admin (suporte auditado). */
export type WhatsAppDispatchRole = 'admin' | 'operador' | 'user' | 'super_admin' | null | undefined

export interface WhatsAppDispatchEligibilityInput {
  /**
   * O `phone` da carteira — o único destino do envio. `undefined` é "o GET
   * ainda não respondeu", `null` é "respondeu e não há telefone": um
   * aguarda, o outro bloqueia.
   */
  phone: string | null | undefined
  /**
   * Estado espelhado do Canal WhatsApp. `undefined` = a leitura não chegou
   * (botão espera, sem conclusão); `null` = confirmado que a conta nunca
   * pareou (o bloqueio concreto).
   */
  channelStatus: WhatsAppChannelStatus | null | undefined
  /** O artefato existe para a competência (mesma regra do download PGDAS). */
  artifactAvailable: boolean
  role: WhatsAppDispatchRole
}

export interface WhatsAppDispatchEligibility {
  eligible: boolean
  /**
   * Algum dado de elegibilidade ainda está sendo lido: o botão fica
   * desabilitado com o affordance de espera, nunca com um motivo concreto
   * que a leitura ainda pode contradizer. `undefined` em `channelStatus`
   * ou `phone` — `null` é sempre resposta concluída, nunca pendência.
   */
  pending: boolean
  /** A frase que a UI mostra como motivo do botão desabilitado. */
  reason: string | null
}

/** Os motivos do bloqueio, uma frase por barreira — vocabulário do produto, nunca o campo cru. */
export const whatsappDispatchReasons = {
  noChannel: 'O escritório não tem Canal WhatsApp pareado.',
  channelDisconnected: 'O Canal WhatsApp está desconectado — pareie em Configurações.',
  channelPairing: 'O Canal WhatsApp ainda está pareando — aguarde a conexão.',
  channelError: 'O Canal WhatsApp está em erro — reinicie o pareamento em Configurações.',
  roleNotAllowed: 'Só administradores e operadores disparam documentos — seu papel permite ver o rastreio.',
  phoneMissing: 'O cliente não tem telefone cadastrado na carteira.',
  phoneInvalid: 'O telefone do cliente está fora do padrão para envio por WhatsApp.',
  artifactMissing: 'Este documento não está disponível para a competência.'
} as const

/**
 * A mesma janela do backend (`garantirTelefone`): BR-plausível, 10 a 13
 * dígitos depois de tirar a máscara — DDD+fixo até E.164 com nono dígito.
 * A normalização fina (nono dígito, DDI) é do gateway, não nossa.
 */
function whatsappPhonePlausible(phone: string | null | undefined): 'missing' | 'invalid' | 'ok' {
  const digits = (phone ?? '').replace(/\D+/g, '')
  if (digits === '') return 'missing'
  if (digits.length < 10 || digits.length > 13) return 'invalid'
  return 'ok'
}

/**
 * O canal libera ou nomeia o próprio estado. `null` é "nunca pareou", que é
 * um bloqueio distinto de "desconectou" — a resposta ao operador muda.
 * `undefined` não chega aqui: a pendência é resolvida pelo chamador.
 */
function channelReason(status: WhatsAppChannelStatus | null): string | null {
  switch (status) {
    case 'connected': return null
    case 'pairing': return whatsappDispatchReasons.channelPairing
    case 'error': return whatsappDispatchReasons.channelError
    case null: return whatsappDispatchReasons.noChannel
    default: return whatsappDispatchReasons.channelDisconnected
  }
}

/**
 * Pode disparar? A resposta é o primeiro motivo que barra, na ordem que o
 * operador resolve: sem canal não adianta papel; sem papel não adianta
 * telefone; sem telefone não adianta documento.
 *
 * `undefined` em `channelStatus` ou `phone` é "ainda lendo": a elegibilidade
 * responde `pending` em vez de um motivo — afirmar "sem canal" ou "sem
 * telefone" sobre uma leitura em curso desenharia um bloqueio falso que a
 * resposta do GET desfaria um segundo depois. Um bloqueio concreto anterior
 * (canal `null`, canal desconectado, papel `user`) vence a pendência: o
 * operador resolve aquele primeiro, e a leitura restante não muda a resposta.
 */
export function whatsappDispatchEligibility(input: WhatsAppDispatchEligibilityInput): WhatsAppDispatchEligibility {
  const pending = () => ({ eligible: false, pending: true, reason: null })

  if (input.channelStatus === undefined) return pending()

  const channel = channelReason(input.channelStatus)
  if (channel !== null) return { eligible: false, pending: false, reason: channel }

  const allowed = input.role === 'admin' || input.role === 'operador' || input.role === 'super_admin'
  if (!allowed) return { eligible: false, pending: false, reason: whatsappDispatchReasons.roleNotAllowed }

  if (input.phone === undefined) return pending()

  const phone = whatsappPhonePlausible(input.phone)
  if (phone === 'missing') return { eligible: false, pending: false, reason: whatsappDispatchReasons.phoneMissing }
  if (phone === 'invalid') return { eligible: false, pending: false, reason: whatsappDispatchReasons.phoneInvalid }

  if (!input.artifactAvailable) return { eligible: false, pending: false, reason: whatsappDispatchReasons.artifactMissing }

  return { eligible: true, pending: false, reason: null }
}

/**
 * A apresentação dos cinco estados do Rastreio. A cor segue a semântica
 * Nuxt (The Status Is Semantic Rule): enfileirado = neutral (trabalho ainda
 * não concluído), enviado = info (saiu para o gateway), entregue = info
 * (confirmado no aparelho), lido = success (o desfecho que o escritório quer)
 * e falhou = error.
 */
export const whatsappDispatchStatusPresentation: Record<WhatsAppDispatchStatus, { label: string, color: 'neutral' | 'info' | 'warning' | 'success' | 'error', icon: string }> = {
  queued: { label: 'Enfileirado', color: 'neutral', icon: 'i-lucide-clock' },
  sent: { label: 'Enviado', color: 'info', icon: 'i-lucide-send' },
  delivered: { label: 'Entregue', color: 'info', icon: 'i-lucide-check-check' },
  read: { label: 'Lido', color: 'success', icon: 'i-lucide-mail-open' },
  failed: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-alert' }
}

/** Os nomes dos artefatos na UI — o mesmo substantivo em botão, toast e rastreio. */
export const whatsappArtifactLabels: Record<WhatsAppArtifact, string> = {
  guia: 'guia (DAS)',
  recibo: 'recibo',
  declaracao: 'declaração'
}

/**
 * A linha de Rastreio que a folha mostra para um artefato: o disparo mais
 * recente dele na célula (cliente × competência). A lista do GET já vem por
 * `id` desc, e uma competência pode ter mais de um disparo por dia — o
 * maior id é o que o webhook atualizou por último.
 */
export function whatsappLatestDispatch(dispatches: readonly WhatsAppDispatch[], artifact: WhatsAppArtifact): WhatsAppDispatch | null {
  return dispatches
    .filter(item => item.artifact_type === artifact)
    .reduce<WhatsAppDispatch | null>((latest, item) => (latest === null || item.id > latest.id ? item : latest), null)
}

/**
 * A nota que acompanha um `failed`: o reenvio da mesma guia ao mesmo
 * destino pede Ciência de reenvio (ou um recálculo que mude a guia) — a
 * idempotência é por guia lógica + contato, não por janela de tempo.
 */
export const whatsappDispatchFailedNote = 'O reenvio para o mesmo Contato de envio pede Ciência de reenvio ou uma guia recalculada.'

/**
 * O motivo neutro do botão de Disparo enquanto a elegibilidade ainda lê
 * canal ou telefone: o `pending` da eligibilidade pede esta frase em vez de
 * qualquer bloqueio concreto que a leitura pode desfazer um segundo depois.
 */
export const whatsappDispatchPendingReason = 'Verificando canal e telefone…'

/**
 * O selo do Rastreio na própria linha da folha: o estado do disparo mais
 * recente do cliente, no mesmo vocabulário e na mesma cor do rastreio do
 * detalhe — o selo é a porta de entrada, o modal é o detalhe. A contagem
 * fica fora do rótulo de propósito: "2 disparos" diz menos que o nome do
 * estado, e o detalhe já lista os envios.
 */
export function whatsappRowSummaryPresentation(summary: MonitoringWhatsAppSummary): { label: string, color: 'neutral' | 'info' | 'warning' | 'success' | 'error', icon: string } {
  return whatsappDispatchStatusPresentation[summary.latest_status]
}

/* ------------------------------------------------------------------ */
/* Enviar guias — guia corrente, pré-seleção e Ciência de reenvio        */
/* ------------------------------------------------------------------ */

/**
 * A guia corrente que o modal de Enviar guias cruzaria com o Rastreio:
 * o período mais recente com guia disponível — `slip_number` (DAS emitido)
 * ou declaração transmitida (o backend gera a guia na hora). O espelho do
 * `guiaAlvo` do `WhatsAppDispatchService`: o sort é por `period` desc e o
 * `slip_number` `null` é a guia sem número sincronizado — não "sem guia".
 */
export interface WhatsAppGuideTarget {
  /** `YYYY-MM` da competência da guia. */
  period: string
  slipNumber: string | null
}

export function whatsappGuideTarget(periods: readonly MonitoringAssessmentPeriod[] | null | undefined): WhatsAppGuideTarget | null {
  const linha = (periods ?? [])
    .filter(item => (item.slip_number ?? '') !== '' || item.declared_at !== null)
    .sort((a, b) => b.period.localeCompare(a.period))[0]

  if (!linha) return null

  return {
    period: linha.period,
    slipNumber: (linha.slip_number ?? '') !== '' ? linha.slip_number : null
  }
}

/**
 * A regra de reenvio no frontend, irmã do `guiaJaEnviada` do backend:
 * a mesma guia lógica (mesma competência **e** mesmo `slip_number`, `null`
 * inclusive) já saiu para este contato em estado que o gateway aceitou —
 * `queued`, `sent`, `delivered` ou `read`. `failed` não segura o contato:
 * a falha já está no Rastreio e a nova tentativa é legítima. Disparo de
 * outro artefato (o recibo/declaração legados) não é guia.
 */
export function whatsappGuideAlreadySent(
  dispatches: readonly WhatsAppDispatch[],
  contactId: number,
  target: WhatsAppGuideTarget | null
): boolean {
  if (target === null) return false
  return dispatches.some(item =>
    item.contact?.id === contactId
    && item.artifact_type === 'guia'
    && item.competence === target.period
    && item.slip_number === target.slipNumber
    && item.status !== 'failed'
  )
}

/**
 * Uma linha do modal Enviar guias: o contato, se ele pode receber o Disparo
 * nesta versão (`sendable`), se entra marcado (`checked`), se já recebeu a
 * guia corrente (`alreadySent` — habilita a Ciência de reenvio) e o motivo
 * do bloqueio quando não é enviável.
 */
export interface WhatsAppSendGuidesPlanItem {
  contact: ClientDispatchContact
  /** WhatsApp com telefone plausível — o único que dispara nesta versão. */
  sendable: boolean
  /** A pré-seleção: marcado quando é enviável e ainda não recebeu esta guia. */
  checked: boolean
  /** Já recebeu esta guia — desmarcado e com a Ciência de reenvio ao lado. */
  alreadySent: boolean
  /** O motivo legível quando `sendable` é falso; `null` nos enviáveis. */
  blockedReason: string | null
}

/** Os motivos do modal, uma frase por barreira — vocabulário do produto. */
export const whatsappSendGuidesReasons = {
  emailNotSendable: 'Contato de e-mail não recebe envio por WhatsApp.',
  phoneInvalid: 'O telefone do contato está fora do padrão para envio por WhatsApp.',
  noneChecked: 'Marque ao menos um Contato de envio WhatsApp para disparar.'
} as const

/**
 * O plano do Enviar guias: todos os Contatos de envio do cliente listados,
 * com a pré-seleção e os bloqueios já resolvidos — e-mail nunca selecionável,
 * telefone inválido idem, WhatsApp que já recebeu a guia corrente entra
 * desmarcado com a Ciência de reenvio disponível.
 */
export function whatsappSendGuidesPlan(input: {
  contacts: readonly ClientDispatchContact[]
  dispatches: readonly WhatsAppDispatch[]
  target: WhatsAppGuideTarget | null
}): WhatsAppSendGuidesPlanItem[] {
  return input.contacts.map((contact) => {
    if (contact.channel !== 'whatsapp') {
      return { contact, sendable: false, checked: false, alreadySent: false, blockedReason: whatsappSendGuidesReasons.emailNotSendable }
    }
    if (whatsappPhonePlausible(contact.value) !== 'ok') {
      return { contact, sendable: false, checked: false, alreadySent: false, blockedReason: whatsappSendGuidesReasons.phoneInvalid }
    }
    const alreadySent = whatsappGuideAlreadySent(input.dispatches, contact.id, input.target)
    return { contact, sendable: true, checked: !alreadySent, alreadySent, blockedReason: null }
  })
}

/**
 * O corpo do `send-guides`: `contact_ids` dos enviáveis marcados e
 * `resend_acknowledgements` dos que além de marcados têm a Ciência de
 * reenvio ligada. `null` quando nenhum WhatsApp está marcado — o botão
 * nem chama a API nesse caso, é a validação do modal.
 */
export function whatsappSendGuidesPayload(
  plan: readonly WhatsAppSendGuidesPlanItem[],
  resendAcknowledged: ReadonlySet<number>
): { contact_ids: number[], resend_acknowledgements: number[] } | null {
  const contactIds = plan
    .filter(item => item.checked && item.sendable)
    .map(item => item.contact.id)
  if (contactIds.length === 0) return null
  return {
    contact_ids: contactIds,
    resend_acknowledgements: contactIds.filter(id => resendAcknowledged.has(id))
  }
}

/* ------------------------------------------------------------------ */
/* Coluna Enviar e painel Rastreio de Envio                             */
/* ------------------------------------------------------------------ */

/**
 * As obrigações para as quais o Disparo por WhatsApp existe nesta versão:
 * só o PGDAS produz a guia que o `send-guides` envia (DAS por `slip_number`
 * ou guia gerada da declaração transmitida). O `monitoringDeclarationDetailSlugs`
 * responde pela lupa; esta lista responde pelo envio — são critérios
 * distintos e crescem separados.
 */
export const whatsappDispatchableObligationSlugs = ['declaracoes/pgdas'] as const

/**
 * A célula Enviar na folha servida: a obrigação serve Disparo (PGDAS) ou
 * não. Quando não serve, `reason` é o motivo legível do botão
 * desabilitado — a coluna fica visível em toda folha servida, e o tooltip
 * é o que diz por que esta obrigação não envia.
 */
export interface WhatsAppSheetSendAvailability {
  available: boolean
  reason: string | null
}

/** O motivo único da célula desabilitada — vocabulário do produto, não do endpoint. */
export const whatsappSheetSendUnavailableReason = 'Disparo por WhatsApp disponível apenas para PGDAS.'

export function whatsappSheetSendAvailability(
  obligation: Pick<MonitoringObligation, 'slug'>
): WhatsAppSheetSendAvailability {
  if ((whatsappDispatchableObligationSlugs as readonly string[]).includes(obligation.slug)) {
    return { available: true, reason: null }
  }
  return { available: false, reason: whatsappSheetSendUnavailableReason }
}

/**
 * A guia da linha que a coluna Rastreio de envio deixa baixar: a mesma que
 * o Enviar guias dispara (`whatsappGuideTarget`) — período mais recente
 * com `slip_number` ou declaração transmitida, que é a régua do download
 * PGDAS. `null` sem guia corrente: a seta fica desabilitada, não some.
 */
export function whatsappSheetGuideDownloadTarget(
  periods: readonly MonitoringAssessmentPeriod[] | null | undefined
): string | null {
  return whatsappGuideTarget(periods)?.period ?? null
}

/** As três abas do painel Rastreio de Envio, na ordem da referência. */
export type WhatsAppTrackingTab = 'envio' | 'visualizacao' | 'nao_enviados'

/** O grupo de cada status no painel: Envio (queued+sent+delivered), Visualização (read), Não enviados (failed). */
export const whatsappTrackingTabLabels: Record<WhatsAppTrackingTab, string> = {
  envio: 'Envio',
  visualizacao: 'Visualização',
  nao_enviados: 'Não enviados'
}

/** A frase fixa do escritório em cada cartão do painel — é ela que o contato lê. */
export const whatsappTrackingCardText = 'Envio de guias realizado por WhatsApp.'

const TRACKING_TAB_OF_STATUS: Record<WhatsAppDispatchStatus, WhatsAppTrackingTab> = {
  queued: 'envio',
  sent: 'envio',
  delivered: 'envio',
  read: 'visualizacao',
  failed: 'nao_enviados'
}

/**
 * A aba em que um Disparo cai no painel. Status ainda em trânsito
 * (`queued`, `sent`, `delivered`) ficam juntos em Envio — o escritório
 * lê "saiu" como um estado só; a leitura (`read`) e a falha (`failed`)
 * têm aba própria porque são desfechos.
 */
export function whatsappDispatchTrackingTab(dispatch: Pick<WhatsAppDispatch, 'status'>): WhatsAppTrackingTab {
  return TRACKING_TAB_OF_STATUS[dispatch.status]
}

/**
 * O agrupamento do painel: cada aba com os seus cartões, já na ordem da
 * lista da API (`id` desc — o mais recente primeiro). `user` lê o mesmo
 * conjunto; a diferença é o que ele não pode fazer, não o que ele vê.
 */
export function whatsappTrackingGroups(dispatches: readonly WhatsAppDispatch[]): Record<WhatsAppTrackingTab, WhatsAppDispatch[]> {
  const groups: Record<WhatsAppTrackingTab, WhatsAppDispatch[]> = {
    envio: [],
    visualizacao: [],
    nao_enviados: []
  }
  for (const dispatch of dispatches) {
    groups[whatsappDispatchTrackingTab(dispatch)].push(dispatch)
  }
  return groups
}

/** O destino do cartão: o telefone formatado do contato, ou o placeholder do contato removido/legado. */
export function whatsappDispatchDestinationLabel(dispatch: Pick<WhatsAppDispatch, 'contact'>): string {
  return dispatch.contact === null
    ? 'Contato de envio removido'
    : whatsappDispatchContactValueLabel(dispatch.contact)
}

/* ------------------------------------------------------------------ */
/* Contatos de envio — apresentação                                      */
/* ------------------------------------------------------------------ */

/**
 * O telefone do Contato de envio com a máscara de leitura: `(11) 98765-4321`,
 * `(11) 3344-5566`, `+55 (11) 98765-4321` quando já vem com DDI. Fora do
 * padrão conhecido o valor cru volta — a máscara não inventa formato.
 */
export function whatsappContactPhoneLabel(value: string): string {
  const digits = value.replace(/\D+/g, '')
  const br = digits.startsWith('55') && digits.length > 11 ? digits.slice(2) : digits
  const ddi = digits !== br ? '+55 ' : ''
  if (br.length === 11) return `${ddi}(${br.slice(0, 2)}) ${br.slice(2, 7)}-${br.slice(7)}`
  if (br.length === 10) return `${ddi}(${br.slice(0, 2)}) ${br.slice(2, 6)}-${br.slice(6)}`
  return value
}

/** O valor do contato na UI: telefone mascarado, e-mail cru. */
export function whatsappDispatchContactValueLabel(contact: Pick<WhatsAppDispatchContact, 'channel' | 'value'>): string {
  return contact.channel === 'whatsapp' ? whatsappContactPhoneLabel(contact.value) : contact.value
}

/**
 * O nome do contato numa linha: o rótulo quando existe, o valor formatado
 * senão — o backfill do `phone` semeia contatos sem rótulo e o número é o
 * que o escritório reconhece.
 */
export function whatsappDispatchContactName(contact: Pick<ClientDispatchContact, 'label' | 'channel' | 'value'>): string {
  const label = contact.label?.trim()
  return label ? label : whatsappDispatchContactValueLabel(contact)
}
