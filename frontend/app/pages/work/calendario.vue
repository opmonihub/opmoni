<script setup lang="ts">
import type { WorkTask, WorkTaskPriority, WorkTaskStatus } from '~/types/work'
import { useClients } from '~/composables/useClients'
import { useDepartments } from '~/composables/useDepartments'
import { useMembers } from '~/composables/useMembers'
import { monthTitleParts } from '~/utils/calendarUi'
import {
  type CalendarView,
  groupTasksByDay,
  parseCalendarQuery,
  periodRange,
  shiftPeriod,
  todayKey,
  weekKeys
} from '~/utils/workCalendar'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { calendar, updateTask, listProcesses } = useWork()
const { list: listClients } = useClients()
const { list: listDepartments } = useDepartments()
const { listDirectory } = useMembers()
const { canManageWork } = useAuth()

const initial = parseCalendarQuery(route.query.view, route.query.date)
const view = ref<CalendarView>(initial.view)
const focusDate = ref(initial.date)

const statusVisible = ref<Record<WorkTaskStatus, boolean>>({
  todo: true,
  doing: true,
  done: true,
  dismissed: true
})

const processId = ref<number | null>(null)
const clientId = ref<number | null>(null)
const assigneeId = ref<number | null>(null)
const departmentId = ref<number | null>(null)
const priority = ref<WorkTaskPriority | ''>('')

const range = computed(() => periodRange(view.value, focusDate.value))
const rangeKey = computed(() => JSON.stringify([
  range.value.from,
  range.value.to,
  processId.value,
  clientId.value,
  assigneeId.value,
  departmentId.value,
  priority.value
]))

const { data, status, error, refresh: reload } = await useAsyncData(
  'work-calendar',
  () => calendar(range.value.from, range.value.to, {
    process_id: processId.value ?? undefined,
    client_id: clientId.value ?? undefined,
    assignee_member_id: assigneeId.value ?? undefined,
    department_id: departmentId.value ?? undefined,
    priority: priority.value || undefined
  }),
  { watch: [rangeKey] }
)

const tasks = computed<WorkTask[]>(() => data.value ?? [])

