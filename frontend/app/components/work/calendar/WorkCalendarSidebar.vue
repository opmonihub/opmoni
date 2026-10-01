<script setup lang="ts">
/**
 * Ported from nuxt-ui-templates/calendar sidebar pieces:
 * - `components/calendar/List.vue` (status visibility ≈ calendars list)
 * - `components/calendar/Mini.vue`
 * - `components/AppSidebar.vue` flex: list → mt-auto separator → mini
 * Operational filters remain Opmoni-domain (collapsed).
 */
import type { DateValue } from '@internationalized/date'
import { CalendarDate } from '@internationalized/date'
import type { WorkTaskStatus, WorkTaskPriority } from '~/types/work'
import { calendarPriorityOptions, countActiveCalendarFilters } from '~/utils/calendarUi'
import { calendarStatusPresentation, parseDateKey } from '~/utils/workCalendar'

const props = defineProps<{
  modelDate: string
  statusVisible: Record<WorkTaskStatus, boolean>
  processId: number | null
  clientId: number | null
  assigneeId: number | null
  departmentId: number | null
  priority: WorkTaskPriority | ''
  processOptions: { label: string, value: number }[]
  clientOptions: { label: string, value: number }[]
  assigneeOptions: { label: string, value: number }[]
  departmentOptions: { label: string, value: number }[]
}>()

const emit = defineEmits<{
  'update:modelDate': [value: string]
  'update:statusVisible': [value: Record<WorkTaskStatus, boolean>]
  'update:processId': [value: number | null]
  'update:clientId': [value: number | null]
  'update:assigneeId': [value: number | null]
  'update:departmentId': [value: number | null]
  'update:priority': [value: WorkTaskPriority | '']
  'clear': []
}>()

const statusItems = computed(() => ([
  { label: 'Status', type: 'label' as const },
  ...(['todo', 'doing', 'done', 'dismissed'] as WorkTaskStatus[]).map(key => ({
    label: calendarStatusPresentation(key).label,
    color: calendarStatusPresentation(key).color,
    value: key,
    slot: 'status' as const,
    as: 'div' as const
  }))
]))

const filtersOpen = ref(false)

const activeFilterCount = computed(() => countActiveCalendarFilters({
  processId: props.processId,
  clientId: props.clientId,
  assigneeId: props.assigneeId,
  departmentId: props.departmentId,
  priority: props.priority
}))

const miniValue = computed({
  get(): DateValue | undefined {
    const parsed = parseDateKey(props.modelDate)
    if (!parsed) return undefined
    return new CalendarDate(parsed.year, parsed.month, parsed.day)
  },
  set(value: DateValue | undefined) {
    if (!value || !('day' in value)) return
    const date = value as { year: number, month: number, day: number }
    emit('update:modelDate', `${date.year}-${String(date.month).padStart(2, '0')}-${String(date.day).padStart(2, '0')}`)
  }
})

function toggleStatus(key: WorkTaskStatus, on: boolean | 'indeterminate') {
  emit('update:statusVisible', { ...props.statusVisible, [key]: Boolean(on) })
}
</script>

