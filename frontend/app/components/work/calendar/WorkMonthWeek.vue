<script setup lang="ts">
/**
 * Ported from nuxt-ui-templates/calendar `components/calendar/MonthWeek.vue`.
 * Work tasks are date-only → all-day bars (layoutAllDay), same as template.
 */
import { addDays } from 'date-fns'
import type { WorkTask } from '~/types/work'
import { layoutAllDay, type AllDayPositionedEvent } from '~/utils/calendarLayout'
import { formatShortMonth, isoDate, taskDayBounds } from '~/utils/calendarDates'
import { accessibleDateLabel } from '~/utils/calendarUi'
import { isCalendarToday } from '~/utils/workCalendar'

const SLOT_HEIGHT = 22
const MAX_SLOTS = 4
const MAX_LANES = MAX_SLOTS - 1

interface WorkAllDayEvent {
  id: string
  start: string
  end: string
  allDay: true
  task: WorkTask
}

const props = defineProps<{
  weekStart: Date
  tasksByDay: Map<string, WorkTask[]>
  loading?: boolean
  canDrag?: boolean
  busyId?: number | null
}>()

const emit = defineEmits<{
  'select': [task: WorkTask, event: MouseEvent]
  'select-date': [dateKey: string]
  'drop': [taskId: number, targetDay: string]
}>()

const days = computed(() => Array.from({ length: 7 }, (_, index) => addDays(props.weekStart, index)))

const gridStyle = {
  gridTemplateRows: ['auto', ...Array.from({ length: MAX_LANES }, () => `${SLOT_HEIGHT}px`), `minmax(${SLOT_HEIGHT}px, 1fr)`].join(' ')
}

const weekEvents = computed((): WorkAllDayEvent[] => {
  const events: WorkAllDayEvent[] = []
  for (const day of days.value) {
    const key = isoDate(day)
    for (const task of props.tasksByDay.get(key) ?? []) {
      const bounds = taskDayBounds(key)
      events.push({
        id: String(task.id),
        start: bounds.start,
        end: bounds.end,
        allDay: true,
        task
      })
    }
  }
  return events
})

const lanes = computed(() => layoutAllDay(weekEvents.value, days.value))

const placed = computed(() => lanes.value)

const bars = computed(() => placed.value.filter(bar => bar.lane < MAX_LANES))

function covers(bar: AllDayPositionedEvent<WorkAllDayEvent>, index: number): boolean {
  return index >= bar.colStart && index < bar.colStart + bar.colSpan
}

const cells = computed(() => {
  return days.value.map((day, index) => {
    const covering = placed.value.filter(bar => covers(bar, index))
    const occupied = new Set(covering.filter(bar => bar.lane < MAX_LANES).map(bar => bar.lane))
    const dropped = covering.filter(bar => bar.lane >= MAX_LANES)

    const free = Array.from({ length: MAX_SLOTS }, (_, slot) => slot).filter(slot => !occupied.has(slot))
    const overflows = dropped.length > 0
    const slot = free[0]

    return {
      day,
      more: overflows && slot !== undefined
        ? {
            slot,
            hidden: dropped.length,
            events: covering.map(bar => bar.event.task)
          }
        : null
    }
  })
})

const SKELETONS: [number, number][] = [[0, 0], [1, 0], [1, 1], [3, 0], [4, 0], [4, 1], [6, 0]]

const showLoading = computed(() => !!props.loading
  && !bars.value.length
  && cells.value.every(cell => !cell.more))

function label(day: Date): string {
  if (day.getDate() === 1) {
    return `${formatShortMonth(day)} 1`
  }
  return String(day.getDate())
}

function onDragStart(event: DragEvent, task: WorkTask) {
  if (!props.canDrag || task.status === 'dismissed' || props.busyId === task.id) {
    event.preventDefault()
    return
  }
  event.dataTransfer?.setData('text/task-id', String(task.id))
  event.dataTransfer!.effectAllowed = 'move'
}

