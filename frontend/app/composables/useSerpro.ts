import type {
  MonitoringClient,
  MonitoringMessage,
  MonitoringObligationSummary,
  MonitoringOverview,
  MonitoringSituacao,
  SerproAssociateResult,
  SerproAuthorizationTerm,
  SerproConnectivityResult,
  SerproConnectionMetadata,
  SerproConnectionPayload,
  SerproSyncRun,
  SerproSyncRunDetail
} from '~/types/serpro'
import { queryOf } from './useApiQuery'

export interface ObligationListParams {
  situacao?: MonitoringSituacao | ''
  q?: string
  tag_id?: number[]
  page?: number
}

export function useSerpro() {
  const { $api } = useNuxtApp()

  async function overview() {
    const res = await $api<{ data: MonitoringOverview }>('/serpro/monitoring/overview')
    return res.data
  }

  /**
   * The whole envelope, not `res.data` alone. The page renders the counters and
   * `attention_reasons` from the summary and the rows from `data_rows`; a page
   * that issued two calls to render one list could disagree with itself.
   */
  async function listObligation(obligation: string, params: ObligationListParams = {}) {
    return $api<{ data: MonitoringObligationSummary, data_rows: MonitoringClient[] }>(
      `/serpro/monitoring/obligations/${obligation}`,
      { query: queryOf(params) }
    )
  }

  /**
   * Separate from listing rows, and load-bearing. `MSGDETALHAMENTO62` says that
   * executing it characterizes ciência da intimação (D19), so the body is
   * fetched only after the member consents — never as a side effect of drawing
   * a row. Keeping one function that did both would make the legal act
   * unreachable to gate.
   */
  async function readMessage(obligation: string, id: number) {
    const res = await $api<{ data: MonitoringMessage }>(
      `/serpro/monitoring/obligations/${obligation}/messages/${id}`
    )
    return res.data
  }

  async function associateClients(obligation: string, clientIds: number[]) {
    const res = await $api<{ data: SerproAssociateResult }>(
      `/serpro/monitoring/obligations/${obligation}/clients`,
      { method: 'POST', body: { client_ids: clientIds } }
    )
    return res.data
  }

  async function connection() {
    const res = await $api<{ data: SerproConnectionMetadata }>('/serpro/connection')
    return res.data
  }

  /**
   * `FormData`, like `useClients().uploadCertificate`, because the certificate
   * is a file. The three optional fields are appended only when present, which
   * is what makes an omitted `consumer_secret` mean "keep the stored one"
   * instead of "store an empty string".
   */
  async function saveConnection(payload: SerproConnectionPayload) {
    const body = new FormData()
    body.append('consumer_key', payload.consumer_key)
    if (payload.consumer_secret) body.append('consumer_secret', payload.consumer_secret)
    if (payload.certificate) body.append('certificate', payload.certificate)
    if (payload.password) body.append('password', payload.password)
    const res = await $api<{ data: SerproConnectionMetadata }>('/serpro/connection', { method: 'PUT', body })
    return res.data
  }

  async function testConnectivity() {
    const res = await $api<{ data: SerproConnectivityResult }>('/serpro/connectivity', { method: 'POST' })
    return res.data
  }

  async function authorizationTerm() {
    const res = await $api<{ data: SerproAuthorizationTerm }>('/serpro/authorization-terms')
    return res.data
  }

  async function syncRuns(params: { page?: number } = {}) {
    return $api<{ data: SerproSyncRun[], meta?: { total: number, current_page?: number, last_page?: number } }>(
      '/serpro/sync-runs',
      { query: queryOf(params) }
    )
  }

  async function showSyncRun(id: number) {
    const res = await $api<{ data: SerproSyncRunDetail }>(`/serpro/sync-runs/${id}`)
    return res.data
  }

  async function resyncRun(id: number) {
    const res = await $api<{ data: SerproSyncRun }>(`/serpro/sync-runs/${id}/resync`, { method: 'POST' })
    return res.data
  }

  return {
    overview,
    listObligation,
    readMessage,
    associateClients,
    connection,
    saveConnection,
    testConnectivity,
    authorizationTerm,
    syncRuns,
    showSyncRun,
    resyncRun
  }
}
