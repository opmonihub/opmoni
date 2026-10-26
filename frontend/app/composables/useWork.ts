import type { WorkGeneratedProcess, WorkGroupedClient, WorkPreviewRow, WorkProcess, WorkProcessDetail, WorkTask, WorkTemplate, WorkTemplatePayload } from '~/types/work'
import { queryOf } from './useApiQuery'

export function useWork() {
  const { $api } = useNuxtApp()
  async function listTemplates(options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkTemplate[] }>('/process-templates', { signal: options.signal })
    return res.data
  }
  async function previewTemplate(id: number, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkPreviewRow[] }>(`/process-templates/${id}/preview`, { signal: options.signal })
    return res.data
  }
  async function generateTemplate(id: number, referenceMonth: string) {
    const res = await $api<{ data: WorkGeneratedProcess[] }>(`/process-templates/${id}/generate`, { method: 'POST', body: { reference_month: referenceMonth } })
    return res.data
  }
  async function showTemplate(id: number, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkTemplate }>(`/process-templates/${id}`, { signal: options.signal })
    return res.data
  }
  async function createTemplate(body: WorkTemplatePayload) {
    const res = await $api<{ data: WorkTemplate }>('/process-templates', { method: 'POST', body })
    return res.data
  }
  async function updateTemplate(id: number, body: WorkTemplatePayload) {
    const res = await $api<{ data: WorkTemplate }>(`/process-templates/${id}`, { method: 'PATCH', body })
    return res.data
  }
  async function listProcesses(params: { template_id?: number, reference_month?: string, client_id?: number, status?: string } = {}, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkProcess[] }>('/processes', { signal: options.signal, query: queryOf(params) })
    return res.data
  }
  async function showProcess(id: number, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkProcessDetail }>(`/processes/${id}`, { signal: options.signal })
    return res.data
  }
  async function listTasks(params: {
    process_id?: number
    client_id?: number
    status?: string
    assignee_member_id?: number
    department_id?: number
    priority?: string
    due_from?: string
    due_to?: string
  } = {}, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkTask[] }>('/tasks', { signal: options.signal, query: queryOf(params) })
    return res.data
  }
  async function updateTask(id: number, body: {
    status?: string
    dismissal_reason?: string
    assignee_member_id?: number | null
    due_on?: string | null
  }) {
    const res = await $api<{ data: WorkTask }>(`/tasks/${id}`, { method: 'PATCH', body })
    return res.data
  }
  async function calendar(from: string, to: string, params: {
    process_id?: number
    client_id?: number
    status?: string
    assignee_member_id?: number
    department_id?: number
    priority?: string
  } = {}, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkTask[] }>('/work/calendar', {
      signal: options.signal, query: queryOf({ from, to, ...params })
    })
    return res.data
  }
  async function grouped(referenceMonth: string, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkGroupedClient[] }>('/work/grouped', { signal: options.signal, query: { reference_month: referenceMonth } })
    return res.data
  }
  async function unscopedTasks(referenceMonth: string, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: WorkTask[] }>('/work/tasks/unscoped', { signal: options.signal, query: { reference_month: referenceMonth, include_undated: 1 } })
    return res.data
  }
  return { listTemplates, showTemplate, createTemplate, updateTemplate, previewTemplate, generateTemplate, listProcesses, showProcess, listTasks, updateTask, calendar, grouped, unscopedTasks }
}
