import type { ErpFiscalDocumentSource, ErpFiscalRecoveryRecord, ErpFiscalSale, ErpFiscalSaleLine } from '../types/erpFiscal'

export interface ErpFiscalCertificateUploadInput {
  label?: string
  establishmentId?: number
  certificate: File
  password: string
}

export function buildErpFiscalCertificateUpload(input: ErpFiscalCertificateUploadInput): FormData {
  const body = new FormData()
  if (input.label !== undefined) body.set('label', input.label.trim())
  if (input.establishmentId !== undefined) body.set('erp_establishment_id', String(input.establishmentId))
  body.set('certificate', input.certificate, input.certificate.name)
  body.set('password', input.password)
  return body
}

export interface ErpFiscalSaleFormFacts {
  identity: Record<string, string | number | boolean>
  recipient: Record<string, unknown>
  totals: Record<string, string>
  freight: Record<string, string | number>
  payment: Array<Record<string, string | number>>
  operationNature?: { cfop: string, movement?: 'in' | 'out' }
  itemFacts: Array<{
    description: string
    ind_total: 0 | 1
    quantity?: string
    unitPrice?: string
    grossTotal?: string
    tax: Record<string, string | number>
  }>
}

export interface ErpFiscalDraftSourceInput {
  kind: 'posted_sale' | 'return' | 'complement'
  sale?: ErpFiscalSale
  returnDocument?: ErpFiscalSale
  facts: ErpFiscalSaleFormFacts
  returnLines?: ErpFiscalSaleLine[]
}

function record(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
}

function fiscalAmount(value: unknown): string {
  const raw = String(value ?? '')
  const match = /^(\d+)(?:\.(\d+))?$/.exec(raw)
  if (!match) throw new Error('O valor comercial não está disponível em formato decimal válido.')
  const fraction = match[2] ?? ''
  if (fraction.length > 2 && /[1-9]/.test(fraction.slice(2))) {
    throw new Error('O valor comercial tem frações menores que centavos e não pode ser convertido para o valor fiscal sem arredondamento.')
  }
  return `${BigInt(match[1]!)}.${fraction.slice(0, 2).padEnd(2, '0')}`
}

export function buildErpFiscalDraftSource(input: ErpFiscalDraftSourceInput): {
  erp_sale_id: number | undefined
  erp_sale_return_id?: number
  document_kind: 'posted_sale' | 'return' | 'complement'
  source: ErpFiscalDocumentSource
} {
  const sale = input.kind === 'return' ? input.returnDocument : input.sale
  const snapshot = record(sale?.commercial_snapshot)
  const party = record(snapshot.party)
  const frozenLines = input.kind === 'return' ? input.sale?.commercial_snapshot?.lines ?? [] : sale?.commercial_snapshot?.lines ?? []
  const selectedReturnLines = input.kind === 'return' ? input.returnLines ?? [] : null
  const bySourceLine = new Map(frozenLines.map(line => [Number(line.id), line]))

  const items = input.facts.itemFacts.map((facts, index) => {
    const returnLine = selectedReturnLines?.[index]
    const sourceLineId = Number(returnLine?.source_line_id ?? (input.kind === 'return' ? 0 : frozenLines[index]?.id))
    const returnSnapshotLine = returnLine?.source_snapshot?.line
    const line = input.kind === 'return' ? returnSnapshotLine : bySourceLine.get(sourceLineId)
    const product = record(record(line?.product_snapshot).product)
    const unit = record(record(line?.product_snapshot).unit)
    const operationNature = record(record(line?.product_snapshot).operation_nature)
    const amounts = record(record(line?.product_snapshot).amounts)

    return {
      ...(returnLine ? { source_line_id: returnLine.source_line_id, source_return_line_id: returnLine.id } : input.kind === 'posted_sale' ? { source_line_id: sourceLineId } : {}),
      ...(line?.erp_product_variant_id !== undefined ? { erp_product_variant_id: line.erp_product_variant_id } : {}),
      code: record(record(line?.product_snapshot).variant).sku ?? product.sku,
      description: facts.description,
      ncm: product.ncm,
      cfop: input.facts.operationNature?.cfop ?? operationNature.cfop,
      unit: unit.short_name,
      quantity: returnLine?.quantity ?? facts.quantity ?? amounts.quantity ?? line?.quantity,
      unit_price: facts.unitPrice ?? amounts.unit_price ?? line?.unit_price,
      gross_total: fiscalAmount(returnLine?.subtotal_amount ?? (input.kind === 'complement' ? facts.grossTotal : undefined) ?? amounts.gross_amount ?? line?.gross_amount),
      ind_total: facts.ind_total,
      tax: {
        origin: product.origin_code,
        icms_cst: product.icms_cst,
        pis_cst: product.pis_cst,
        cofins_cst: product.cofins_cst,
        ...facts.tax
      }
    }
  })

  return {
    erp_sale_id: input.kind === 'posted_sale' ? sale?.id : undefined,
    ...(input.kind === 'return' ? { erp_sale_return_id: sale?.id } : {}),
    document_kind: input.kind,
    source: {
      identity: input.facts.identity,
      ...(input.facts.operationNature ? { operation_nature: input.facts.operationNature } : {}),
      recipient: {
        cnpj: party.tax_id,
        legal_name: party.name,
        ...input.facts.recipient
      },
      totals: input.facts.totals,
      freight: input.facts.freight,
      items,
      payment: input.facts.payment
    }
  }
}

