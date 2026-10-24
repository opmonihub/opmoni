import type { ErpCommercialDocument, ErpCommercialTaxContext, ErpCommercialTaxReviewOptions } from '../types/erpBusiness'

export function erpTaxRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
}

/** Only represented saved context is used; mixed or missing context stays unselected. */
export function erpSavedTaxContext(document: ErpCommercialDocument): ErpCommercialTaxContext | undefined {
  const contexts = document.lines.map(line => erpTaxRecord(erpTaxRecord(line.product_snapshot).cfop_resolution).context)
  const first = contexts[0]
  if (!first || contexts.some(value => JSON.stringify(value) !== JSON.stringify(first))) return undefined
  return erpTaxReviewOptions({ tax_context: erpTaxRecord(first) as ErpCommercialTaxContext }).tax_context
}

export function erpTaxReviewOptions(input: ErpCommercialTaxReviewOptions = {}): ErpCommercialTaxReviewOptions {
  const output: ErpCommercialTaxReviewOptions = {}
  if (input.line_ids) output.line_ids = [...input.line_ids]
  if (input.tax_context) {
    const context: ErpCommercialTaxContext = {}
    for (const field of ['origin_uf', 'destination_uf', 'model', 'purpose', 'final_consumer', 'presence'] as const) {
      if (Object.hasOwn(input.tax_context, field)) Object.assign(context, { [field]: input.tax_context[field] })
    }
    output.tax_context = context
  }
  if (input.lines) output.lines = input.lines.map(line => ({ id: line.id, ...(Object.hasOwn(line, 'cfop') ? { cfop: line.cfop } : {}) }))
  return output
}

export function erpTaxSourceLabel(value: unknown): string {
  const source = erpTaxRecord(value)
  const labels: Record<string, string> = { product: 'Valor do produto', profile: 'Herdado do perfil', company_profile: 'Perfil padrão da empresa', company_setting: 'Configuração da empresa', unset: 'Não definido', unresolved: 'Não resolvido', explicit: 'Escolha explícita', product_exception: 'Exceção por produto', nature_default: 'Padrão da natureza' }
  const label = labels[String(source.source)] ?? 'Origem histórica não informada'
  return `${label}${typeof source.profile_id === 'number' ? ` #${source.profile_id}` : ''}${typeof source.revision === 'number' ? ` · revisão ${source.revision}` : ''}`
}

export function erpTaxReadiness(document: ErpCommercialDocument): { title: string, description: string, errors: string[] } {
  const snapshot = erpTaxRecord(document.commercial_snapshot)
  const context = erpTaxRecord(snapshot.fiscal_context)
  const errors = Object.values(erpTaxRecord(context.errors)).flatMap(value => typeof value === 'string' ? [value] : Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [])
  const model = document.fiscal_model === '55' ? 'NF-e (55)' : document.fiscal_model === '65' ? 'NFC-e (65)' : null
  if (document.status === 'posted') return { title: 'Fonte comercial congelada', description: 'Os fatos históricos não serão reparados pelo catálogo atual. Dados ausentes exigem avaliação da correção comercial suportada; a emissão mantém suas validações próprias.', errors }
  if (!model) return { title: 'Sem intenção fiscal declarada', description: 'O documento segue a validação comercial. Perfil ou CFOP não representam autorização fiscal.', errors }
  const checked = context.readiness === 'source_prerequisites_checked'
  return { title: `${model} · ${checked ? 'Pré-requisitos da fonte comercial verificados' : context.readiness === 'review_required' ? 'Revisão explícita necessária' : 'Fonte comercial incompleta'}`, description: 'Última verificação salva da fonte comercial. O lançamento revalida os dados; a emissão ainda exige validação do modelo, configuração e autorização próprias.', errors }
}
