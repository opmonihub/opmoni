import { statusPresentation } from '../composables/useWorkPresentation.ts'
import type { WorkTask, WorkTaskStatus } from '../types/work.ts'

export type CalendarView = 'month' | 'week' | 'day'

export const MAX_VISIBLE_CHIPS = 3

export const statusOrder: Record<WorkTaskStatus, number> = {
  todo: 0,
  doing: 1,
  done: 2,
  dismissed: 3
}

export type TaskChipColor = 'info' | 'warning' | 'success' | 'neutral'

const statusDotClass: Record<WorkTaskStatus, string> = {
  todo: 'bg-info',
  doing: 'bg-warning',
  done: 'bg-success',
  dismissed: 'bg-muted'
}

export function calendarStatusPresentation(taskStatus: WorkTaskStatus): {
  label: string
  color: TaskChipColor
  dotClass: string
} {
  const presentation = statusPresentation(taskStatus)
  return {
    label: presentation.label,
    color: presentation.color,
    dotClass: statusDotClass[taskStatus]
  }
}

/** Ported from nuxt-ui-templates/calendar `utils/calendars.ts` — status → chip fill. */
export const taskChipBlockClasses: Record<TaskChipColor, string> = {
  info: 'bg-info/15 hover:bg-info/25 data-active:bg-info/25 text-info border-info',
  warning: 'bg-warning/15 hover:bg-warning/25 data-active:bg-warning/25 text-warning border-warning',
  success: 'bg-success/15 hover:bg-success/25 data-active:bg-success/25 text-success border-success',
  neutral: 'bg-muted/15 hover:bg-muted/25 data-active:bg-muted/25 text-muted border-muted'
}

export const taskChipCompactClasses: Record<TaskChipColor, string> = {
  info: 'max-lg:bg-info/15 max-lg:text-info',
  warning: 'max-lg:bg-warning/15 max-lg:text-warning',
  success: 'max-lg:bg-success/15 max-lg:text-success',
  neutral: 'max-lg:bg-muted/15 max-lg:text-muted'
}

export const taskChipOutlineClasses: Record<TaskChipColor, string> = {
  info: 'outline-info/25',
  warning: 'outline-warning/25',
  success: 'outline-success/25',
  neutral: 'outline-inverted/25'
}

export function formatWeekdayShort(key: string, locale = 'pt-BR') {
  const parsed = parseDateKey(key)
  if (!parsed) return ''
  return new Date(parsed.year, parsed.month - 1, parsed.day).toLocaleDateString(locale, { weekday: 'short' })
}

export function pad2(n: number) {
  return String(n).padStart(2, '0')
}

export function toDateKey(year: number, month: number, day: number) {
  return `${year}-${pad2(month)}-${pad2(day)}`
}

export function parseDateKey(key: string): { year: number, month: number, day: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key)
  if (!match) return null
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return null
  return { year, month, day }
}

/**
 * Product calendar timezone (Brazil). Must not depend on process/browser
 * `resolvedOptions().timeZone` — Docker SSR is often UTC while clients are
 * America/Sao_Paulo, which flips "today" across midnight and breaks hydration.
 */
export const APP_CALENDAR_TIMEZONE = 'America/Sao_Paulo'

export function todayKey(timeZone = APP_CALENDAR_TIMEZONE) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  }).formatToParts(new Date())
  const year = Number(parts.find(p => p.type === 'year')?.value)
  const month = Number(parts.find(p => p.type === 'month')?.value)
  const day = Number(parts.find(p => p.type === 'day')?.value)
  return toDateKey(year, month, day)
}

/** Compare a YYYY-MM-DD key to "today" in {@link APP_CALENDAR_TIMEZONE}. */
export function isCalendarToday(key: string, today = todayKey()) {
  return key === today
}

export function firstDayOfMonth(year: number, month: number) {
  return toDateKey(year, month, 1)
}

export function lastDayOfMonth(year: number, month: number) {
  const last = new Date(year, month, 0).getDate()
  return toDateKey(year, month, last)
}