const secretFieldNames = new Set(['certificate', 'certificate_content', 'password', 'private_key', 'private_key_content', 'pfx', 'pkcs12', 'secret', 'token'])

function withoutSecrets(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(withoutSecrets)
  if (!value || typeof value !== 'object') return value
  return Object.fromEntries(Object.entries(value as Record<string, unknown>)
    .filter(([key]) => !secretFieldNames.has(key.toLowerCase()))
    .map(([key, item]) => [key, withoutSecrets(item)]))
}

export interface ErpFiscalRecoveryInput {
  actorId: number
  companyId: number
  action: string
  path: string
  idempotencyKey: string
  body: Record<string, unknown>
  resourceId?: number | null
  recoveryMode?: 'replay' | 'read'
}

export function createErpFiscalRecoveryRecord(input: ErpFiscalRecoveryInput): ErpFiscalRecoveryRecord {
  return {
    version: 1,
    actorId: input.actorId,
    companyId: input.companyId,
    action: input.action,
    path: input.path,
    idempotencyKey: input.idempotencyKey,
    body: withoutSecrets(input.body) as Record<string, unknown>,
    resourceId: input.resourceId ?? null,
    recoveryMode: input.recoveryMode ?? 'replay',
    savedAt: new Date().toISOString()
  }
}

export function erpFiscalRecoveryStorageKey(actorId: number, companyId: number): string {
  return `opmoni:erp-fiscal:recovery:v1:${actorId}:${companyId}`
}

export function parseErpFiscalRecoveryRecord(value: string | null, actorId: number, companyId: number): ErpFiscalRecoveryRecord | null {
  if (!value) return null
  try {
    const parsed = JSON.parse(value) as ErpFiscalRecoveryRecord
    if (parsed.version !== 1 || parsed.actorId !== actorId || parsed.companyId !== companyId || !parsed.path.startsWith('/erp/fiscal/')) return null
    return parsed
  } catch {
    return null
  }
}

export function fiscalApiFieldErrors(error: unknown): Record<string, string> {
  const details = record(error)
  const errors = record(record(details.data).errors ?? details.errors)
  return Object.fromEntries(Object.entries(errors).flatMap(([key, messages]) => {
    const message = Array.isArray(messages) ? messages.find(value => typeof value === 'string') : messages
    return typeof message === 'string' ? [[key, message]] : []
  }))
}
