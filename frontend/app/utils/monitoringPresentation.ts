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

/** The counter row's own label — the fifth reading, which is not a state. */
export const monitoringTotalLabel = 'Total'

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
