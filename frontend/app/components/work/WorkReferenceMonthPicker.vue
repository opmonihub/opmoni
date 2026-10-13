<script setup lang="ts">
import {
  formatReferenceMonthLabel,
  REFERENCE_MONTH_RE,
  referenceMonthShortLabels
} from '~/utils/workReferenceMonth'

const props = defineProps<{
  modelValue: string
  label: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'prev': []
  'next': []
}>()

const open = ref(false)
const shortMonths = referenceMonthShortLabels('pt-BR')
const pickerYear = ref(Number(props.modelValue.slice(0, 4)) || new Date().getFullYear())

watch(open, (isOpen) => {
  if (isOpen && REFERENCE_MONTH_RE.test(props.modelValue)) {
    pickerYear.value = Number(props.modelValue.slice(0, 4))
  }
})

const selectedParts = computed(() => {
  const match = REFERENCE_MONTH_RE.exec(props.modelValue)
  if (!match) return { year: pickerYear.value, month: 0 }
  return { year: Number(match[1]), month: Number(match[2]) }
})

function selectMonth(monthIndex: number) {
  emit('update:modelValue', `${pickerYear.value}-${String(monthIndex).padStart(2, '0')}`)
  open.value = false
}

function isSelected(monthIndex: number) {
  return selectedParts.value.year === pickerYear.value
    && selectedParts.value.month === monthIndex
}
</script>

<template>
  <div class="flex items-center gap-0.5">
    <UTooltip text="Mês anterior">
      <UButton
        icon="i-lucide-chevron-left"
        color="neutral"
        variant="ghost"
        square
        aria-label="Mês anterior"
        @click="emit('prev')"
      />
    </UTooltip>

    <UPopover v-model:open="open" :content="{ align: 'start' }" :modal="true">
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-calendar"
        class="data-[state=open]:bg-elevated group min-w-0"
      >
        <span class="truncate first-letter:uppercase">
          {{ label }}
        </span>

        <template #trailing>
          <UIcon
            name="i-lucide-chevron-down"
            class="size-5 shrink-0 text-dimmed transition-transform duration-200 group-data-[state=open]:rotate-180"
          />
        </template>
      </UButton>

      <template #content>
        <div class="w-64 p-3">
          <div class="mb-2 flex items-center justify-between gap-2">
            <UButton
              icon="i-lucide-chevron-left"
              color="neutral"
              variant="ghost"
              size="sm"
              square
              aria-label="Ano anterior"
              @click="pickerYear -= 1"
            />
            <span class="text-sm font-medium text-highlighted tabular-nums">
              {{ pickerYear }}
            </span>
            <UButton
              icon="i-lucide-chevron-right"
              color="neutral"
              variant="ghost"
              size="sm"
              square
              aria-label="Próximo ano"
              @click="pickerYear += 1"
            />
          </div>

          <div class="grid grid-cols-3 gap-1">
            <UButton
              v-for="(monthLabel, index) in shortMonths"
              :key="monthLabel"
              :label="monthLabel"
              size="sm"
              :color="isSelected(index + 1) ? 'primary' : 'neutral'"
              :variant="isSelected(index + 1) ? 'soft' : 'ghost'"
              class="justify-center first-letter:uppercase"
              :aria-label="formatReferenceMonthLabel(`${pickerYear}-${String(index + 1).padStart(2, '0')}`)"
              @click="selectMonth(index + 1)"
            />
          </div>
        </div>
      </template>
    </UPopover>

    <UTooltip text="Próximo mês">
      <UButton
        icon="i-lucide-chevron-right"
        color="neutral"
        variant="ghost"
        square
        aria-label="Próximo mês"
        @click="emit('next')"
      />
    </UTooltip>
  </div>
</template>