function onDrop(event: DragEvent, dayKey: string) {
  event.preventDefault()
  const raw = event.dataTransfer?.getData('text/task-id')
  const id = raw ? Number(raw) : NaN
  if (!Number.isFinite(id)) return
  emit('drop', id, dayKey)
}

function allowDrop(event: DragEvent) {
  if (!props.canDrag) return
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
}
</script>

<template>
  <div
    class="grid grid-cols-7 min-w-0 border-b border-default"
    :style="gridStyle"
  >
    <div
      v-for="({ day }, index) in cells"
      :key="`day-${day.getTime()}`"
      :data-date="isoDate(day)"
      class="row-span-full border-default"
      :class="index !== 0 && 'border-s'"
      :style="{ gridColumn: index + 1 }"
      @dragover="allowDrop"
      @drop="onDrop($event, isoDate(day))"
    />

    <button
      v-for="({ day }, index) in cells"
      :key="`number-${day.getTime()}`"
      type="button"
      class="select-none row-start-1 justify-self-end inline-flex items-center justify-center h-6 min-w-6 m-0.5 px-1 py-1 text-xs font-semibold rounded-full transition-colors focus-visible:outline-3"
      :class="isCalendarToday(isoDate(day))
        ? 'text-inverted bg-primary active:bg-primary/75 outline-primary/25'
        : 'text-default hover:bg-(--control-bg) active:bg-(--control-bg) outline-inverted/25'"
      :style="{ gridColumn: index + 1 }"
      :aria-label="`Ir para ${accessibleDateLabel(isoDate(day))}`"
      :aria-current="isCalendarToday(isoDate(day)) ? 'date' : undefined"
      @click="emit('select-date', isoDate(day))"
    >
      {{ label(day) }}
    </button>

    <USkeleton
      v-for="[day, slot] in showLoading ? SKELETONS : []"
      :key="`skeleton-${day}-${slot}`"
      class="self-start mx-0.5 h-5 rounded-full"
      :style="{ gridColumn: day + 1, gridRow: slot + 2 }"
    />

    <WorkCalendarTaskChip
      v-for="{ event, colStart, colSpan, lane } in bars"
      :key="event.id"
      :task="event.task"
      :day-key="isoDate(days[colStart]!)"
      all-day
      :can-drag="canDrag"
      :busy="busyId === event.task.id"
      class="self-start mx-0.5"
      :style="{ gridColumn: `${colStart + 1} / span ${colSpan}`, gridRow: lane + 2 }"
      @select="(task, mouseEvent) => emit('select', task, mouseEvent)"
      @drag-start="onDragStart"
    />

    <template
      v-for="({ day, more }, index) in cells"
      :key="`more-${day.getTime()}`"
    >
      <UPopover
        v-if="more"
        :content="{ side: 'right' }"
        :ui="{ content: 'flex max-h-64 w-64 flex-col gap-0.5 overflow-y-auto p-2' }"
      >
        <template #default="{ open }">
          <UButton
            color="neutral"
            variant="ghost"
            size="xs"
            class="self-start mx-0.5 px-1.5 py-0.5 justify-start text-muted font-normal"
            :class="open && 'bg-elevated'"
            :style="{ gridColumn: index + 1, gridRow: more.slot + 2 }"
            :aria-label="`Mostrar mais ${more.hidden} tarefas em ${accessibleDateLabel(isoDate(day))}`"
          >
            <span class="lg:hidden">+{{ more.hidden }}</span>
            <span class="hidden lg:inline">+{{ more.hidden }} mais</span>
          </UButton>
        </template>

        <template #content>
          <WorkCalendarTaskChip
            v-for="task in more.events"
            :key="task.id"
            :task="task"
            :day-key="isoDate(day)"
            all-day
            @select="(selected, mouseEvent) => emit('select', selected, mouseEvent)"
          />
        </template>
      </UPopover>
    </template>
  </div>
</template>
