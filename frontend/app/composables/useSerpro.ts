import type {
  MonitoringClient,
  MonitoringDeclarationArtifact,
  MonitoringDeclarationDetail,
  MonitoringMessage,
  MonitoringMessageRecovery,
  MonitoringObligationResponse,
  MonitoringOverview,
  MonitoringSituacao,
  SerproAccountCertificate,
  SerproAssociateResult,
  SerproAuthorizationTerm,
  SerproConnectivityResult,
  SerproContractingCertificateMetadata,
  SerproConnectionMetadata,
  SerproConnectionPayload,
  SerproManualSearchQuota,
  SerproObligationScheduleRow,
  SerproScheduleMap,
  SerproSearchDocumentsPayload,
  SerproSyncRun,
  SerproSyncRunDetail
} from '~/types/serpro'
import type { WhatsAppArtifact, WhatsAppDispatch, WhatsAppSendGuidesResult } from '~/types/whatsapp'
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
  async function declarationDetail(obligation: string, clientId: number) {
    const res = await $api<{ data: MonitoringDeclarationDetail }>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/declaration-detail`
    )
    return res.data
  }

  /**
   * PDF bilhetado no PGDAS-D. Só no cliente — `Blob` não existe no SSR.
   */
  async function downloadDeclarationArtifact(
    obligation: string,
    clientId: number,
    period: string,
    artifact: MonitoringDeclarationArtifact
  ) {
    return $api<Blob>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/periods/${period}/artifacts/${artifact}`,
      { responseType: 'blob' }
    )
  }

  /**
   * O Disparo explícito: enfileira o envio do artefato da competência para o
   * telefone da carteira pelo Canal WhatsApp. A API responde `202` com a
   * linha `queued` — e, num segundo clique dentro da janela do dia, com a
   * linha que já existia, nunca um segundo envio. O `422` nomeia o bloqueio
   * (`errors.channel|phone|artifact|competence`) e é do caller mostrar.
   */
  async function whatsappDispatch(obligation: string, clientId: number, competence: string, artifact: WhatsAppArtifact) {
    const res = await $api<{ data: WhatsAppDispatch }>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/whatsapp/dispatch`,
      { method: 'POST', body: { competence, artifact } }
    )
    return res.data
  }

  /**
   * O Enviar guias do modal: um POST, um Disparo `guia` por Contato de
   * envio WhatsApp marcado — a guia corrente é a que o backend resolve
   * (período mais recente com `slip_number` ou declaração transmitida).
   * `resendAcknowledgements` carrega os ids com Ciência de reenvio — a
   * exceção que deixa a mesma guia sair de novo para o mesmo contato.
   * A resposta `202` traz a competência/slip resolvidos, os disparos
   * criados e os contatos `skipped` com o motivo legível; o `422` nomeia
   * o bloqueio (canal, guia, contatos) e é do caller mostrar.
   */
  async function sendGuides(obligation: string, clientId: number, contactIds: number[], resendAcknowledgements: number[] = []) {
    const res = await $api<{ data: WhatsAppSendGuidesResult }>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/whatsapp/send-guides`,
      { method: 'POST', body: { contact_ids: contactIds, resend_acknowledgements: resendAcknowledgements } }
    )
    return res.data
  }

  /**
   * O Rastreio (cliente [× competência] [× artefato]): a lista do `data[]`,
   * não paginada — um cliente tem poucos disparos. A célula filtra pela
   * competência; o modal e o painel leem o histórico sem competência para
   * cruzar a guia corrente. Qualquer membro lê; só admin/operador disparam.
   */
  async function whatsappDispatches(obligation: string, clientId: number, competence?: string, artifact?: WhatsAppArtifact) {
    const res = await $api<{ data: WhatsAppDispatch[] }>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/whatsapp/dispatches`,
      { query: queryOf({ competence, artifact }) }
    )
    return res.data
  }

  async function readMessage(obligation: string, clientId: number, id: number) {
    const res = await $api<{ data: MonitoringMessage }>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/messages/${id}`,
      { method: 'POST', body: { ciencia: true }, retry: 0 }
    )
    return res.data
  }

  async function reconcileMessage(obligation: string, clientId: number, id: number) {
    const res = await $api<{ data: MonitoringMessageRecovery }>(
      `/serpro/monitoring/obligations/${obligation}/clients/${clientId}/messages/${id}/reconcile`,
      { method: 'POST', retry: 0 }
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
    if (payload.certificate_source) body.append('certificate_source', payload.certificate_source)
    if (payload.contracting_account_id !== undefined) body.append('contracting_account_id', String(payload.contracting_account_id))
    if (payload.use_account_certificate !== undefined) body.append('use_account_certificate', payload.use_account_certificate ? '1' : '0')
    if (payload.certificate) {
      body.append('certificate', payload.certificate)
      // Every selected file supplies a password field, including a passwordless container.
      body.append('password', payload.password ?? '')
    } else if (payload.password !== undefined) body.append('password', payload.password)
    const res = await $api<{ data: SerproConnectionMetadata }>('/serpro/connection', { method: 'PUT', body })
    return res.data
  }

  async function contractingCertificate(accountId: number) {
    const res = await $api<{ data: SerproContractingCertificateMetadata }>(`/admin/accounts/${accountId}/contracting-certificate`)
    return res.data
  }

  async function uploadContractingCertificate(accountId: number, file: File, password: string) {
    const body = new FormData()
    body.append('certificate', file)
    body.append('password', password)
    const res = await $api<{ data: SerproContractingCertificateMetadata }>(`/admin/accounts/${accountId}/contracting-certificate`, { method: 'PUT', body })
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
    declarationDetail,
    downloadDeclarationArtifact,
    whatsappDispatch,
    sendGuides,
    whatsappDispatches,
    readMessage,
    reconcileMessage,
    associateClients,
    searchDocuments,
    searchQuota,
    schedules,
    setSchedules,
    connection,
    saveConnection,
    contractingCertificate,
    uploadContractingCertificate,
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
