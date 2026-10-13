<script setup lang="ts">
import type { WorkTask } from '~/types/work'

const props = defineProps<{
  task: WorkTask | null
  open: boolean
  anchor?: HTMLElement | null
  canManage: boolean
  busy?: boolean
  memberOptions: { label: string, value: number }[]
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'advance': [task: WorkTask]
  'back': [task: WorkTask]
  'assign': [task: WorkTask, memberId: number | null]
  'dismiss': [task: WorkTask, reason: string]
}>()

const isMobile = useClientMediaQuery('(max-width: 767px)')
const dismissOpen = ref(false)
const dismissReason = ref('')

watch(() => props.open, (value) => {
  if (!value) {
    dismissOpen.value = false
    dismissReason.value = ''
  }
})

function requestDismiss() {
  if (props.task) dismissOpen.value = true
}

function confirmDismiss() {
  if (!props.task || !dismissReason.value.trim()) return
  emit('dismiss', props.task, dismissReason.value.trim())
  dismissOpen.value = false
  dismissReason.value = ''
}
</script>

<template>
  <UPopover
    v-if="!isMobile && task"
    :open="open"
    :reference="anchor ?? undefined"
    :content="{ align: 'start', side: 'bottom', sideOffset: 8 }"
    :ui="{ content: 'p-2 w-74' }"
    @update:open="emit('update:open', $event)"
  >
    <template #content>
      <WorkCalendarTaskDetails
        :task="task"
        :can-manage="canManage"
        :busy="busy"
        :member-options="memberOptions"
        @advance="emit('advance', $event)"
        @back="emit('back', $event)"
        @assign="(selectedTask, memberId) => emit('assign', selectedTask, memberId)"
        @request-dismiss="requestDismiss"
      />
    </template>
  </UPopover>

  <UModal
    v-else-if="isMobile"
    :open="open"
    :title="task?.title ?? 'Tarefa'"
    :description="task ? `${task.process?.client?.name ?? 'Sem cliente'} · ${task.process?.name ?? 'Processo'}` : undefined"
    @update:open="emit('update:open', $event)"
  >
    <template v-if="task" #body>
      <WorkCalendarTaskDetails
        :task="task"
        :can-manage="canManage"
        :busy="busy"
        :member-options="memberOptions"
        :show-title="false"
        show-close
        @advance="emit('advance', $event)"
        @back="emit('back', $event)"
        @assign="(selectedTask, memberId) => emit('assign', selectedTask, memberId)"
        @request-dismiss="requestDismiss"
        @close="emit('update:open', false)"
      />
    </template>
  </UModal>

  <UModal
    v-model:open="dismissOpen"
    title="Dispensar tarefa"
    description="Informe o motivo. Essa ação registra a dispensa na rotina."
  >
    <template #body>
      <UFormField label="Motivo" required>
        <UTextarea
          v-model="dismissReason"
          :rows="3"
          class="w-full"
          autofocus
        />
      </UFormField>
    </template>
    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        label="Confirmar"
        color="error"
        :disabled="!dismissReason.trim()"
        :loading="busy"
        @click="confirmDismiss"
      />
    </template>
  </UModal>
</template>