export function addDays(key: string, delta: number): string {
  const parsed = parseDateKey(key)
  if (!parsed) return key
  const date = new Date(parsed.year, parsed.month - 1, parsed.day + delta)
  return toDateKey(date.getFullYear(), date.getMonth() + 1, date.getDate())
}

export function startOfWeekMonday(key: string): string {
  const parsed = parseDateKey(key)
  if (!parsed) return key
  const date = new Date(parsed.year, parsed.month - 1, parsed.day)
  const day = date.getDay() // 0 Sun
  const offset = day === 0 ? -6 : 1 - day
  date.setDate(date.getDate() + offset)
  return toDateKey(date.getFullYear(), date.getMonth() + 1, date.getDate())
}

export function monthLabel(key: string) {
  const parsed = parseDateKey(key)
  if (!parsed) return 'Calendário'
  return new Date(parsed.year, parsed.month - 1, 1).toLocaleDateString('pt-BR', {
    month: 'long',
    year: 'numeric'
  })
}

export function sortTasks(tasks: WorkTask[]) {
  return [...tasks].sort((a, b) => {
    const statusDiff = statusOrder[a.status] - statusOrder[b.status]
    if (statusDiff !== 0) return statusDiff
    return a.title.localeCompare(b.title, 'pt-BR')
  })
}

export function groupTasksByDay(tasks: WorkTask[]) {
  const map = new Map<string, WorkTask[]>()
  for (const task of tasks) {
    if (!task.due_on) continue
    const list = map.get(task.due_on) ?? []
    list.push(task)
    map.set(task.due_on, list)
  }
  for (const [key, list] of map) {
    map.set(key, sortTasks(list))
  }
  return map
}

export interface MonthCell {
  key: string
  day: number
  inMonth: boolean
  isToday: boolean
}

/** Fixed 6×7 grid Mon–Sun covering the month of `anchorKey`. */
export function buildMonthGrid(anchorKey: string, today = todayKey()): MonthCell[] {
  const parsed = parseDateKey(anchorKey)
  if (!parsed) return []
  const first = firstDayOfMonth(parsed.year, parsed.month)
  const gridStart = startOfWeekMonday(first)
  const cells: MonthCell[] = []
  for (let i = 0; i < 42; i++) {
    const key = addDays(gridStart, i)
    const cell = parseDateKey(key)!
    cells.push({
      key,
      day: cell.day,
      inMonth: cell.month === parsed.month && cell.year === parsed.year,
      isToday: key === today
    })
  }
  return cells
}

export function weekKeys(anchorKey: string): string[] {
  const start = startOfWeekMonday(anchorKey)
  return Array.from({ length: 7 }, (_, i) => addDays(start, i))
}

export function periodRange(view: CalendarView, dateKey: string): { from: string, to: string } {
  const parsed = parseDateKey(dateKey) ?? parseDateKey(todayKey())!
  if (view === 'day') {
    return { from: dateKey, to: dateKey }
  }
  if (view === 'week') {
    const start = startOfWeekMonday(dateKey)
    return { from: start, to: addDays(start, 6) }
  }
  return {
    from: firstDayOfMonth(parsed.year, parsed.month),
    to: lastDayOfMonth(parsed.year, parsed.month)
  }
}

export function shiftPeriod(view: CalendarView, dateKey: string, delta: -1 | 1): string {
  const parsed = parseDateKey(dateKey) ?? parseDateKey(todayKey())!
  if (view === 'day') return addDays(dateKey, delta)
  if (view === 'week') return addDays(dateKey, delta * 7)
  const date = new Date(parsed.year, parsed.month - 1 + delta, 1)
  return toDateKey(date.getFullYear(), date.getMonth() + 1, 1)
}

export function parseCalendarQuery(viewRaw: unknown, dateRaw: unknown): { view: CalendarView, date: string } {
  const view = viewRaw === 'week' || viewRaw === 'day' || viewRaw === 'month' ? viewRaw : 'month'
  const date = typeof dateRaw === 'string' && parseDateKey(dateRaw) ? dateRaw : todayKey()
  return { view, date }
}
