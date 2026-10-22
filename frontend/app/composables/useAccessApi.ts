import type { $Fetch, FetchOptions, ResponseType } from 'ofetch'
import { multipartTransport } from '#shared/utils/ingress'
import type { Identity } from '#shared/types/access'
import { tenantApiPath, tenantMembership } from '#shared/utils/business'
import { erpCompanySelection, erpOperatingApiPath } from '#shared/utils/erp'
import { snapshotAccessApiBody, snapshotAccessApiQuery, useAccessApiAdmissionQueue } from '~/utils/accessApiAdmissionQueue'

export function useAccessApi() {
  const nuxtApp = useNuxtApp()
  const admission = useAccessApiAdmissionQueue(nuxtApp)
  const request = useRequestFetch() as $Fetch
  const event = useRequestEvent()
  const csrf = useState<string | null>('access-csrf', () => null)
  const identity = useState<Identity | null>('access-user', () => null)
  const sessionGeneration = useState('access-session-generation', () => 0)

  async function api<T, R extends ResponseType = 'json'>(path: string, options: FetchOptions<R> = {}): Promise<T> {
    if (!path.startsWith('/') || path.startsWith('//'))
      throw new Error('API requests must use a relative application path')
    const capturedHeaders = options.headers ? new Headers(options.headers) : undefined
    const requestOptions = {
      ...options,
      body: snapshotAccessApiBody(options.body, capturedHeaders?.get('content-type') ?? undefined),
      query: snapshotAccessApiQuery(options.query),
      params: snapshotAccessApiQuery(options.params),
      headers: capturedHeaders
    }
    if (requestOptions.signal?.aborted)
      throw new DOMException('The request was aborted before it was sent.', 'AbortError')
    const identityAtCall = identity.value
    const membership = tenantMembership(identityAtCall)
    const company = erpCompanySelection(identityAtCall)
    const snapshot = {
      actorId: identityAtCall?.id ?? null,
      accountId: membership?.id ?? null,
      companyId: company?.id ?? null,
      sessionGeneration: sessionGeneration.value
    }
    const isCurrent = () => {
      const currentIdentity = identity.value
      return (currentIdentity?.id ?? null) === snapshot.actorId
        && (tenantMembership(currentIdentity)?.id ?? null) === snapshot.accountId
        && (erpCompanySelection(currentIdentity)?.id ?? null) === snapshot.companyId
        && sessionGeneration.value === snapshot.sessionGeneration
    }
    if (tenantApiPath(path) && !membership)
      throw new Error('Selecione um escritório ativo para continuar (account required)')
    if (requestOptions.method && !['GET', 'HEAD'].includes(requestOptions.method.toUpperCase()) && !csrf.value)
      await refreshCsrf(requestOptions.signal)
    const headers = new Headers(requestOptions.headers)
    if (!headers.has('Accept'))
      headers.set('Accept', requestOptions.responseType === 'blob' ? '*/*' : 'application/json')
    if (csrf.value) headers.set('X-CSRF-Token', csrf.value)
    headers.delete('X-Account-Id')
    headers.delete('X-Company-Id')
    if (tenantApiPath(path) && membership)
      headers.set('X-Account-Id', String(membership.id))
    if (erpOperatingApiPath(path) && company)
      headers.set('X-Company-Id', String(company.id))
    const responseHooks = requestOptions.onResponse
      ? Array.isArray(requestOptions.onResponse) ? requestOptions.onResponse : [requestOptions.onResponse]
      : []
    return await admission.run(() => request<T, R>(`/api/v1${path}`, {
      ...requestOptions,
      ...multipartTransport(requestOptions.method, requestOptions.body) as Pick<FetchOptions<R>, 'method' | 'body'>,
      // Nitro 2 fetchWithEvent spreads init.headers, so use an enumerable record.
      headers: Object.fromEntries(headers.entries()),
      retry: 0,
      onResponse: [({ response }) => {
        if (import.meta.server && event) {
          for (const cookie of response.headers.getSetCookie())
            event.node.res.appendHeader('set-cookie', cookie)
        }
      }, ...responseHooks]
    }).catch((error: { statusCode?: number }) => {
      if (error.statusCode === 403) csrf.value = null
      throw error
    }) as T, { signal: requestOptions.signal, isCurrent })
  }
  async function refreshCsrf(signal?: AbortSignal | null) {
    const identityAtCall = identity.value
    const snapshot = {
      actorId: identityAtCall?.id ?? null,
      accountId: tenantMembership(identityAtCall)?.id ?? null,
      companyId: erpCompanySelection(identityAtCall)?.id ?? null,
      sessionGeneration: sessionGeneration.value
    }
    const token = (await api<{ csrf_token: string }>('/csrf', { signal })).csrf_token
    const currentIdentity = identity.value
    if ((currentIdentity?.id ?? null) === snapshot.actorId
      && (tenantMembership(currentIdentity)?.id ?? null) === snapshot.accountId
      && (erpCompanySelection(currentIdentity)?.id ?? null) === snapshot.companyId
      && sessionGeneration.value === snapshot.sessionGeneration) {
      csrf.value = token
    }
  }
  return { api, refreshCsrf, csrf }
}
