import { addDays as addDaysFns, lightFormat } from 'date-fns'
import { addDays, parseDateKey } from './workCalendar.ts'

/** Ported helpers from nuxt-ui-templates/calendar `app/utils/dates.ts` (pt-BR). */

export function isoDate(date: Date): string {
  return lightFormat(date, 'yyyy-MM-dd')
}

export function dateFromKey(key: string): Date {
  const parsed = parseDateKey(key)
  if (!parsed) return new Date(NaN)
  return new Date(parsed.year, parsed.month - 1, parsed.day)
}

export function eachDayOfKeys(keys: string[]): Date[] {
  return keys.map(dateFromKey).filter(date => !Number.isNaN(date.getTime()))
}

export function eachDay(start: Date, endExclusive: Date): Date[] {
  const days: Date[] = []
  for (let day = start; day < endExclusive; day = addDaysFns(day, 1)) {
    days.push(day)
  }
  return days
}

const weekdayFormat = new Intl.DateTimeFormat('pt-BR', { weekday: 'short' })
const shortMonthFormat = new Intl.DateTimeFormat('pt-BR', { month: 'short' })

export function formatWeekday(date: Date): string {
  return weekdayFormat.format(date).replace(/\.$/, '')
}

export function formatShortMonth(date: Date): string {
  return shortMonthFormat.format(date).replace(/\.$/, '')
}

/** Work tasks are date-only → exclusive next-day end, same as template all-day. */
export function taskDayBounds(dueOn: string): { start: string, end: string } {
  return {
    start: `${dueOn}T00:00:00`,
    end: `${addDays(dueOn, 1)}T00:00:00`
  }
}
