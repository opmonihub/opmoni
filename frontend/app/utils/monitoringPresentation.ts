import type {
  AttentionReasonCode,
  MonitoringAssessmentPeriod,
  MonitoringCounter,
  MonitoringObligationSummary,
  MonitoringSituacao,
  MonitoringSlipStatus,
  ObligationCategory,
  SerproAuthorizationTermState,
  SerproRunItemState,
  SerproSyncRunState
} from '~/types/serpro'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'error'

/** The counter row's own label — the fifth reading, which is not a state. */
export const monitoringTotalLabel = 'Total'

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
  processando: { label: 'Processando', color: 'info', icon: 'i-lucide-arrow-repeat' },
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

export const serproRunStatePresentation: Record<SerproSyncRunState, { label: string, color: Tone, icon: string }> = {
  queued: { label: 'Na fila', color: 'neutral', icon: 'i-lucide-clock' },
  running: { label: 'Em execução', color: 'info', icon: 'i-lucide-arrow-repeat' },
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
  pendente: { label: 'Em validação', color: 'info', icon: 'i-lucide-clock' },
  validado: { label: 'Validado', color: 'success', icon: 'i-lucide-circle-check' },
  autenticado: { label: 'Autenticado', color: 'success', icon: 'i-lucide-shield-check' },
  vencido: { label: 'Vencido', color: 'error', icon: 'i-lucide-circle-alert' },
  recusado: { label: 'Recusado', color: 'error', icon: 'i-lucide-ban' }
}

/**
 * What the office is expected to do about a term in each state. The wording
 * lives here rather than in a template because it is state-conditional and a
 * single sentence that is true for a valid term is false for an expired one:
 * `tasks.md` 4.11 makes renewal the office's own action when the term lapses,
 * so a page that says "nothing to do" above an expired badge is telling the
 * office something false about its own position.
 */
export const serproTermGuidance: Record<SerproAuthorizationTermState, string> = {
  ausente: 'O termo é emitido automaticamente assim que o escritório tiver um certificado válido.',
  pendente: 'A validação está em curso na plataforma. Não há nada a fazer.',
  validado: 'A renovação é feita pela plataforma, sem nenhuma ação sua.',
  autenticado: 'A renovação é feita pela plataforma, sem nenhuma ação sua.',
  vencido: 'O termo venceu. A renovação é do escritório e depende de um certificado válido — a plataforma não a faz sozinha.',
  recusado: 'O provedor recusou o termo. O escritório precisa emitir um novo termo a partir de um certificado válido.'
}

/** The total is the sum of the four; `encerrado` is deliberately not in it. */
export function monitoringCountersTotal(summary: Pick<MonitoringObligationSummary, 'em_dia' | 'processando' | 'pendencias' | 'atencao'>) {
  return summary.em_dia + summary.processando + summary.pendencias + summary.atencao
}

export function formatMonitoringCount(value: number) {
  return new Intl.NumberFormat('pt-BR').format(value)
}

export function formatMonitoringDate(value: string | null | undefined) {
  if (!value) return '—'
  const [date] = value.split('T')
  const [year, month, day] = (date ?? '').split('-')
  if (!year || !month || !day) return '—'
  return `${day}/${month}/${year}`
}

export function formatMonitoringDueOn(value: string | null | undefined) {
  return formatMonitoringDate(value)
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
