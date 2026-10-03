<script setup lang="ts">
import { parseDate, type DateValue } from '@internationalized/date'
import { operatorDetail } from './filter-model'
import {
  columnTypeOf,
  type FilterPanelColumn,
  type FilterPanelDraft
} from '~/utils/filterPanel'

const props = defineProps<{
  column: FilterPanelColumn
  draft: FilterPanelDraft
  error: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  draft: [patch: Partial<FilterPanelDraft>]
  apply: []
}>()

const showPair = computed(() => {
  if (!props.column.operators) return true
  return operatorDetail(columnTypeOf(props.column.control), props.draft.operator).target === 'multiple'
})

function asText(value: unknown) {
  if (value === null || value === undefined) return ''
  return String(value)
}

function parseDay(value: string) {
  if (!value) return undefined
  try {
    return parseDate(value)
  } catch {
    return undefined
  }
}

const dateRange = computed(() => {
  const start = parseDay(props.draft.from)
  const end = parseDay(props.draft.to)
  if (!start && !end) return undefined
  return { start, end }
})

function onDateRange(value: { start?: DateValue, end?: DateValue } | null | undefined) {
  emit('draft', {
    from: value?.start?.toString() ?? '',
    to: value?.end?.toString() ?? ''
  })
}

function onApply() {
  if (props.disabled || props.error) return
  emit('apply')
}
</script>

<template>
  <div class="flex flex-col gap-2 p-3" @keydown.enter.prevent="onApply">
    <UInput
      v-if="column.control === 'text'"
      :model-value="draft.text"
      :icon="column.icon"
      :placeholder="column.label"
      :color="error ? 'error' : 'neutral'"
      autofocus
      class="w-full"
      :disabled="disabled"
      @update:model-value="emit('draft', { text: asText($event) })"
    />

    <UInputDate
      v-else-if="column.control === 'date-range'"
      :model-value="dateRange"
      range
      locale="pt-BR"
      :color="error ? 'error' : 'neutral'"
      icon="i-lucide-calendar"
      class="w-full"
      :disabled="disabled"
      @update:model-value="onDateRange($event)"
    />

    <div v-else class="flex gap-1.5">
      <UInput
        :model-value="draft.from"
        type="number"
        min="0"
        step="0.01"
        placeholder="De"
        :color="error ? 'error' : 'neutral'"
        class="min-w-0 flex-1"
        :disabled="disabled"
        :aria-label="`${column.label} de`"
        @update:model-value="emit('draft', { from: asText($event) })"
      />
      <UInput
        v-if="showPair"
        :model-value="draft.to"
        type="number"
        min="0"
        step="0.01"
        placeholder="Até"
        :color="error ? 'error' : 'neutral'"
        class="min-w-0 flex-1"
        :disabled="disabled"
        :aria-label="`${column.label} até`"
        @update:model-value="emit('draft', { to: asText($event) })"
      />
    </div>

    <p v-if="error" class="text-xs text-error">
      {{ error }}
    </p>

    <div class="flex justify-end">
      <UButton
        label="Aplicar"
        size="sm"
        color="neutral"
        variant="outline"
        :disabled="disabled || !!error"
        @click="onApply"
      />
    </div>
  </div>
</template>
