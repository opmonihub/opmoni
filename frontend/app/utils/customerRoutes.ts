import type { ClientPortfolioDocument, DeadlineStatus } from '~/types/client'

const documentBySlug = {
  certificados: 'certificate',
  procuracao: 'poa'
} as const

const slugByDocument: Record<ClientPortfolioDocument, keyof typeof documentBySlug> = {
  certificate: 'certificados',
  poa: 'procuracao'
}

const statusBySlug = {
  'a-vencer': 'expiring',
  'vencido': 'expired',
  'valido': 'valid',
  'sem-cadastro': 'missing'
} as const

const slugByStatus: Record<DeadlineStatus, keyof typeof statusBySlug> = {
  expiring: 'a-vencer',
  expired: 'vencido',
  valid: 'valido',
  missing: 'sem-cadastro'
}

const legacyStatus: Record<string, DeadlineStatus | 'all'> = {
  all: 'all',
  expiring: 'expiring',
  expired: 'expired',
  valid: 'valid',
  missing: 'missing'
}

function segment(value: unknown) {
  return Array.isArray(value) ? value[0] : value
}

export function customerListPath(document: ClientPortfolioDocument, status: DeadlineStatus | 'all' = 'all') {
  const base = `/customers/${slugByDocument[document]}`
  if (status === 'all') return base
  return `${base}/${slugByStatus[status]}`
}

export function customerDetailPath(id: number) {
  return `/customers/empresa/${id}`
}

export function parseCustomerList(documento: unknown, situacao: unknown) {
  const documentSlug = segment(documento)
  const statusSlug = segment(situacao)
  if (typeof documentSlug !== 'string' || !(documentSlug in documentBySlug)) return null

  const document = documentBySlug[documentSlug as keyof typeof documentBySlug]
  if (statusSlug == null || statusSlug === '') return { document, status: 'all' as const }
  if (typeof statusSlug !== 'string' || !(statusSlug in statusBySlug)) return null

  return {
    document,
    status: statusBySlug[statusSlug as keyof typeof statusBySlug]
  }
}

export function customerListPathFromQuery(query: { documento?: unknown, situacao?: unknown }) {
  const documento = segment(query.documento)
  const situacao = segment(query.situacao)
  const hasDocumento = documento != null && documento !== ''
  const hasSituacao = situacao != null && situacao !== ''
  if (!hasDocumento && !hasSituacao) return null

  const document: ClientPortfolioDocument = documento === 'poa' ? 'poa' : 'certificate'
  const status = typeof situacao === 'string' && situacao in legacyStatus ? legacyStatus[situacao] : 'all'
  return customerListPath(document, status ?? 'all')
}
