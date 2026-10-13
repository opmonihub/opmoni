<script setup lang="ts">
import type { WorkTask, WorkTaskStatus } from '~/types/work'

type ColumnKey = WorkTaskStatus

const COLUMN_PAGE = 24

const props = defineProps<{
  tasksByColumn: Record<ColumnKey, WorkTask[]>
  loading?: boolean
  canManageWork: boolean
  isLocked: (task: WorkTask) => boolean
  busyId: number | null
  assigneeItems: Array<{ label: string, value: number | null }>
  membersFailed?: boolean
  membersHint?: string
  memberName: (id: number | null) => string
}>()

const emit = defineEmits<{
  advance: [task: WorkTask]
  moveBack: [task: WorkTask]
  dismiss: [task: WorkTask]
  assign: [task: WorkTask, memberId: number | null]
}>()

const columns = [
  { key: 'todo' as ColumnKey, title: 'A fazer', icon: 'i-lucide-circle' },
  { key: 'doing' as ColumnKey, title: 'Em progresso', icon: 'i-lucide-loader' },
  { key: 'done' as ColumnKey, title: 'Concluída', icon: 'i-lucide-circle-check' },
  { key: 'dismissed' as ColumnKey, title: 'Dispensada', icon: 'i-lucide-circle-minus' }
]

/** Cap mounted cards per column — full Sep datasets (~600+ todo) freeze the main thread. */
const visibleLimit = ref<Partial<Record<ColumnKey, number>>>({})

function tasksFor(statusKey: ColumnKey): WorkTask[] {
  return props.tasksByColumn[statusKey] ?? []
}

function limitFor(key: ColumnKey): number {
  return visibleLimit.value[key] ?? COLUMN_PAGE
}

function shownTasks(key: ColumnKey): WorkTask[] {
  return tasksFor(key).slice(0, limitFor(key))
}

function remainingCount(key: ColumnKey): number {
  return Math.max(0, tasksFor(key).length - limitFor(key))
}

function showMore(key: ColumnKey) {
  visibleLimit.value = {
    ...visibleLimit.value,
    [key]: limitFor(key) + COLUMN_PAGE
  }
}

/** Reset paging when column sizes change (filters / status moves), not on object identity. */
watch(
  () => (['todo', 'doing', 'done', 'dismissed'] as const)
    .map(key => `${key}:${(props.tasksByColumn[key] ?? []).length}`)
    .join('|'),
  () => {
    visibleLimit.value = {}
  }
)
</script>

<template>
  <div
    v-if="loading"
    class="grid min-w-0 items-start gap-3 xl:grid-cols-4 lg:grid-cols-2"
    aria-busy="true"
    aria-label="Carregando quadro de tarefas"
  >
    <section
      v-for="column in columns"
      :key="`skeleton-${column.key}`"
      class="flex min-w-0 flex-col gap-2 rounded-xl bg-elevated/60 p-2 ring-1 ring-default"
    >
      <header class="flex items-center gap-2 px-1 pt-1">
        <USkeleton class="size-4 shrink-0 rounded" />
        <USkeleton class="h-4 w-24" />
        <USkeleton class="ms-auto h-5 w-8 rounded-full" />
      </header>
      <USkeleton
        v-for="card in 3"
        :key="card"
        class="h-28 w-full rounded-xl"
      />
    </section>
  </div>

  <div
    v-else
    class="grid min-w-0 items-start gap-3 xl:grid-cols-4 lg:grid-cols-2"
  >
    <section
      v-for="column in columns"
      :key="column.key"
      :aria-label="column.title"
      class="flex min-w-0 flex-col gap-2 rounded-xl bg-elevated/60 p-2 ring-1 ring-default"
    >
      <header class="flex items-center gap-2 px-1 pt-1">
        <UIcon :name="column.icon" class="size-4 shrink-0 text-muted" />
        <h3 class="min-w-0 flex-1 truncate text-sm font-semibold text-highlighted">
          {{ column.title }}
        </h3>
        <UBadge color="neutral" variant="subtle" :label="String(tasksFor(column.key).length)" />
      </header>

      <p
        v-if="tasksFor(column.key).length === 0"
        class="rounded-lg bg-default px-3 py-4 text-center text-xs text-muted ring ring-default"
      >
        Nenhuma tarefa
      </p>

      <WorkTarefasCard
        v-for="task in shownTasks(column.key)"
        :key="task.id"
        :task="task"
        :can-manage-work="canManageWork"
        :busy="busyId === task.id"
        :locked="isLocked(task)"
        :assignee-items="assigneeItems"
        :members-failed="membersFailed"
        :members-hint="membersHint"
        :assignee-label="memberName(task.assignee_member_id)"
        @advance="emit('advance', task)"
        @move-back="emit('moveBack', task)"
        @dismiss="emit('dismiss', task)"
        @assign="(memberId) => emit('assign', task, memberId)"
      />

      <UButton
        v-if="remainingCount(column.key) > 0"
        :label="`Ver mais (${remainingCount(column.key)})`"
        icon="i-lucide-chevrons-down"
        color="neutral"
        variant="ghost"
        size="sm"
        block
        class="mt-1"
        @click="showMore(column.key)"
      />
    </section>
  </div>
</template>
