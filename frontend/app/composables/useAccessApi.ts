import type { $Fetch, FetchOptions, ResponseType } from 'ofetch'
import type { Identity } from '#shared/types/access'
import { tenantApiPath, tenantMembership } from '#shared/utils/business'
import { erpCompanySelection, erpOperatingApiPath } from '#shared/utils/erp'

export function useAccessApi() {
  const request = useRequestFetch() as $Fetch
  const event = useRequestEvent()
  const csrf = useState<string | null>('access-csrf', () => null)
  const identity = useState<Identity | null>('access-user', () => null)

  async function api<T, R extends ResponseType = 'json'>(path: string, options: FetchOptions<R> = {}): Promise<T> {
    if (!path.startsWith('/') || path.startsWith('//'))
      throw new Error('API requests must use a relative application path')
    const membership = tenantMembership(identity.value)
    if (tenantApiPath(path) && !membership)
      throw new Error('Selecione um escritório ativo para continuar (account required)')
    if (options.method && !['GET', 'HEAD'].includes(options.method.toUpperCase()) && !csrf.value)
      await refreshCsrf()
    const headers = new Headers(options.headers)
    if (!headers.has('Accept'))
      headers.set('Accept', options.responseType === 'blob' ? '*/*' : 'application/json')
    if (csrf.value) headers.set('X-CSRF-Token', csrf.value)
    headers.delete('X-Account-Id')
    headers.delete('X-Company-Id')
    if (tenantApiPath(path) && membership)
      headers.set('X-Account-Id', String(membership.id))
    const company = erpCompanySelection(identity.value)
    if (erpOperatingApiPath(path) && company)
      headers.set('X-Company-Id', String(company.id))
    const responseHooks = options.onResponse
      ? Array.isArray(options.onResponse) ? options.onResponse : [options.onResponse]
      : []
    return await request<T, R>(`/api/v1${path}`, {
      ...options,
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
    }) as T
  }
  async function refreshCsrf() {
    csrf.value = (await api<{ csrf_token: string }>('/csrf')).csrf_token
  }
  return { api, refreshCsrf, csrf }
}
