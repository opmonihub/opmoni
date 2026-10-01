<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { priorityPresentation } from '~/composables/useWorkPresentation'
import type { WorkTask } from '~/types/work'

const props = defineProps<{
  task: WorkTask
  canManageWork: boolean
  busy?: boolean
  locked?: boolean
  assigneeItems: Array<{ label: string, value: number | null }>
  membersFailed?: boolean
  membersHint?: string
  assigneeLabel: string
}>()

const emit = defineEmits<{
  advance: []
  moveBack: []
  dismiss: []
  assign: [memberId: number | null]
}>()

const processLabel = computed(() => props.task.process?.name ?? 'Processo avulso')

const dueLabel = computed(() => {
  if (!props.task.due_on) return 'Sem vencimento'
  return new Date(`${props.task.due_on}T00:00:00`).toLocaleDateString('pt-BR')
})

const canAdvance = computed(() => props.task.status === 'todo' || props.task.status === 'doing')
const canMoveBack = computed(() => props.task.status === 'doing' || props.task.status === 'done')
const canDismiss = computed(() => props.task.status !== 'dismissed')

const advanceLabel = computed(() => props.task.status === 'todo' ? 'Avançar' : 'Concluir')

/** Dropdown stays cheap closed; USelectMenu-per-card was freezing ~700-card boards. */
const assignItems = computed<DropdownMenuItem[][]>(() => {
  if (props.membersFailed) {
    return [[{
      label: props.membersHint ?? 'Responsáveis indisponíveis',
      disabled: true
    }]]
  }
  if (!props.assigneeItems.length) {
    return [[{ label: 'Nenhum responsável disponível', disabled: true }]]
  }
  return [props.assigneeItems.map(item => ({
    label: item.label,
    onSelect: () => emit('assign', item.value)
  }))]
})
</script>

<template>
  <article class="flex min-w-0 flex-col gap-3 rounded-xl bg-default p-3 ring ring-default">
    <div class="flex min-w-0 flex-col gap-2">
      <div class="flex min-w-0 items-start gap-2">
        <p class="min-w-0 flex-1 truncate text-sm font-medium text-highlighted" :title="task.title">
          {{ task.title }}
        </p>
        <UBadge
          :color="priorityPresentation(task.priority).color"
          variant="subtle"
          :label="priorityPresentation(task.priority).label"
        />
      </div>

      <p class="truncate text-xs text-muted" :title="processLabel">
        {{ processLabel }}
      </p>
      <p v-if="task.process?.client?.name" class="truncate text-xs text-muted">
        {{ task.process.client.name }}
      </p>

      <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
        <span class="inline-flex items-center gap-1">
          <UIcon name="i-lucide-calendar" class="size-3.5 shrink-0" />
          {{ dueLabel }}
        </span>
        <UBadge color="neutral" variant="outline" :label="task.department?.name ?? 'Sem departamento'" />
      </div>

      <p class="text-xs text-muted">
        Responsável: {{ assigneeLabel }}
      </p>

      <p v-if="locked" class="flex items-center gap-1.5 text-xs text-warning">
        <UIcon name="i-lucide-lock" class="size-3.5 shrink-0" />
        Etapa anterior pendente bloqueia avanço e dispensa.
      </p>
    </div>

    <div v-if="canManageWork" class="flex flex-col gap-2 border-t border-default pt-3">
      <UDropdownMenu :items="assignItems" :content="{ align: 'start' }">
        <UButton
          :label="assigneeLabel"
          icon="i-lucide-user-round"
          trailing-icon="i-lucide-chevrons-up-down"
          color="neutral"
          variant="outline"
          size="xs"
          block
          class="justify-between"
          :loading="busy"
          :disabled="busy || membersFailed"
        />
      </UDropdownMenu>
      <p v-if="membersFailed" class="text-xs text-muted">
        {{ membersHint }}
      </p>

      <div
        v-if="canAdvance || canMoveBack || canDismiss"
        class="flex flex-wrap items-center gap-1.5"
        role="group"
        aria-label="Ações de status"
      >
        <UButton
          v-if="canAdvance"
          :label="advanceLabel"
          icon="i-lucide-arrow-right"
          size="xs"
          color="primary"
          :loading="busy"
          :disabled="busy || locked"
          @click="emit('advance')"
        />
        <UButton
          v-if="canMoveBack"
          label="Retornar"
          icon="i-lucide-arrow-left"
          size="xs"
          color="neutral"
          variant="outline"
          :loading="busy"
          :disabled="busy"
          @click="emit('moveBack')"
        />
        <UButton
          v-if="canDismiss"
          label="Dispensar"
          icon="i-lucide-circle-minus"
          size="xs"
          color="neutral"
          variant="ghost"
          class="ms-auto"
          :disabled="busy || locked"
          @click="emit('dismiss')"
        />
      </div>
    </div>
  </article>
</template>
