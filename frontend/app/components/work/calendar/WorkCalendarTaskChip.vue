<script setup lang="ts">
/**
 * Ported from nuxt-ui-templates/calendar `components/calendar/EventChip.vue`.
 * Used in month cells; week/day use date-only vertical lists.
 */
import type { WorkTask } from '~/types/work'
import {
  calendarStatusPresentation,
  taskChipBlockClasses,
  taskChipCompactClasses,
  taskChipOutlineClasses
} from '~/utils/workCalendar'
import { accessibleDateLabel } from '~/utils/calendarUi'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  task: WorkTask
  dayKey: string
  allDay?: boolean
  showTime?: boolean
  canDrag?: boolean
  busy?: boolean
  active?: boolean
}>(), {
  allDay: false
})

const emit = defineEmits<{
  'select': [task: WorkTask, event: MouseEvent]
  'drag-start': [event: DragEvent, task: WorkTask]
}>()

const color = computed(() => calendarStatusPresentation(props.task.status).color)
const presentation = computed(() => calendarStatusPresentation(props.task.status))
</script>

<template>
  <button
    v-bind="$attrs"
    type="button"
    data-event
    class="select-none flex items-center gap-1.5 min-w-0 rounded-full px-1.5 py-0.5 text-xs text-start transition-colors focus-visible:outline-3"
    :class="[
      taskChipOutlineClasses[color],
      allDay
        ? taskChipBlockClasses[color]
        : ['text-default hover:bg-(--control-bg) data-active:bg-(--control-bg)', taskChipCompactClasses[color]]
    ]"
    :data-active="active || undefined"
    :aria-label="`${task.title}, ${presentation.label}, ${accessibleDateLabel(dayKey)}`"
    :draggable="canDrag && task.status !== 'dismissed'"
    :disabled="busy"
    @click.stop="emit('select', task, $event)"
    @dragstart="emit('drag-start', $event, task)"
  >
    <span
      v-if="allDay"
      :class="presentation.dotClass"
      class="rounded-full flex items-center justify-center p-0.5 -mx-0.75"
    >
      <UIcon
        name="i-lucide-calendar"
        class="size-2.5 shrink-0 text-inverted"
      />
    </span>
    <span
      v-else
      class="max-lg:hidden size-2 shrink-0 rounded-full"
      :class="presentation.dotClass"
    />

    <span class="font-medium truncate">{{ task.title }}</span>
  </button>
</template>
