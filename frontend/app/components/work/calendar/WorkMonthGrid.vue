<script setup lang="ts">
/**
 * Ported from nuxt-ui-templates/calendar `components/calendar/MonthView.vue`
 * chrome + week rows (single-month slice — Work API fetches the visible month).
 */
import { addDays, addWeeks, startOfWeek } from 'date-fns'
import type { WorkTask } from '~/types/work'
import { formatWeekday, isoDate } from '~/utils/calendarDates'
import { accessibleDateLabel } from '~/utils/calendarUi'
import { parseDateKey } from '~/utils/workCalendar'

const ROW_HEIGHT = 140
const HEADER_PADDING = 8
const HEADER_HEIGHT = 64
const WEEKDAY_HEIGHT = 40
const CHROME_HEIGHT = HEADER_PADDING + HEADER_HEIGHT + WEEKDAY_HEIGHT

const props = defineProps<{
  anchorDate: string
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

const monthStart = computed(() => {
  const parsed = parseDateKey(props.anchorDate)
  if (!parsed) return startOfWeek(new Date(), { weekStartsOn: 1 })
  return startOfWeek(new Date(parsed.year, parsed.month - 1, 1), { weekStartsOn: 1 })
})

const weeks = computed(() => Array.from({ length: 6 }, (_, index) => addWeeks(monthStart.value, index)))

const weekdays = computed(() =>
  Array.from({ length: 7 }, (_, index) => formatWeekday(addDays(monthStart.value, index)))
)
</script>

<template>
  <div class="relative flex-1 flex flex-col min-h-0">
    <table v-if="!loading" class="sr-only">
      <caption>Calendário mensal</caption>
      <thead>
        <tr>
          <th v-for="(weekday, index) in weekdays" :key="index" scope="col">
            {{ weekday }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="week in weeks" :key="week.getTime()">
          <td v-for="index in 7" :key="index">
            {{ accessibleDateLabel(isoDate(addDays(week, index - 1))) }}:
            {{ tasksByDay.get(isoDate(addDays(week, index - 1)))?.length ?? 0 }} tarefas
          </td>
        </tr>
      </tbody>
    </table>
    <UScrollArea
      :style="{ scrollPaddingTop: `${CHROME_HEIGHT}px` }"
      class="flex-1 snap-y snap-proximity [scrollbar-gutter:stable] [view-transition-name:calendar]"
    >
      <div :style="{ paddingTop: `${CHROME_HEIGHT}px` }">
        <WorkCalendarWorkMonthWeek
          v-for="week in weeks"
          :key="week.getTime()"
          :week-start="week"
          :tasks-by-day="tasksByDay"
          :loading="loading"
          :can-drag="canDrag"
          :busy-id="busyId"
          :style="{ height: `${ROW_HEIGHT}px` }"
          @select="(task, event) => emit('select', task, event)"
          @select-date="emit('select-date', $event)"
          @drop="(taskId, day) => emit('drop', taskId, day)"
        />
      </div>
    </UScrollArea>

    <div class="absolute top-[calc(var(--ui-header-height)+0.5rem)] inset-x-0 z-30 h-10 grid grid-cols-7 glass-material bg-(--glass-bg) border-b border-default overflow-hidden [scrollbar-gutter:stable] [view-transition-name:weekdays]">
      <span
        v-for="(weekday, index) in weekdays"
        :key="`${weekday}-${index}`"
        class="flex items-center justify-end pe-2 text-sm text-muted border-default first-letter:uppercase"
        :class="index !== 0 && 'border-s'"
      >
        {{ weekday }}
      </span>
    </div>
  </div>
</template>
