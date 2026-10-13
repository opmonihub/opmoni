import type {
  Department,
  DepartmentColor,
  DepartmentUpdatePayload,
  DepartmentWritePayload
} from '~/types/team'
import { queryOf } from './useApiQuery'

export function useDepartments() {
  const { $api } = useNuxtApp()

  async function list(params: Record<string, unknown> = {}) {
    const response = await $api<{ data: Department[] }>('/departments', {
      query: queryOf(params)
    })
    return response.data
  }

  async function create(body: DepartmentWritePayload) {
    const response = await $api<{ data: Department }>('/departments', {
      method: 'POST', body
    })
    return response.data
  }

  async function update(id: number, body: DepartmentUpdatePayload) {
    const response = await $api<{ data: Department }>(`/departments/${id}`, {
      method: 'PATCH', body
    })
    return response.data
  }

  async function remove(id: number) {
    await $api(`/departments/${id}`, { method: 'DELETE' })
  }

  return { list, create, update, remove }
}

export const departmentColorOptions: { label: string, value: DepartmentColor }[] = [
  { label: 'Neutro', value: 'neutral' },
  { label: 'Primária', value: 'primary' },
  { label: 'Sucesso', value: 'success' },
  { label: 'Info', value: 'info' },
  { label: 'Aviso', value: 'warning' },
  { label: 'Erro', value: 'error' }
]
