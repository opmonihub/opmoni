<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { statusPresentation } from '~/composables/useWorkPresentation'
import type { WorkTaskStatus } from '~/types/work'

const props = withDefaults(defineProps<{
  status: WorkTaskStatus
  locked?: boolean
  disabled?: boolean
}>(), {
  locked: false,
  disabled: false
})

const emit = defineEmits<{
  change: [status: Exclude<WorkTaskStatus, 'dismissed'>]
  dismiss: []
}>()

const STATUS_VALUES: WorkTaskStatus[] = ['todo', 'doing', 'done', 'dismissed']

const presentation = computed(() => statusPresentation(props.status))

/** Button+menu stays light when closed; USelect-per-row thrashes large Work tables. */
const items = computed<DropdownMenuItem[][]>(() => [
  STATUS_VALUES
    .filter(value => value !== props.status)
    .map((value) => {
      const item = statusPresentation(value)
      const cascadeBlocked = props.locked && value !== 'todo'
      return {
        label: item.label,
        disabled: cascadeBlocked,
        onSelect: () => {
          if (value === 'dismissed') {
            emit('dismiss')
            return
          }
          emit('change', value)
        }
      }
    })
])
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton
      size="xs"
      variant="soft"
      :color="presentation.color"
      :disabled="disabled"
      trailing-icon="i-lucide-chevron-down"
      class="h-7 min-w-28 justify-between"
      :ui="{ base: 'w-full max-w-36' }"
      aria-label="Status da tarefa"
    >
      {{ presentation.label }}
    </UButton>
  </UDropdownMenu>
</template>
