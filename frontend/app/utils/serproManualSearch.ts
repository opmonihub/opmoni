import type { SerproManualSearchMode } from '~/types/serpro'

/**
 * The manual search's own wording, in one place for the same reason as
 * `monitoringPresentation`: `node --test` cannot import an SFC, and a sentence
 * written inside a `.vue` template is a sentence no consistency check can read.
 */

/** The two search shapes, as the operator reads them in the modal. */
export const manualSearchModePresentation: Record<SerproManualSearchMode, { label: string, description: string }> = {
  full: {
    label: 'Busca completa',
    description: 'Baixa todas as leituras que o documento publica.'
  },
  slip_status: {
    label: 'Só status da guia',
    description: 'Consulta apenas se a guia está paga, sem baixar os demais campos.'
  }
}

/**
 * The quota column's caption. `null` — never `0 de 10` — when the quota did not
 * arrive: a zero would claim the client spent nothing this month, which is a
 * claim about the data the endpoint never made.
 */
export function manualSearchQuotaLabel(used: number | null | undefined, limit: number | null | undefined): string | null {
  if (used == null || limit == null || limit <= 0) return null
  return `${used} de ${limit}`
}

/**
 * The bar's colour, from how much of the month is left rather than from the
 * raw fraction: the office reads "can I still search?" — one query left is a
 * warning, none left is an error, anything else is ordinary use.
 */
export function manualSearchQuotaColor(used: number, limit: number): 'primary' | 'warning' | 'error' {
  if (limit > 0 && used >= limit) return 'error'
  if (limit - used <= 2) return 'warning'
  return 'primary'
}

/**
 * The clients the backend refused, from a quota `422`.
 *
 * The refusal names each exceeded client and what is left of its quota, and it
 * can arrive one message per client under one field (`client_ids`), spread over
 * indexed fields (`client_ids.0`, …) or as the top-level `message` — every one
 * of those shapes is a list the modal has to show, so the extraction flattens
 * whatever the validator produced instead of trusting one key.
 */
export function quotaExceededMessages(e: unknown): string[] {
  const record = typeof e === 'object' && e !== null ? (e as Record<string, unknown>) : null
  const data = typeof record?.data === 'object' && record.data !== null ? (record.data as Record<string, unknown>) : null
  const errors = data?.errors
  if (typeof errors === 'object' && errors !== null) {
    const messages = Object.values(errors as Record<string, unknown>)
      .flatMap(value => (Array.isArray(value) ? value : [value]))
      .filter((value): value is string => typeof value === 'string' && value.length > 0)
    if (messages.length) return messages
  }
  const message = record?.message ?? data?.message
  return typeof message === 'string' && message.length > 0 ? [message] : []
}
