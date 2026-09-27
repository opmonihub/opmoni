<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { statusPresentation } from '~/composables/useWorkPresentation'
import type { WorkTaskStatus } from '~/types/work'
import {
  derivedProcessStatusPresentation,
  type DerivedProcessStatus
} from '~/utils/workDerivedStatus'

const props = withDefaults(defineProps<{
  derived: DerivedProcessStatus
  canManage?: boolean
  disabled?: boolean
}>(), {
  canManage: false,
  disabled: false
})

const emit = defineEmits<{
  change: [status: Exclude<WorkTaskStatus, 'dismissed'>]
  dismiss: []
}>()

const presentation = computed(() => derivedProcessStatusPresentation(props.derived))

const STATUS_VALUES: WorkTaskStatus[] = ['todo', 'doing', 'done', 'dismissed']

/** Hide the menu option that matches the current derived status. */
function isRedundantForDerived(value: WorkTaskStatus, derived: DerivedProcessStatus): boolean {
  if (derived === 'open') return value === 'todo'
  if (derived === 'in_progress') return value === 'doing'
  if (derived === 'done') return value === 'done'
  return false
}

const items = computed<DropdownMenuItem[][]>(() => [
  STATUS_VALUES
    .filter(value => !isRedundantForDerived(value, props.derived))
    .map((value) => {
      const item = statusPresentation(value)
      return {
        label: item.label,
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
  <UBadge
    v-if="!canManage || derived === 'empty'"
    :color="presentation.color"
    variant="subtle"
    :label="presentation.label"
  />
  <UDropdownMenu
    v-else
    :items="items"
    :content="{ align: 'end' }"
  >
    <UButton
      size="xs"
      variant="soft"
      :color="presentation.color"
      :disabled="disabled"
      trailing-icon="i-lucide-chevron-down"
      class="h-7 min-w-28 justify-between"
      :ui="{ base: 'w-full max-w-36' }"
      aria-label="Status do grupo — aplicar a todas as tarefas"
    >
      {{ presentation.label }}
    </UButton>
  </UDropdownMenu>
</template>
