import { parseCalendarQuery } from '~/utils/workCalendar'
import type { WorkTaskPriority, WorkTaskStatus } from '~/types/work'

const statuses: WorkTaskStatus[] = ['todo', 'doing', 'done', 'dismissed']

export function useWorkCalendarNavigation() {
  const route = useRoute()
  const defaults = () => ({
    ...parseCalendarQuery(undefined, undefined), processId: null as number | null,
    clientId: null as number | null, assigneeId: null as number | null,
    departmentId: null as number | null, priority: '' as WorkTaskPriority | '', statuses: [...statuses]
  })
  function normalize(value: unknown) {
    const input = value && typeof value === 'object' ? value as Record<string, unknown> : {}
    const id = (value: unknown) => Number.isSafeInteger(value) && Number(value) > 0 ? Number(value) : null
    return {
      ...parseCalendarQuery(input.view, input.date),
      processId: id(input.processId), clientId: id(input.clientId),
      assigneeId: id(input.assigneeId), departmentId: id(input.departmentId),
      priority: (['low', 'medium', 'high', 'urgent'].includes(String(input.priority)) ? input.priority : '') as WorkTaskPriority | '',
      statuses: Array.isArray(input.statuses) ? input.statuses.filter((status): status is WorkTaskStatus => statuses.includes(status as WorkTaskStatus)) : [...statuses]
    }
  }
  const state = useListNavigationState('work-calendar', { defaults, normalize })
  watch(() => [route.query.view, route.query.date] as const, ([view, date], previous) => {
    if (view === undefined && date === undefined && previous === undefined) return
    state.value = { ...state.value, ...parseCalendarQuery(view, date) }
  }, { immediate: true, flush: 'sync' })
  const statusVisible = computed<Record<WorkTaskStatus, boolean>>({
    get: () => Object.fromEntries(statuses.map(status => [status, state.value.statuses.includes(status)])) as Record<WorkTaskStatus, boolean>,
    set: (value) => { state.value = { ...state.value, statuses: statuses.filter(status => value[status]) } }
  })
  return {
    view: listNavigationField(state, 'view'), focusDate: listNavigationField(state, 'date'),
    processId: listNavigationField(state, 'processId'), clientId: listNavigationField(state, 'clientId'),
    assigneeId: listNavigationField(state, 'assigneeId'), departmentId: listNavigationField(state, 'departmentId'),
    priority: listNavigationField(state, 'priority'), statusVisible
  }
}
