<script setup lang="ts">
import type { WorkTask } from '~/types/work'
import { priorityPresentation } from '~/composables/useWorkPresentation'
import { calendarStatusPresentation } from '~/utils/workCalendar'

const props = defineProps<{
  task: WorkTask
  canManage: boolean
  busy?: boolean
  memberOptions: { label: string, value: number }[]
  showClose?: boolean
  showTitle?: boolean
}>()

const emit = defineEmits<{
  'advance': [task: WorkTask]
  'back': [task: WorkTask]
  'assign': [task: WorkTask, memberId: number | null]
  'request-dismiss': [task: WorkTask]
  'close': []
}>()

const canAdvance = computed(() => props.task.status === 'todo' || props.task.status === 'doing')
const canBack = computed(() => props.task.status === 'doing' || props.task.status === 'done')
const canDismiss = computed(() => props.task.status !== 'dismissed')

const status = computed(() => calendarStatusPresentation(props.task.status))
const priority = computed(() => priorityPresentation(props.task.priority))

const assigneeLabel = computed(() => {
  if (props.task.assignee_member_id == null) return 'Sem responsável'
  return props.memberOptions.find(m => m.value === props.task.assignee_member_id)?.label
    ?? String(props.task.assignee_member_id)
})

function formatDue(due: string | null) {
  if (!due) return 'Sem prazo'
  return new Date(`${due}T00:00:00`).toLocaleDateString('pt-BR')
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <!-- Header: title + cliente·processo, status trailing -->
    <div class="flex items-start gap-2 rounded-md bg-(--control-bg) px-3 py-2">
      <div class="min-w-0 flex-1">
        <h2
          v-if="showTitle !== false"
          class="truncate text-sm font-medium text-highlighted"
        >
          {{ task.title }}
        </h2>
        <p
          class="truncate text-xs text-muted"
          :class="showTitle !== false ? 'mt-0.5' : ''"
        >
          {{ task.process?.client?.name ?? 'Sem cliente' }} · {{ task.process?.name ?? 'Processo' }}
        </p>
      </div>
      <UBadge
        :label="status.label"
        :color="status.color"
        variant="subtle"
        size="sm"
        class="shrink-0"
      />
    </div>

    <!-- Meta grid: label | value -->
    <div class="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-1.5 rounded-md bg-(--control-bg) px-3 py-2">
      <span class="w-24 text-end text-sm text-muted">Vencimento</span>
      <span class="truncate text-sm text-highlighted">
        {{ formatDue(task.due_on) }}
      </span>

      <span class="w-24 text-end text-sm text-muted">Responsável</span>
      <USelectMenu
        v-if="canManage"
        :model-value="task.assignee_member_id"
        :items="memberOptions"
        value-key="value"
        label-key="label"
        placeholder="Sem responsável"
        clear
        size="sm"
        variant="none"
        class="w-full min-w-0"
        :disabled="busy"
        :ui="{ base: 'ps-0 py-0' }"
        @update:model-value="emit('assign', task, ($event as number | null) ?? null)"
      />
      <span
        v-else
        class="truncate text-sm text-highlighted"
      >
        {{ assigneeLabel }}
      </span>

      <span class="w-24 text-end text-sm text-muted">Departamento</span>
      <span class="truncate text-sm text-highlighted">
        {{ task.department?.name ?? 'Sem departamento' }}
      </span>

      <span class="w-24 text-end text-sm text-muted">Prioridade</span>
      <span class="truncate text-sm text-highlighted">
        {{ priority.label }}
      </span>
    </div>

    <!-- Process link as discrete row -->
    <NuxtLink
      v-if="task.process?.id"
      :to="`/work/processos/${task.process.id}`"
      class="inline-flex items-center gap-1.5 rounded-md bg-(--control-bg) px-3 py-2 text-sm text-muted transition-colors hover:bg-(--control-bg-hover) hover:text-highlighted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
      <UIcon name="i-lucide-external-link" class="size-3.5 shrink-0" />
      <span class="truncate">Abrir processo</span>
    </NuxtLink>

    <!-- Footer actions -->
    <div
      v-if="canManage || showClose"
      class="flex flex-col gap-1.5"
    >
      <div
        v-if="canManage && (canBack || canAdvance)"
        class="flex flex-wrap items-center gap-1.5"
      >
        <UButton
          v-if="canBack"
          label="Retornar"
          color="neutral"
          variant="outline"
          size="sm"
          class="flex-1"
          :loading="busy"
          @click="emit('back', task)"
        />
        <UButton
          v-if="canAdvance"
          :label="task.status === 'todo' ? 'Avançar' : 'Concluir'"
          color="primary"
          size="sm"
          class="flex-1"
          :loading="busy"
          @click="emit('advance', task)"
        />
      </div>

      <UButton
        v-if="canManage && canDismiss"
        label="Dispensar"
        color="error"
        variant="soft"
        size="sm"
        block
        :disabled="busy"
        @click="emit('request-dismiss', task)"
      />

      <UButton
        v-if="showClose"
        label="Fechar"
        color="neutral"
        variant="ghost"
        size="sm"
        block
        @click="emit('close')"
      />
    </div>
  </div>
</template>
