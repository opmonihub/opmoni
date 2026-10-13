import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { layoutAllDay } from '../app/utils/calendarLayout.ts'
import { taskDayBounds } from '../app/utils/calendarDates.ts'
import { lightFormat } from 'date-fns'

function isoDate(date: Date): string {
  return lightFormat(date, 'yyyy-MM-dd')
}

describe('calendar layout (template port)', () => {
  it('maps a due_on day to exclusive all-day bounds', () => {
    assert.deepEqual(taskDayBounds('2026-09-25'), {
      start: '2026-09-25T00:00:00',
      end: '2026-09-26T00:00:00'
    })
  })

  it('packs single-day all-day events into one column each', () => {
    const monday = new Date(2026, 8, 21)
    const days = Array.from({ length: 7 }, (_, i) => new Date(2026, 8, 21 + i))
    const fridayKey = isoDate(new Date(2026, 8, 25))
    const bounds = taskDayBounds(fridayKey)

    const placed = layoutAllDay([{
      id: '1',
      start: bounds.start,
      end: bounds.end,
      allDay: true
    }], days)

    assert.equal(placed.length, 1)
    assert.equal(placed[0]!.colStart, 4)
    assert.equal(placed[0]!.colSpan, 1)
    assert.equal(placed[0]!.lane, 0)
    assert.equal(isoDate(monday), '2026-09-21')
  })
})
