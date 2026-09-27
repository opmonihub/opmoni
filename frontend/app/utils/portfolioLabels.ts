import type { ClientPersonType, ClientStatus, DeadlineStatus, TaxRegime } from '~/types/client'
import { formatTaxId, maskTaxId } from './taxId.ts'

export const taxRegimeLabel: Record<TaxRegime, string> = {
  mei: 'MEI',
  simple_national: 'Simples Nacional',
  presumed_profit: 'Lucro presumido',
  actual_profit: 'Lucro real',
  other: 'Outro',
  not_applicable: 'Não se aplica'
}

export const clientStatusLabel: Record<ClientStatus, string> = {
  active: 'Ativo',
  inactive: 'Inativo'
}

export const clientStatusPresentation: Record<ClientStatus, {
  label: string
  color: 'success' | 'neutral'
  icon: string
}> = {
  active: { label: clientStatusLabel.active, color: 'success', icon: 'i-lucide-circle-check' },
  inactive: { label: clientStatusLabel.inactive, color: 'neutral', icon: 'i-lucide-circle-pause' }
}

export function clientRegimeText(regime: TaxRegime | string | null | undefined): string {
  if (!regime) return '—'
  return taxRegimeLabel[regime as TaxRegime] ?? regime
}

/**
 * Sheet cell for the CPF/CNPJ column.
 *
 * `person_type` null (or anything but `individual`) formats the raw document:
 * `formatTaxId` picks CPF vs CNPJ by length, so an unknown person type still
 * reads correctly. Only an explicit `individual` gets the masked CPF — masking
 * a CNPJ would leak nothing useful, but masking a real CPF by accident would
 * hide data the accountant needs.
 */
export function clientSheetTaxIdLabel(client: { tax_id: string | null, person_type: ClientPersonType | null }): string {
  if (!client.tax_id) return 'Não informado'
  return client.person_type === 'individual' ? maskTaxId(client.tax_id) : formatTaxId(client.tax_id)
}

export const deadlineStatusLabel: Record<DeadlineStatus, string> = {
  missing: 'Sem cadastro',
  valid: 'Válido',
  expiring: 'A vencer',
  expired: 'Vencido'
}

export const deadlineStatusAppearance: Record<DeadlineStatus, {
  color: 'neutral' | 'success' | 'warning' | 'error'
  icon: string
  iconClass: string
}> = {
  missing: { color: 'neutral', icon: 'i-lucide-circle-minus', iconClass: 'text-muted' },
  valid: { color: 'success', icon: 'i-lucide-circle-check', iconClass: 'text-success' },
  expiring: { color: 'warning', icon: 'i-lucide-clock-alert', iconClass: 'text-warning' },
  expired: { color: 'error', icon: 'i-lucide-circle-alert', iconClass: 'text-error' }
}

export const deadlinePresentation: Record<DeadlineStatus, {
  label: string
  color: 'neutral' | 'success' | 'warning' | 'error'
  icon: string
}> = {
  missing: { label: deadlineStatusLabel.missing, color: deadlineStatusAppearance.missing.color, icon: deadlineStatusAppearance.missing.icon },
  valid: { label: deadlineStatusLabel.valid, color: deadlineStatusAppearance.valid.color, icon: deadlineStatusAppearance.valid.icon },
  expiring: { label: deadlineStatusLabel.expiring, color: deadlineStatusAppearance.expiring.color, icon: deadlineStatusAppearance.expiring.icon },
  expired: { label: deadlineStatusLabel.expired, color: deadlineStatusAppearance.expired.color, icon: deadlineStatusAppearance.expired.icon }
}

const deadlineBadgeLabel = deadlineStatusLabel

export function deadlineBadgePresentation(status: DeadlineStatus, formattedDate?: string | null) {
  const { color, icon } = deadlineStatusAppearance[status]
  if (status === 'missing' || !formattedDate) {
    return { color, icon, label: deadlineBadgeLabel[status], title: undefined }
  }

  return {
    color,
    icon,
    label: formattedDate,
    title: `${deadlineBadgeLabel[status]} até ${formattedDate}`
  }
}

export function formatPtCount(value: number) {
  return new Intl.NumberFormat('pt-BR').format(value)
}
