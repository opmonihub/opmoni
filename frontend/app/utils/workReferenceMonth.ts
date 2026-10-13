import { pad2, todayKey } from './workCalendar.ts'

export const REFERENCE_MONTH_RE = /^(\d{4})-(0[1-9]|1[0-2])$/

/** Work list routes that filter by competência (YYYY-MM). */
export const WORK_MONTH_SCOPED_PATHS = [
  '/work/clientes',
  '/work/processos',
  '/work/tarefas'
] as const

export type WorkMonthScopedPath = (typeof WORK_MONTH_SCOPED_PATHS)[number]

export function isWorkMonthScopedPath(path: string): boolean {
  return (WORK_MONTH_SCOPED_PATHS as readonly string[]).includes(path)
}

export function currentReferenceMonth(): string {
  return todayKey().slice(0, 7)
}

export function parseReferenceMonth(raw: unknown): string | null {
  return typeof raw === 'string' && REFERENCE_MONTH_RE.test(raw) ? raw : null
}

/** Portuguese month + year, e.g. "setembro 2026" (capitalize in UI). */
export function formatReferenceMonthLabel(ym: string, locale = 'pt-BR'): string {
  const match = REFERENCE_MONTH_RE.exec(ym)
  if (!match) return ym
  const year = Number(match[1])
  const month = Number(match[2])
  const date = new Date(year, month - 1, 1)
  const monthName = date.toLocaleDateString(locale, { month: 'long' })
  return `${monthName} ${year}`
}

export function shiftReferenceMonth(ym: string, delta: number): string {
  const match = REFERENCE_MONTH_RE.exec(ym)
  if (!match) return ym
  const year = Number(match[1])
  const month = Number(match[2])
  const date = new Date(year, month - 1 + delta, 1)
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}`
}

/** Short month labels Jan…Dez for the picker grid. */
export function referenceMonthShortLabels(locale = 'pt-BR'): string[] {
  return Array.from({ length: 12 }, (_, index) => {
    const label = new Date(2026, index, 1).toLocaleDateString(locale, { month: 'short' })
    return label.replace(/\.$/, '')
  })
}
