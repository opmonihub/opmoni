import { differenceInCalendarDays } from 'date-fns'

/** Ported from nuxt-ui-templates/calendar `app/utils/layout.ts`. */
export interface LayoutEvent {
  id: string
  start: string
  end: string
  allDay?: boolean
}

export interface AllDayPositionedEvent<T extends LayoutEvent = LayoutEvent> {
  event: T
  colStart: number
  colSpan: number
  lane: number
}

export function layoutAllDay<T extends LayoutEvent>(events: T[], days: Date[]): AllDayPositionedEvent<T>[] {
  const first = days[0]
  if (!first) {
    return []
  }

  const items = events
    .map((event) => {
      const colStart = Math.max(0, differenceInCalendarDays(new Date(event.start), first))
      const colEnd = Math.min(days.length, differenceInCalendarDays(new Date(event.end), first))

      return { event, colStart, colSpan: colEnd - colStart }
    })
    .filter(item => item.colSpan > 0)
    .sort((a, b) => a.colStart - b.colStart || b.colSpan - a.colSpan)

  const lanes: number[] = []

  return items.map((item) => {
    let lane = lanes.findIndex(end => end <= item.colStart)
    if (lane === -1) {
      lane = lanes.length
    }
    lanes[lane] = item.colStart + item.colSpan

    return { ...item, lane }
  })
}