/**
 * The `refresh` below is the composable's, not `reload`'s: the header button and
 * the alert answer through it, so a failed manual refresh toasts "Não foi possível
 * atualizar o calendário" instead of going quiet. The task mutations below keep
 * calling `reload` inside their own try/catch, so those still report through
 * their own message.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o calendário',
  refreshErrorTitle: 'Não foi possível atualizar o calendário'
})

const filteredTasks = computed(() => tasks.value.filter(task => statusVisible.value[task.status]))
const tasksByDay = computed(() => groupTasksByDay(filteredTasks.value))

const title = computed(() => {
  if (view.value === 'month') return monthTitleParts(focusDate.value)
  if (view.value === 'week') {
    const keys = weekKeys(focusDate.value)
    const start = monthTitleParts(keys[0]!)
    const end = monthTitleParts(keys[6]!)
    if (start.months === end.months && start.year === end.year) {
      return { months: `${keys[0]!.slice(8)}–${keys[6]!.slice(8)} ${start.months}`, year: start.year }
    }
    return { months: `${keys[0]} → ${keys[6]}`, year: end.year }
  }
  const date = new Date(`${focusDate.value}T00:00:00`)
  return {
    months: date.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' }),
    year: String(date.getFullYear())
  }
})

const isSidebarOpen = ref(false)
const isMobile = useClientMediaQuery('(max-width: 1023px)')

watch([view, focusDate], () => {
  if (isMobile.value) isSidebarOpen.value = false
})

const dayColumnKeys = computed(() => {
  if (view.value === 'day') return [focusDate.value]
  if (view.value === 'week') return weekKeys(focusDate.value)
  return []
})

const viewItems = [
  { label: 'Dia', value: 'day' as const },
  { label: 'Semana', value: 'week' as const },
  { label: 'Mês', value: 'month' as const }
]

function syncQuery() {
  router.replace({
    query: {
      ...route.query,
      view: view.value,
      date: focusDate.value
    }
  })
}

watch([view, focusDate], syncQuery, { immediate: true })

watch(
  () => [route.query.view, route.query.date] as const,
  ([qView, qDate]) => {
    const parsed = parseCalendarQuery(qView, qDate)
    if (parsed.view !== view.value) view.value = parsed.view
    if (parsed.date !== focusDate.value) focusDate.value = parsed.date
  }
)

function goToday() {
  focusDate.value = todayKey()
}

function goPrev() {
  focusDate.value = shiftPeriod(view.value, focusDate.value, -1)
}

function goNext() {
  focusDate.value = shiftPeriod(view.value, focusDate.value, 1)
}

function onMiniDate(date: string) {
  focusDate.value = date
}

function clearFilters() {
  processId.value = null
  clientId.value = null
  assigneeId.value = null
  departmentId.value = null
  priority.value = ''
  statusVisible.value = { todo: true, doing: true, done: true, dismissed: true }
}

const { data: filterSources } = await useAsyncData('work-calendar-filters', async () => {
  const month = focusDate.value.slice(0, 7)
  const [processes, clientsRes, members, departments] = await Promise.all([
    listProcesses({ reference_month: month }).catch(() => []),
    listClients({ per_page: 100, sort: 'name', direction: 'asc' }).catch(() => ({ data: [] as { id: number, name: string }[] })),
    listDirectory().catch(() => []),
    listDepartments().catch(() => [])
  ])
  return {
    processes,
    clients: clientsRes.data ?? [],
    members,
    departments
  }
}, { watch: [() => focusDate.value.slice(0, 7)] })

const processOptions = computed(() => (filterSources.value?.processes ?? []).map(p => ({
  label: p.name,
  value: p.id
})))

const clientOptions = computed(() => (filterSources.value?.clients ?? []).map(c => ({
  label: c.name,
  value: c.id
})))

const assigneeOptions = computed(() => (filterSources.value?.members ?? []).map(m => ({
  label: m.name,
  value: m.id
})))

const departmentOptions = computed(() => (filterSources.value?.departments ?? []).map(d => ({
  label: d.name,
  value: d.id
})))

function apiMessage(error: unknown): string | undefined {
  if (!error || typeof error !== 'object') return undefined
  const data = (error as { data?: { message?: string }, response?: { _data?: { message?: string } } }).data
  const nested = (error as { response?: { _data?: { message?: string } } }).response?._data
  const message = data?.message ?? nested?.message
  return typeof message === 'string' && message.length > 0 ? message : undefined
}

function apiStatus(error: unknown): number | undefined {
  if (!error || typeof error !== 'object') return undefined
  const record = error as {
    status?: number
    statusCode?: number
    response?: { status?: number }
    data?: { status?: number }
  }
  const code = record.status ?? record.statusCode ?? record.response?.status ?? record.data?.status
  return typeof code === 'number' ? code : undefined
}

const selectedTask = ref<WorkTask | null>(null)
const taskAnchor = ref<HTMLElement | null>(null)
const popoverOpen = ref(false)
const busyId = ref<number | null>(null)
const optimistic = ref(new Map<number, string>())

const displayByDay = computed(() => {
  const base = new Map(tasksByDay.value)
  for (const [id, day] of optimistic.value) {
    for (const [key, list] of base) {
      const next = list.filter(t => t.id !== id)
      if (next.length) base.set(key, next)
      else base.delete(key)
    }
    const task = tasks.value.find(t => t.id === id)
    if (!task) continue
    const list = base.get(day) ?? []
    base.set(day, [...list, { ...task, due_on: day }].sort((a, b) => a.title.localeCompare(b.title, 'pt-BR')))
  }
  return base
})

function openTask(task: WorkTask, event?: MouseEvent) {
  selectedTask.value = task
  taskAnchor.value = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null
  popoverOpen.value = true
}

async function advance(task: WorkTask) {
  const next = task.status === 'todo' ? 'doing' : task.status === 'doing' ? 'done' : null
  if (!next) return
  busyId.value = task.id
  try {
    await updateTask(task.id, { status: next })
    await reload()
    toast.add({ title: next === 'done' ? 'Tarefa concluída' : 'Tarefa em progresso', color: 'success' })
  } catch (error: unknown) {
    if (apiStatus(error) === 422) {
      toast.add({ title: 'Avanço bloqueado', description: apiMessage(error) ?? 'Aguardando etapas anteriores.', color: 'warning' })
    } else {
      toast.add({ title: 'Não foi possível avançar a tarefa', description: apiMessage(error), color: 'error' })
    }
  } finally {
    busyId.value = null
  }
}

async function moveBack(task: WorkTask) {
  const previous = task.status === 'doing' ? 'todo' : task.status === 'done' ? 'doing' : null
  if (!previous) return
  busyId.value = task.id
  try {
    await updateTask(task.id, { status: previous })
    await reload()
    toast.add({ title: 'Tarefa retornada', color: 'success' })
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível retornar a tarefa', description: apiMessage(error), color: 'error' })
  } finally {
    busyId.value = null
  }
}

async function assign(task: WorkTask, memberId: number | null) {
  busyId.value = task.id
  try {
    await updateTask(task.id, { assignee_member_id: memberId })
    await reload()
    toast.add({ title: memberId === null ? 'Responsável removido' : 'Responsável atualizado', color: 'success' })
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível atribuir o responsável', description: apiMessage(error), color: 'error' })
  } finally {
    busyId.value = null
  }
}

async function dismiss(task: WorkTask, reason: string) {
  busyId.value = task.id
  try {
    await updateTask(task.id, { status: 'dismissed', dismissal_reason: reason })
    popoverOpen.value = false
    await reload()
    toast.add({ title: 'Tarefa dispensada', color: 'success' })
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível dispensar a tarefa', description: apiMessage(error), color: 'error' })
  } finally {
    busyId.value = null
  }
}

async function onDrop(taskId: number, targetDay: string) {
  if (!canManageWork.value) return
  const task = tasks.value.find(t => t.id === taskId)
  if (!task || task.status === 'dismissed') return
  if (task.due_on === targetDay) return
  if (busyId.value === taskId) return

  const nextMap = new Map(optimistic.value)
  nextMap.set(taskId, targetDay)
  optimistic.value = nextMap
  busyId.value = taskId

  try {
    await updateTask(taskId, { due_on: targetDay })
    await reload()
  } catch (error: unknown) {
    toast.add({
      title: 'Não foi possível reagendar',
      description: apiMessage(error),
      color: 'error'
    })
  } finally {
    const cleared = new Map(optimistic.value)
    cleared.delete(taskId)
    optimistic.value = cleared
    busyId.value = null
  }
}

function isEditableTarget(target: EventTarget | null) {
  if (!(target instanceof HTMLElement)) return false
  const tag = target.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable
}

function onKeydown(event: KeyboardEvent) {
  if (isEditableTarget(event.target)) return
  if (event.key === 't' || event.key === 'T') {
    event.preventDefault()
    goToday()
    return
  }
  if (event.key === 'ArrowLeft') {
    event.preventDefault()
    goPrev()
  }
  if (event.key === 'ArrowRight') {
    event.preventDefault()
    goNext()
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <!-- Shell composition ported from nuxt-ui-templates/calendar `pages/[view]/[date].vue` -->
  <div class="isolate relative flex min-h-0 min-w-0 flex-1 overflow-hidden">
    <div
      v-if="isSidebarOpen"
      class="fixed inset-0 z-40 bg-black/40 lg:hidden"
      @click="isSidebarOpen = false"
    />

    <!--
      Rail owns width + padding (template USidebar chrome). Do not also set
      lg:w-64 on WorkCalendarSidebar — that double-width was spilling the mini calendar.
    -->
    <div
      class="z-50 flex min-h-0 min-w-0 shrink-0 flex-col self-stretch border-e border-default bg-default transition-transform lg:relative lg:z-0 lg:w-64 lg:max-w-64 lg:translate-x-0 lg:shadow-none"
      :class="isSidebarOpen
        ? 'fixed inset-y-0 start-0 w-72 max-w-[85vw] translate-x-0 p-2 pe-px shadow-2xl'
        : 'fixed inset-y-0 start-0 w-72 max-w-[85vw] -translate-x-full p-2 pe-px pointer-events-none lg:pointer-events-auto lg:max-w-64'"
    >
      <div class="mb-2 flex shrink-0 items-center justify-end lg:hidden">
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="soft"
          size="sm"
          aria-label="Fechar menu"
          class="rounded-full"
          @click="isSidebarOpen = false"
        />
      </div>

      <WorkCalendarSidebar
        class="min-h-0 min-w-0 flex-1"
        :model-date="focusDate"
        :status-visible="statusVisible"
        :process-id="processId"
        :client-id="clientId"
        :assignee-id="assigneeId"
        :department-id="departmentId"
        :priority="priority"
        :process-options="processOptions"
        :client-options="clientOptions"
        :assignee-options="assigneeOptions"
        :department-options="departmentOptions"
        @update:model-date="onMiniDate"
        @update:status-visible="statusVisible = $event"
        @update:process-id="processId = $event"
        @update:client-id="clientId = $event"
        @update:assignee-id="assigneeId = $event"
        @update:department-id="departmentId = $event"
        @update:priority="priority = $event"
        @clear="clearFilters"
      />
    </div>

    <div class="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden -z-1">
      <header class="absolute top-0 inset-x-0 z-10 flex items-center gap-2 sm:gap-4 h-[calc(var(--ui-header-height)+0.5rem)] px-4 pt-2 border-b border-default glass-material [view-transition-name:header]">
        <div class="pointer-events-none absolute inset-0 -z-10 bg-(--glass-bg) bg-linear-to-b from-default from-40% to-transparent" />

        <div class="flex min-w-0 flex-1 items-center gap-2">
          <UButton
            icon="i-lucide-menu"
            color="neutral"
            variant="soft"
            size="sm"
            aria-label="Abrir menu"
            class="lg:hidden shrink-0 rounded-full"
            @click="isSidebarOpen = true"
          />

          <h1 class="flex min-w-0 flex-1 items-baseline gap-1.5 text-xl tracking-tight sm:text-2xl">
            <span class="truncate font-bold text-highlighted first-letter:uppercase">{{ title.months }}</span>
            <span class="font-normal text-muted">{{ title.year }}</span>
          </h1>
        </div>

        <UTabs
          :model-value="view"
          :items="viewItems"
          :content="false"
          color="neutral"
          size="sm"
          class="mx-auto w-20 sm:w-42 lg:w-48"
          :ui="{ trigger: 'p-1 lg:p-1.5' }"
          @update:model-value="(value) => { view = value as CalendarView }"
        >
          <template #default="{ item }">
            <span class="sm:hidden">{{ item.label.charAt(0) }}</span>
            <span class="hidden sm:inline">{{ item.label }}</span>
          </template>
        </UTabs>

        <div class="flex items-center justify-end gap-2 md:flex-1">
          <div class="flex items-center gap-1">
            <UTooltip text="Anterior" :kbds="['arrowleft']">
              <UButton
                icon="i-lucide-chevron-left"
                color="neutral"
                variant="soft"
                size="sm"
                aria-label="Período anterior"
                class="rounded-full"
                :disabled="isLoading"
                @click="goPrev"
              />
            </UTooltip>
            <UTooltip text="Hoje" :kbds="['t']">
              <UButton
                label="Hoje"
                color="neutral"
                variant="soft"
                size="sm"
                class="hidden rounded-full sm:inline-flex"
                :disabled="isLoading"
                @click="goToday"
              />
            </UTooltip>
            <UTooltip text="Próximo" :kbds="['arrowright']">
              <UButton
                icon="i-lucide-chevron-right"
                color="neutral"
                variant="soft"
                size="sm"
                aria-label="Próximo período"
                class="rounded-full"
                :disabled="isLoading"
                @click="goNext"
              />
            </UTooltip>
            <UTooltip text="Atualizar">
              <UButton
                icon="i-lucide-refresh-cw"
                color="neutral"
                variant="soft"
                size="sm"
                aria-label="Atualizar calendário"
                class="rounded-full"
                :loading="isLoading"
                @click="refresh"
              />
            </UTooltip>
          </div>
        </div>
      </header>

      <div class="flex min-h-0 flex-1 flex-col">
        <ErrorRetryAlert
          v-if="showError"
          class="relative z-20 m-3 mt-[calc(var(--ui-header-height,4rem)+1rem)]"
          title="Não foi possível carregar o calendário"
          @retry="retry"
        />

        <UEmpty
          v-else-if="!isLoading && !filteredTasks.length"
          class="my-auto py-16"
          icon="i-lucide-calendar-days"
          title="Nenhuma tarefa com prazo"
          description="Rotinas geradas com vencimento aparecem aqui."
          variant="naked"
        />

        <WorkCalendarWorkMonthGrid
          v-else-if="view === 'month'"
          :anchor-date="focusDate"
          :tasks-by-day="displayByDay"
          :loading="isLoading"
          :can-drag="canManageWork"
          :busy-id="busyId"
          @select="openTask"
          @select-date="onMiniDate"
          @drop="onDrop"
        />

        <WorkCalendarWorkDayColumns
          v-else
          :day-keys="dayColumnKeys"
          :tasks-by-day="displayByDay"
          :loading="isLoading"
          :single="view === 'day'"
          @select="openTask"
        />
      </div>
    </div>

    <WorkCalendarTaskPopover
      v-model:open="popoverOpen"
      :task="selectedTask"
      :anchor="taskAnchor"
      :can-manage="canManageWork"
      :busy="busyId === selectedTask?.id"
      :member-options="assigneeOptions"
      @advance="advance"
      @back="moveBack"
      @assign="assign"
      @dismiss="dismiss"
    />
  </div>
</template>