<template>
  <!--
    Width/padding live on the parent rail (like template USidebar chrome).
    This body only fills the rail: min-w-0 + overflow-x-hidden stop the xs
    UCalendar from spilling past lg:w-64 / mobile w-72.
  -->
  <aside class="flex min-h-0 min-w-0 w-full flex-1 flex-col gap-3 overflow-x-hidden overflow-y-auto">
    <UNavigationMenu
      :items="statusItems"
      orientation="vertical"
      class="min-w-0 shrink-0"
    >
      <template #status="{ item }">
        <UCheckbox
          :label="item.label"
          :color="item.color"
          :model-value="statusVisible[item.value as WorkTaskStatus]"
          class="w-full min-w-0"
          @update:model-value="toggleStatus(item.value as WorkTaskStatus, $event)"
        />
      </template>
    </UNavigationMenu>

    <UCollapsible
      v-model:open="filtersOpen"
      class="min-w-0 shrink-0"
    >
      <UButton
        color="neutral"
        variant="soft"
        class="w-full min-w-0 justify-start rounded-full"
        :trailing-icon="filtersOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
        :aria-label="filtersOpen ? 'Recolher filtros' : 'Expandir filtros'"
      >
        <span class="flex min-w-0 flex-1 items-center gap-2 text-left">
          <UIcon name="i-lucide-sliders-horizontal" class="size-4 shrink-0 text-muted" />
          <span class="truncate">Filtros</span>
          <UBadge
            v-if="activeFilterCount"
            :label="String(activeFilterCount)"
            color="primary"
            variant="subtle"
            size="sm"
            class="shrink-0"
          />
        </span>
      </UButton>

      <template #content>
        <div class="flex min-w-0 flex-col gap-3 px-1.5 pb-1 pt-3">
          <UFormField label="Processo">
            <USelectMenu
              :model-value="processId"
              :items="processOptions"
              value-key="value"
              label-key="label"
              placeholder="Todos"
              clear
              class="w-full"
              @update:model-value="emit('update:processId', ($event as number | null) ?? null)"
            />
          </UFormField>
          <UFormField label="Cliente">
            <USelectMenu
              :model-value="clientId"
              :items="clientOptions"
              value-key="value"
              label-key="label"
              placeholder="Todos"
              clear
              class="w-full"
              @update:model-value="emit('update:clientId', ($event as number | null) ?? null)"
            />
          </UFormField>
          <UFormField label="Responsável">
            <USelectMenu
              :model-value="assigneeId"
              :items="assigneeOptions"
              value-key="value"
              label-key="label"
              placeholder="Todos"
              clear
              class="w-full"
              @update:model-value="emit('update:assigneeId', ($event as number | null) ?? null)"
            />
          </UFormField>
          <UFormField label="Departamento">
            <USelectMenu
              :model-value="departmentId"
              :items="departmentOptions"
              value-key="value"
              label-key="label"
              placeholder="Todos"
              clear
              class="w-full"
              @update:model-value="emit('update:departmentId', ($event as number | null) ?? null)"
            />
          </UFormField>
          <UFormField label="Prioridade">
            <USelect
              :model-value="priority || undefined"
              :items="calendarPriorityOptions"
              value-key="value"
              label-key="label"
              placeholder="Todas"
              class="w-full"
              @update:model-value="emit('update:priority', ($event as WorkTaskPriority | undefined) ?? '')"
            />
          </UFormField>
          <UButton
            label="Limpar filtros"
            color="neutral"
            variant="ghost"
            size="sm"
            class="self-start rounded-full px-1.5"
            :disabled="activeFilterCount === 0"
            @click="emit('clear')"
          />
        </div>
      </template>
    </UCollapsible>

    <!-- Template AppSidebar: separator mt-auto docks mini calendar at the bottom -->
    <USeparator class="mt-auto shrink-0" />

    <!--
      ClientOnly: Reka CalendarCellTrigger uses isToday(..., getLocalTimeZone()).
      Docker SSR is UTC while browsers here are America/Sao_Paulo — that flips
      tabindex/today attrs across midnight and cascades into parentNode errors.
    -->
    <div class="min-w-0 shrink-0 overflow-hidden pb-1">
      <ClientOnly>
        <UCalendar
          v-model="miniValue"
          :week-starts-on="1"
          :year-controls="false"
          size="xs"
          fixed-weeks
          locale="pt-BR"
          class="w-full max-w-full"
          :ui="{
            root: 'w-full max-w-full',
            header: 'w-full min-w-0',
            body: 'w-full min-w-0',
            grid: 'w-full',
            gridBody: 'w-full'
          }"
        />
        <template #fallback>
          <div class="h-56 w-full max-w-full" aria-hidden="true" />
        </template>
      </ClientOnly>
    </div>
  </aside>
</template>
