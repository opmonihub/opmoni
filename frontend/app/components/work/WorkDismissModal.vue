<script setup lang="ts">
const open = defineModel<boolean>('open', { required: true })
const reason = defineModel<string>('reason', { required: true })

const props = withDefaults(defineProps<{
  count?: number
  title?: string
  description?: string
  loading?: boolean
  confirmColor?: 'error' | 'warning'
  fieldLabel?: string
  fieldHelp?: string
  placeholder?: string
  rows?: number
}>(), {
  count: 0,
  confirmColor: 'error',
  fieldLabel: 'Motivo',
  placeholder: 'Descreva o motivo da dispensa...',
  rows: 3
})

const emit = defineEmits<{ confirm: [] }>()

const resolvedTitle = computed(() => {
  if (props.title) return props.title
  return props.count === 1 ? 'Dispensar tarefa' : 'Dispensar tarefas'
})

const resolvedDescription = computed(() => {
  if (props.description) return props.description
  return `${props.count} tarefa(s) serão marcadas como dispensadas.`
})
</script>

<template>
  <UModal
    v-model:open="open"
    :title="resolvedTitle"
    :description="resolvedDescription"
  >
    <template #body>
      <UFormField
        :label="fieldLabel"
        name="dismissal_reason"
        required
        :help="fieldHelp"
      >
        <UTextarea
          v-model="reason"
          :rows="rows"
          autoresize
          :placeholder="placeholder"
          class="w-full"
        />
      </UFormField>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          label="Cancelar"
          color="neutral"
          :variant="confirmColor === 'warning' ? 'subtle' : 'ghost'"
          @click="open = false"
        />
        <UButton
          label="Dispensar"
          :color="confirmColor"
          :variant="confirmColor === 'warning' ? 'solid' : undefined"
          :icon="confirmColor === 'warning' ? 'i-lucide-circle-minus' : undefined"
          :loading="loading"
          :disabled="loading || !reason.trim()"
          @click="emit('confirm')"
        />
      </div>
    </template>
  </UModal>
</template>
