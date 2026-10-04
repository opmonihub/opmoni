import type {
  MonitoringClient,
  MonitoringMessage,
  MonitoringObligationResponse,
  MonitoringOverview,
  MonitoringSituacao,
  SerproAccountCertificate,
  SerproAssociateResult,
  SerproAuthorizationTerm,
  SerproConnectivityResult,
  SerproConnectionMetadata,
  SerproConnectionPayload,
  SerproManualSearchQuota,
  SerproObligationScheduleRow,
  SerproScheduleMap,
  SerproSearchDocumentsPayload,
  SerproSyncRun,
  SerproSyncRunDetail
} from '~/types/serpro'
import { scheduleMapFromRows, scheduleRowsFromMap } from '~/utils/serproSchedules'
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
    return $api<{ data: MonitoringObligationResponse, data_rows: MonitoringClient[] }>(
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
   *
   * `POST` with `ciencia: true`: the backend refuses the read without it, so a
   * prefetch or a retried GET can never register the act.
   */
  async function readMessage(obligation: string, clientId: number, id: number) {
    const res = await $api<{ data: MonitoringMessage }>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/messages/${id}`,
      { method: 'POST', body: { ciencia: true } }
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

  /**
   * The manual search is a request, not a result: the endpoint answers `202`
   * and the runs happen in background, the rows showing as `Processando` while
   * they last. The quota the backend validates is per client×document —
   * a client over it comes back as a `422`, and the caller is the one who can
   * name the clients, so this function does not swallow anything.
   */
  async function searchDocuments(obligation: string, payload: SerproSearchDocumentsPayload) {
    await $api(`/serpro/monitoring/obligations/${obligation}/clients/search`, { method: 'POST', body: payload })
  }

  /**
   * The month's used/limit pair per client of the obligation, for the search
   * modal's quota column. The modal draws what arrived; a client missing here
   * renders the em dash, not a zero.
   */
  async function searchQuota(obligation: string) {
    const res = await $api<{ data: SerproManualSearchQuota[] }>(
      `/serpro/monitoring/obligations/${obligation}/search-quota`
    )
    return res.data
  }

  /**
   * The Account's scheduled-search day per document. The wire is a list of
   * `{obligation, day}` rows — only the documents with a scheduled day travel
   * in either direction; a document absent from the list has no automatic
   * search. The composable hands the page the map the form reads and writes.
   */
  async function schedules() {
    const res = await $api<{ data: SerproObligationScheduleRow[] }>('/serpro/obligation-schedules')
    return scheduleMapFromRows(res.data)
  }

  async function setSchedules(map: SerproScheduleMap) {
    const res = await $api<{ data: SerproObligationScheduleRow[] }>(
      '/serpro/obligation-schedules',
      { method: 'PUT', body: { schedules: scheduleRowsFromMap(map) } }
    )
    return scheduleMapFromRows(res.data)
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
    // "Usar o e-CNPJ da conta 1" viaja como flag e não como arquivo: os bytes
    // já estão na linha corrente de `account_certificates`, e mandá-los de novo
    // seria a segunda cópia que a spec proíbe.
    if (payload.use_account_certificate) body.append('use_account_certificate', '1')
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

  /**
   * The e-CNPJ the office has delivered, or a throw on `404` — which is this
   * route's ordinary answer for an office that has delivered none, and is left to
   * the caller to read, because the endpoint deliberately does not answer `200`
   * with an empty object.
   *
   * The upload is the office's one and only participation, and it is not a
   * signature: what comes back is metadata, and the term that is built from it is
   * signed, submitted and renewed by the platform.
   */
  async function accountCertificate() {
    const res = await $api<{ data: SerproAccountCertificate }>('/serpro/account-certificate')
    return res.data
  }

  /**
   * `FormData`, like `useClients().uploadCertificate`, and **no `Content-Type`
   * set by hand**: the boundary is what delimits the parts, and a hand-written
   * header drops it, which is a `400` the operator cannot act on.
   *
   * The password is sent as a field, never as a query parameter, so it does not
   * reach an access log, a `Referer` or the browser history. It is not kept
   * anywhere on this side either: the composable hands it to `FormData` and
   * forgets it, and the caller clears its own copy.
   */
  async function uploadAccountCertificate(file: File, password: string) {
    const body = new FormData()
    body.append('certificate', file)
    body.append('password', password)
    const res = await $api<{ data: SerproAccountCertificate }>('/serpro/account-certificate', { method: 'POST', body })
    return res.data
  }

  /**
   * Removal is not a row deletion the office can undo from the UI, so the
   * confirmation belongs to the screen. The endpoint answers `204` whether or not
   * there was anything to remove.
   */
  async function removeAccountCertificate() {
    await $api('/serpro/account-certificate', { method: 'DELETE' })
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

  /**
   * The current Account's switch. The GET is any member's; the PUT is the
   * account `admin`'s — the backend answers `403` to anyone else, and this
   * composable only transports the answer.
   */
  async function enablement() {
    const res = await $api<{ data: { enabled: boolean } }>('/serpro/enablement')
    return res.data
  }

  async function setEnablement(enabled: boolean) {
    const res = await $api<{ data: { enabled: boolean } }>('/serpro/enablement', { method: 'PUT', body: { enabled } })
    return res.data
  }

  return {
    overview,
    listObligation,
    readMessage,
    associateClients,
    searchDocuments,
    searchQuota,
    schedules,
    setSchedules,
    connection,
    saveConnection,
    testConnectivity,
    authorizationTerm,
    accountCertificate,
    uploadAccountCertificate,
    removeAccountCertificate,
    syncRuns,
    showSyncRun,
    resyncRun,
    enablement,
    setEnablement
  }
}
