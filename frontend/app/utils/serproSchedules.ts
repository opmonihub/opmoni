import type { SerproObligationScheduleRow, SerproScheduleMap } from '~/types/serpro'

/**
 * The scheduled-search day per document, in the settings form's words.
 *
 * `node --test` cannot import an SFC, so the conversion of the typed day into
 * the wire value — and the refusal of everything that is not 1–28 or empty —
 * lives here, where a test can hold it against the contract.
 */

export const scheduleDayMin = 1
/** 28, not 29–31: every month has a 28th, so no scheduled month is skipped. */
export const scheduleDayMax = 28

export type ScheduleDayParse = { ok: true, day: number | null } | { ok: false }

/**
 * The typed entry as the wire value: empty means "sem agendamento" (`null`),
 * an integer 1–28 is the day, and anything else is a refusal the form has to
 * name — not silently clamp, or the office would save a day it never chose.
 */
export function parseScheduleDay(value: string | number | null | undefined): ScheduleDayParse {
  if (value === null || value === undefined || value === '') return { ok: true, day: null }
  const day = typeof value === 'number' ? value : Number(value)
  if (!Number.isInteger(day) || day < scheduleDayMin || day > scheduleDayMax) return { ok: false }
  return { ok: true, day }
}

/**
 * The whole form as the PUT payload, marking the lines that would not convert.
 * A missing key stays missing: the endpoint treats absence as "no scheduled
 * search" for that document, and sending `null` for every untouched line would
 * claim the operator cleared them.
 */
export function schedulePayloadFromInputs(inputs: Record<string, string | number | null | undefined>): {
  schedules: SerproScheduleMap
  invalid: string[]
} {
  const schedules: SerproScheduleMap = {}
  const invalid: string[] = []
  for (const [obligation, value] of Object.entries(inputs)) {
    const parsed = parseScheduleDay(value)
    if (!parsed.ok) {
      invalid.push(obligation)
      continue
    }
    if (parsed.day === null) continue
    schedules[obligation] = parsed.day
  }
  return { schedules, invalid }
}

/**
 * The wire shape the backend speaks: a **list** of `{obligation, day}` rows,
 * only for the documents that have a scheduled day — absence from the list is
 * how "no automatic search for this document" travels, in both directions.
 * These two translate between that list and the map the settings form reads.
 */
export function scheduleMapFromRows(rows: SerproObligationScheduleRow[]): SerproScheduleMap {
  const map: SerproScheduleMap = {}
  for (const row of rows) {
    map[row.obligation] = row.day
  }
  return map
}

export function scheduleRowsFromMap(map: SerproScheduleMap): SerproObligationScheduleRow[] {
  return Object.entries(map)
    .filter(([, day]) => day != null)
    .map(([obligation, day]) => ({ obligation, day: Number(day) }))
}
