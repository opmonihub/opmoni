export type WorkTaskStatus = 'todo' | 'doing' | 'done' | 'dismissed'
export type WorkTaskPriority = 'low' | 'medium' | 'high' | 'urgent'

export interface WorkDepartmentRef { id: number, name: string, color: string }

export interface WorkTemplateStep { id: number, title: string, department_id: number | null, department: WorkDepartmentRef | null, description: string | null, due_day: number, priority: WorkTaskPriority, order: number, default_assignee_member_id: number | null }

export interface WorkTemplateException { client_id: number, kind: 'added' | 'removed' }

export interface WorkTemplateTag { id: number, name: string }

export interface WorkTemplate { id: number, name: string, description: string | null, cascade: boolean, generate_day: number, due_day: number, is_active: boolean, regimes: string[], tags?: WorkTemplateTag[], exceptions?: WorkTemplateException[], steps?: WorkTemplateStep[] }
export interface WorkProcess { id: number, name: string, status: string, due_on: string | null, reference_month: string | null, template?: { id: number, name: string, cascade?: boolean }, client?: { id: number, name: string }, progress?: { total: number, done: number, dismissed: number, open: number, ratio: number } }

export interface WorkGeneratedProcess { id: number, name: string, client_id: number }

export interface WorkPreviewRow { client: { id: number, name: string }, reason: 'rule' | 'added' }

export interface WorkTemplatePayload {
  name: string
  description?: string | null
  cascade?: boolean
  generate_day?: number
  due_day?: number
  is_active?: boolean
  regimes?: string[]
  tag_ids?: number[]
  exceptions?: WorkTemplateException[]
  steps?: { id?: number, title: string, department_id: number | null, description?: string | null, due_day: number, priority: WorkTaskPriority, order: number, default_assignee_member_id?: number | null }[]
}
export interface WorkTask { id: number, title: string, department_id: number | null, department: WorkDepartmentRef | null, description: string | null, status: WorkTaskStatus, due_on: string | null, priority: WorkTaskPriority, assignee_member_id: number | null, order: number, cascade_locked?: boolean, process?: { id: number, name: string, cascade?: boolean | null, client?: { id: number, name: string } } }
export interface WorkProcessDetail extends WorkProcess { tasks: WorkTask[] }
export interface WorkGroupedProcess {
  process: {
    id: number
    name: string
    status: string
    due_on: string | null
    reference_month: string | null
    cascade?: boolean
    template: { id: number, name: string, cascade?: boolean } | null
  }
  totals: { tasks: number, done: number, dismissed: number, open: number }
  progress: { total: number, done: number, dismissed: number, open: number, ratio: number }
  ratio: number
  tasks: WorkTask[]
}

export interface WorkGroupedClient {
  client: { id: number, name: string }
  totals: { processes: number, tasks: number }
  processes: WorkGroupedProcess[]
}
