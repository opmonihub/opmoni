import type { Identity } from '../types/access'

export function accessDestination(user: Identity): string {
  if (user.current_account?.status === 'active')
    return '/'
  if (user.current_company?.status === 'active')
    return '/erp'
  return '/acesso-indisponivel'
}

export function focusAccessDestination(user: Identity, path: string): string | null {
  if (path === '/admin' || path.startsWith('/admin/'))
    return user.is_super_admin ? null : accessDestination(user)
  const target = accessDestination(user)
  if (target === path)
    return null
  if (target === '/erp' && (path === '/erp' || path.startsWith('/erp/')))
    return null
  if (target !== '/' || path === '/acesso-indisponivel' || path === '/selecionar-escritorio')
    return target
  return null
}

export function apiFailure(error: unknown): {
  status: number
  code: string
  message: string
  errors?: Record<string, string[]>
} {
  const e = error as {
    statusCode?: number
    status?: number
    data?: {
      code?: string
      message?: string
      errors?: Record<string, string[]>
    }
  }
  const status = e.statusCode || e.status || 0
  return { status, code: e.data?.code || '', errors: e.data?.errors, message: status === 429 ? 'Muitas tentativas. Aguarde um minuto e tente novamente.' : status >= 500 || !status ? 'Serviço temporariamente indisponível. Tente novamente.' : e.data?.message || 'Não foi possível concluir. Tente novamente.' }
}

export function passwordLength(value: string): number {
  return [...value].length
}

export function retryAfterSeconds(error: unknown, now = Date.now()): number {
  if (typeof error !== 'object' || error === null) return 0
  const record = error as { headers?: unknown, response?: { headers?: unknown } }
  const headers = (record.response?.headers ?? record.headers) as { get?: (name: string) => string | null, [key: string]: unknown } | undefined
  const raw = typeof headers?.get === 'function'
    ? headers.get('retry-after')
    : headers?.['retry-after'] ?? headers?.['Retry-After']
  if (typeof raw !== 'string' || !raw.trim()) return 0
  const seconds = Number(raw)
  if (Number.isFinite(seconds) && seconds > 0) return Math.min(3600, Math.ceil(seconds))
  const timestamp = Date.parse(raw)
  return Number.isFinite(timestamp) && timestamp > now ? Math.min(3600, Math.ceil((timestamp - now) / 1000)) : 0
}
