import type { WorkTaskPriority } from '~/types/work'

export interface CalendarFilterSelection {
  processId: number | null
  clientId: number | null
  assigneeId: number | null
  departmentId: number | null
  priority: string
}

export const calendarPriorityOptions: { label: string, value: WorkTaskPriority }[] = [
  { label: 'Baixa', value: 'low' },
  { label: 'Média', value: 'medium' },
  { label: 'Alta', value: 'high' },
  { label: 'Urgente', value: 'urgent' }
]

export function countActiveCalendarFilters(selection: CalendarFilterSelection) {
  return [
    selection.processId,
    selection.clientId,
    selection.assigneeId,
    selection.departmentId,
    selection.priority
  ].filter(value => value !== null && value !== undefined && value !== '').length
}

export function accessibleDateLabel(dateKey: string, locale = 'pt-BR') {
  const date = new Date(`${dateKey}T00:00:00`)
  if (Number.isNaN(date.getTime())) return dateKey

  return new Intl.DateTimeFormat(locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric'
  }).format(date)
}

export function monthTitleParts(key: string, locale = 'pt-BR'): { months: string, year: string } {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key)
  if (!match) return { months: 'Calendário', year: '' }
  const year = Number(match[1])
  const month = Number(match[2])
  const date = new Date(year, month - 1, 1)
  return {
    months: date.toLocaleDateString(locale, { month: 'long' }),
    year: String(year)
  }
}
