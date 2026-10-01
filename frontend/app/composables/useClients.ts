import type {
  Client,
  ClientBulkDeletion,
  ClientCertificateUpload,
  ClientSheet,
  ClientListParams,
  ClientPortfolioAnalytics,
  ClientPortfolioSummary,
  ClientSavedFilter,
  ClientSelectionSnapshot,
  ClientTag,
  ClientTagAssignment,
  ClientTagColor,
  ClientUpdatePayload,
  ClientWritePayload,
  CnpjPreview,
  CnpjRefreshPreview
} from '~/types/client'
import type { ClientMonitoringModules, SerproAssociateResult } from '~/types/serpro'
import { queryOf } from './useApiQuery'

export function useClients() {
  const { $api } = useNuxtApp()

  async function list(params: ClientListParams) {
    return $api<{
      data: ClientSheet[]
      meta?: { total: number, mode?: 'sheet' | 'paged', current_page?: number, last_page?: number }
    }>('/clients', { query: queryOf(params) })
  }

  async function show(id: number) {
    const response = await $api<{ data: Client }>(`/clients/${id}`)
    return response.data
  }

  async function portfolioSummary(params: Pick<ClientListParams, 'q' | 'status' | 'tax_regime' | 'tag_id' | 'certificate_status' | 'poa_status'> & { client_id?: number }) {
    const response = await $api<{ data: ClientPortfolioSummary }>('/clients/summary', { query: queryOf(params) })
    return response.data
  }

  async function portfolioAnalytics(params: Pick<ClientListParams, 'q' | 'status' | 'tax_regime' | 'tag_id' | 'certificate_status' | 'poa_status'> & { client_id?: number } = {}) {
    const response = await $api<{ data: ClientPortfolioAnalytics }>('/clients/analytics', { query: queryOf(params) })
    return response.data
  }

  async function lookupCnpj(cnpj: string) {
    const response = await $api<{ data: CnpjPreview }>('/clients/cnpj-lookup', {
      method: 'POST', body: { tax_id: cnpj }
    })
    return response.data
  }

  async function create(payload: ClientWritePayload) {
    const response = await $api<{ data: Client }>('/clients', { method: 'POST', body: payload })
    return response.data
  }

  async function update(id: number, payload: ClientUpdatePayload) {
    const response = await $api<{ data: Client }>(`/clients/${id}`, { method: 'PATCH', body: payload })
    return response.data
  }

  async function refreshPreview(id: number) {
    const response = await $api<{ data: CnpjRefreshPreview }>(`/clients/${id}/cnpj-refresh-preview`, { method: 'POST' })
    return response.data
  }

  async function refreshCnpj(id: number) {
    const response = await $api<{ data: Client }>(`/clients/${id}/cnpj-refresh`, { method: 'POST' })
    return response.data
  }

  async function remove(id: number) {
    await $api(`/clients/${id}`, { method: 'DELETE' })
  }

  async function createSelection(body: Pick<ClientListParams, 'q' | 'status' | 'tax_regime' | 'deadline_status' | 'certificate_status' | 'poa_status' | 'tag_id' | 'view'>) {
    return $api<{ data: ClientSelectionSnapshot }>('/clients/selections', { method: 'POST', body })
  }

  async function selectionPresence(id: string, ids: number[]) {
    return $api<{ data: { ids: number[] } }>(`/clients/selections/${id}/presence`, { method: 'POST', body: { ids } })
  }

  async function bulkDelete(body: { ids?: number[], selection_id?: string, excluded_ids?: number[] }) {
    return $api<{ data: ClientBulkDeletion }>('/clients/bulk-deletions', { method: 'POST', body })
  }

  async function bulkDeletion(id: string) {
    return $api<{ data: ClientBulkDeletion }>(`/clients/bulk-deletions/${id}`)
  }

  async function listSavedFilters() {
    return $api<{ data: ClientSavedFilter[] }>('/clients/saved-filters')
  }

  async function createSavedFilter(body: { name: string, q?: string | null, filters: ClientSavedFilter['filters'] }) {
    const response = await $api<{ data: ClientSavedFilter }>('/clients/saved-filters', { method: 'POST', body })
    return response.data
  }

  async function deleteSavedFilter(id: number) {
    await $api(`/clients/saved-filters/${id}`, { method: 'DELETE' })
  }

  async function listTags() {
    return $api<{ data: ClientTag[] }>('/tags')
  }

  async function createTag(body: { name: string, color: ClientTagColor }) {
    const response = await $api<{ data: ClientTag }>('/tags', { method: 'POST', body })
    return response.data
  }

  async function updateTag(id: number, body: { name?: string, color?: ClientTagColor }) {
    const response = await $api<{ data: ClientTag }>(`/tags/${id}`, { method: 'PATCH', body })
    return response.data
  }

  async function deleteTag(id: number) {
    await $api(`/tags/${id}`, { method: 'DELETE' })
  }

  async function assignTags(body: ClientTagAssignment) {
    return $api<{ data: { clients: number } }>('/clients/tags', { method: 'POST', body })
  }

  async function uploadCertificate(id: number, file: File, password: string) {
    const body = new FormData()
    body.append('certificate', file)
    body.append('password', password)
    return $api<ClientCertificateUpload>(`/clients/${id}/certificate`, { method: 'POST', body })
  }

  async function removeCertificate(id: number) {
    await $api(`/clients/${id}/certificate`, { method: 'DELETE' })
  }

  async function monitoringModules(id: number) {
    const response = await $api<{ data: ClientMonitoringModules }>(`/clients/${id}/monitoring-modules`)
    return response.data
  }

  async function confirmMonitoringModules(id: number, obligations: string[]) {
    const response = await $api<{ data: SerproAssociateResult }>(`/clients/${id}/monitoring-modules`, {
      method: 'POST', body: { obligations }
    })
    return response.data
  }

  return { list, show, portfolioSummary, portfolioAnalytics, lookupCnpj, create, update, refreshPreview, refreshCnpj, remove, createSelection, selectionPresence, bulkDelete, bulkDeletion, listSavedFilters, createSavedFilter, deleteSavedFilter, listTags, createTag, updateTag, deleteTag, assignTags, uploadCertificate, removeCertificate, monitoringModules, confirmMonitoringModules }
}
