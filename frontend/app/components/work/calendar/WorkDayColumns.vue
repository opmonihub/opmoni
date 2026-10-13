<script setup lang="ts">
import type { WorkTask } from '~/types/work'
import { priorityPresentation } from '~/composables/useWorkPresentation'
import { accessibleDateLabel } from '~/utils/calendarUi'
import { calendarStatusPresentation, parseDateKey } from '~/utils/workCalendar'

defineProps<{
  dayKeys: string[]
  tasksByDay: Map<string, WorkTask[]>
  loading?: boolean
  single?: boolean
}>()

const emit = defineEmits<{
  select: [task: WorkTask, event: MouseEvent]
}>()

function dayLabel(key: string) {
  const parsed = parseDateKey(key)
  if (!parsed) return key
  return new Date(parsed.year, parsed.month - 1, parsed.day).toLocaleDateString('pt-BR', {
    weekday: 'short',
    day: '2-digit',
    month: 'short'
  })
}
</script>

<template>
  <div
    class="grid min-h-0 flex-1 gap-px overflow-auto bg-default"
    :class="single ? 'grid-cols-1' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-7'"
  >
    <section
      v-for="key in dayKeys"
      :key="key"
      class="flex min-h-40 min-w-0 flex-col bg-default"
      :aria-label="accessibleDateLabel(key)"
    >
      <h2 class="border-b border-default px-3 py-2 text-sm font-medium first-letter:uppercase text-highlighted">
        {{ dayLabel(key) }}
      </h2>

      <div v-if="loading" class="space-y-2 p-3">
        <USkeleton class="h-8 w-full" />
        <USkeleton class="h-8 w-3/4" />
      </div>

      <UEmpty
        v-else-if="!(tasksByDay.get(key)?.length)"
        class="my-auto py-6"
        icon="i-lucide-calendar-off"
        title="Sem tarefas"
        description="Nenhuma rotina com vencimento neste dia."
        variant="naked"
      />

      <ul v-else class="flex flex-1 flex-col gap-1 overflow-y-auto p-2">
        <li v-for="task in tasksByDay.get(key)" :key="task.id">
          <button
            type="button"
            class="flex w-full flex-col gap-0.5 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-elevated focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
            :aria-label="`${task.title}, ${calendarStatusPresentation(task.status).label}, ${accessibleDateLabel(key)}`"
            @click="emit('select', task, $event)"
          >
            <span class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-highlighted">
              <span
                class="size-1.5 shrink-0 rounded-full"
                :class="calendarStatusPresentation(task.status).dotClass"
              />
              <span class="truncate">{{ task.title }}</span>
            </span>
            <span class="truncate pl-3 text-xs text-muted">
              {{ task.process?.client?.name ?? 'Sem cliente' }}
              · {{ task.process?.name ?? 'Processo' }}
            </span>
            <span class="truncate pl-3 text-xs text-muted">
              {{ task.department?.name ?? 'Sem departamento' }}
              · {{ priorityPresentation(task.priority).label }}
              <template v-if="task.assignee_member_id"> · #{{ task.assignee_member_id }}</template>
            </span>
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>
