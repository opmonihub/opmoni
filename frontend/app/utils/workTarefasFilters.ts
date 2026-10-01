import type { DataTableFilterColumn, DataTableFilterModel } from '../components/data-table/filter-model.ts'
import { columnType, matchesFilters } from '../components/data-table/filter-model.ts'
import { priorityPresentation, statusPresentation } from '../composables/useWorkPresentation.ts'
import type { WorkGroupedClient, WorkTask, WorkTaskPriority, WorkTaskStatus } from '../types/work.ts'

export type WorkTarefasLeaf = {
  id: number
  clientId: number
  clientName: string
  processId: number
  processName: string
  cascade: boolean
  cascadeLocked?: boolean
  order: number
  title: string
  status: WorkTaskStatus
  department_id: number | null
  departmentName: string
  due_on: string | null
  priority: WorkTaskPriority
  assignee_member_id: number | null
}

export type WorkDepartmentFilterOption = {
  id: number
  name: string
}

export type WorkTarefasFilterOptions = {
  clients: Array<{ id: number, name: string }>
  processes: Array<{ id: number, name: string }>
  departments: WorkDepartmentFilterOption[]
  assignees: Array<{ id: number | null, label: string }>
  includeAssignee: boolean
}

export type WorkTaskDueRange = {
  from?: string
  to?: string
}

const STATUS_OPTIONS: WorkTaskStatus[] = ['todo', 'doing', 'done', 'dismissed']
const PRIORITY_OPTIONS: WorkTaskPriority[] = ['low', 'medium', 'high', 'urgent']

export function workTarefasFilterColumns(options: WorkTarefasFilterOptions): DataTableFilterColumn[] {
  const columns: DataTableFilterColumn[] = [
    {
      id: 'status',
      label: 'Status',
      icon: 'i-lucide-circle-dot',
      type: 'option',
      options: STATUS_OPTIONS.map(status => ({
        label: statusPresentation(status).label,
        value: status,
        color: statusPresentation(status).color
      }))
    },
    {
      id: 'priority',
      label: 'Prioridade',
      icon: 'i-lucide-flag',
      type: 'option',
      options: PRIORITY_OPTIONS.map(priority => ({
        label: priorityPresentation(priority).label,
        value: priority,
        color: priorityPresentation(priority).color
      }))
    },
    {
      id: 'department',
      label: 'Departamento',
      icon: 'i-lucide-building-2',
      type: 'option',
      options: [
        ...options.departments.map(department => ({ label: department.name, value: String(department.id) })),
        { label: 'Sem departamento', value: 'none' }
      ]
    },
    {
      id: 'clientId',
      label: 'Cliente',
      icon: 'i-lucide-users',
      type: 'option',
      options: options.clients.map(client => ({
        label: client.name,
        value: String(client.id)
      }))
    },
    {
      id: 'processId',
      label: 'Processo',
      icon: 'i-lucide-layers',
      type: 'option',
      options: options.processes.map(process => ({
        label: process.name,
        value: String(process.id)
      }))
    },
    {
      id: 'cascade',
      label: 'Cascata',
      icon: 'i-lucide-link',
      type: 'option',
      options: [
        { label: 'Com cascata', value: 'true', color: 'warning' },
        { label: 'Sem cascata', value: 'false', color: 'neutral' }
      ]
    }
  ]

  if (options.includeAssignee) {
    columns.splice(3, 0, {
      id: 'assignee',
      label: 'Responsável',
      icon: 'i-lucide-user-round',
      type: 'option',
      options: options.assignees.map(assignee => ({
        label: assignee.label,
        value: assignee.id === null ? 'none' : String(assignee.id)
      }))
    })
  }

  return columns
}

function taskDepartmentKey(task: Pick<WorkTask, 'department_id'>): string {
  return task.department_id === null ? 'none' : String(task.department_id)
}

function readLeaf(leaf: WorkTarefasLeaf, columnId: string): unknown {
  switch (columnId) {
    case 'status': return leaf.status
    case 'priority': return leaf.priority
    case 'department': return leaf.department_id === null ? 'none' : String(leaf.department_id)
    case 'clientId': return String(leaf.clientId)
    case 'processId': return String(leaf.processId)
    case 'cascade': return leaf.cascade ? 'true' : 'false'
    case 'assignee': return leaf.assignee_member_id === null ? 'none' : String(leaf.assignee_member_id)
    default: return undefined
  }
}

function readTask(task: WorkTask, columnId: string): unknown {
  switch (columnId) {
    case 'status': return task.status
    case 'priority': return task.priority
    case 'department': return taskDepartmentKey(task)
    case 'clientId': return String(task.process?.client?.id ?? 0)
    case 'processId': return String(task.process?.id ?? 0)
    case 'cascade': return task.process?.cascade ? 'true' : 'false'
    case 'assignee': return task.assignee_member_id === null ? 'none' : String(task.assignee_member_id)
    default: return undefined
  }
}

