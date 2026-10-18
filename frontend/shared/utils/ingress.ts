/** Keep operational fiscal/export/bulk requests on their existing longer budget. */
export function certificateWritePath(path: string): boolean {
  return path === '/api/v1/serpro/connection' || /^\/api\/v1\/admin\/accounts\/\d+\/contracting-certificate$/.test(path)
}

export function ingressTimeout(path: string, method: string): number {
  const write = !['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())
  if ((path === '/api/v1/serpro/connectivity' && write) || (certificateWritePath(path) && write)) return 15000
  if (/^\/api\/v1\/(admin(?:\/|$)|csrf$|registration-status$|register$|login$|logout$|me$|first-access(?:\/|$)|account(?:\/|$)|members(?:\/|$))/.test(path)) return 10000
  return 120000
}

export function multipartTransport(method: string | undefined, body: unknown): { method?: string, body: unknown } {
  if (body instanceof FormData && method?.toUpperCase() === 'PUT') {
    const form = new FormData()
    for (const [key, value] of body.entries()) form.append(key, value)
    form.set('_method', 'PUT')
    return { method: 'POST', body: form }
  }
  return { method, body }
}