function typeOf(columnId: string, columns: DataTableFilterColumn[]) {
  return columnType(columns.find(column => column.id === columnId))
}

function matchesDueRange(dueOn: string | null, range: WorkTaskDueRange): boolean {
  const from = range.from?.trim()
  const to = range.to?.trim()
  if (!from && !to) return true
  if (!dueOn) return false
  if (from && dueOn < from) return false
  if (to && dueOn > to) return false
  return true
}

export function filterWorkTarefasLeaves(
  leaves: WorkTarefasLeaf[],
  filters: DataTableFilterModel[],
  columns: DataTableFilterColumn[],
  search: string
): WorkTarefasLeaf[] {
  const term = search.trim().toLocaleLowerCase('pt-BR')
  return leaves.filter((leaf) => {
    if (term && !leaf.title.toLocaleLowerCase('pt-BR').includes(term)
      && !leaf.clientName.toLocaleLowerCase('pt-BR').includes(term)
      && !leaf.processName.toLocaleLowerCase('pt-BR').includes(term)
      && !leaf.departmentName.toLocaleLowerCase('pt-BR').includes(term)) {
      return false
    }
    if (!filters.length) return true
    return matchesFilters(
      filters,
      columnId => readLeaf(leaf, columnId),
      columnId => typeOf(columnId, columns)
    )
  })
}

export function filterWorkTasks(
  tasks: WorkTask[],
  filters: DataTableFilterModel[],
  columns: DataTableFilterColumn[],
  search: string,
  dueRange: WorkTaskDueRange = {}
): WorkTask[] {
  const term = search.trim().toLocaleLowerCase('pt-BR')
  return tasks.filter((task) => {
    if (!matchesDueRange(task.due_on, dueRange)) return false
    const clientName = task.process?.client?.name ?? ''
    const processName = task.process?.name ?? ''
    const departmentName = workDepartmentLabel(task)
    if (term && !task.title.toLocaleLowerCase('pt-BR').includes(term)
      && !clientName.toLocaleLowerCase('pt-BR').includes(term)
      && !processName.toLocaleLowerCase('pt-BR').includes(term)
      && !departmentName.toLocaleLowerCase('pt-BR').includes(term)) {
      return false
    }
    if (!filters.length) return true
    return matchesFilters(
      filters,
      columnId => readTask(task, columnId),
      columnId => typeOf(columnId, columns)
    )
  })
}

export function flattenGroupedTasks(
  groups: Array<{
    client: { id: number, name: string }
    processes: Array<{
      process: { id: number, name: string, cascade?: boolean }
      tasks: WorkTask[]
    }>
  }>
): WorkTask[] {
  const tasks: WorkTask[] = []

  for (const group of groups) {
    for (const entry of group.processes) {
      for (const task of entry.tasks) {
        tasks.push({
          ...task,
          process: {
            id: entry.process.id,
            name: entry.process.name,
            cascade: Boolean(entry.process.cascade),
            client: { id: group.client.id, name: group.client.name }
          }
        })
      }
    }
  }

  return tasks
}

export function tasksForWorkScope(groups: WorkGroupedClient[], unscoped: WorkTask[], scope: 'month' | 'undated'): WorkTask[] {
  if (scope === 'undated') return unscoped.filter(task => task.due_on === null)

  return [
    ...flattenGroupedTasks(groups),
    ...unscoped.filter(task => task.due_on !== null)
  ]
}

export function workDepartmentLabel(task: Pick<WorkTask, 'department'>): string {
  return task.department?.name ?? 'Sem departamento'
}

export function tasksToLeaves(tasks: WorkTask[]): WorkTarefasLeaf[] {
  return tasks.map(task => ({
    id: task.id,
    clientId: task.process?.client?.id ?? 0,
    clientName: task.process?.client?.name ?? 'Sem cliente',
    processId: task.process?.id ?? 0,
    processName: task.process?.name ?? 'Processo avulso',
    cascade: Boolean(task.process?.cascade),
    cascadeLocked: task.cascade_locked,
    order: task.order,
    title: task.title,
    status: task.status,
    department_id: task.department_id,
    departmentName: workDepartmentLabel(task),
    due_on: task.due_on,
    priority: task.priority,
    assignee_member_id: task.assignee_member_id
  })).sort((a, b) => {
    if (a.clientName !== b.clientName) return a.clientName.localeCompare(b.clientName, 'pt-BR')
    if (a.processName !== b.processName) return a.processName.localeCompare(b.processName, 'pt-BR')
    return a.order - b.order
  })
}
