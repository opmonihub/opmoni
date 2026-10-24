export type ErpTypedFiscalModel = '57' | '58'
export type ErpTypedFiscalKind = 'transport_service' | 'complement' | 'substitution' | 'transport_manifest'
export type ErpTypedFiscalControl = 'input' | 'textarea' | 'select'

export interface ErpTypedFiscalFieldDescriptor {
  key: string
  path: string
  aliases?: string[]
  label: string
  section: 'service' | 'party' | 'route' | 'tax' | 'cargo' | 'vehicle' | 'driver' | 'manifest' | 'reference'
  control?: ErpTypedFiscalControl
  requiredWhen?: 'always' | 'taker_is_other' | 'cargo_measure_needs_type'
  inputMode?: 'numeric' | 'decimal'
  maxlength?: number
  options?: Array<{ label: string, value: string | number }>
}

const cteKinds: ErpTypedFiscalKind[] = ['transport_service', 'complement', 'substitution']

const cteFields: ErpTypedFiscalFieldDescriptor[] = [
  { key: 'service_type', path: 'transport_service.service_type', label: 'Tipo de serviço (0 a 4)', section: 'service', control: 'select', requiredWhen: 'always', options: [0, 1, 2, 3, 4].map(value => ({ label: `${value} · Serviço de transporte ${value}`, value })) },
  { key: 'emission_type', path: 'transport_service.emission_type', label: 'Tipo de emissão (fluxo local suporta 1)', section: 'service', control: 'select', requiredWhen: 'always', options: [{ label: '1 · Normal', value: 1 }] },
  { key: 'operation_nature', path: 'transport_service.operation_nature', label: 'Natureza da operação', section: 'service', requiredWhen: 'always' },
  { key: 'cfop', path: 'transport_service.cfop', label: 'CFOP', section: 'service', requiredWhen: 'always' },
  { key: 'rntrc', path: 'transport_service.rntrc', label: 'RNTRC (8 dígitos)', section: 'service', requiredWhen: 'always', inputMode: 'numeric', maxlength: 8 },
  { key: 'service_value', path: 'transport_service.service_value', label: 'Valor da prestação', section: 'service', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'received_value', path: 'transport_service.received_value', label: 'Valor a receber', section: 'service', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'tax_cst', path: 'transport_service.tax.cst', label: 'CST (fluxo local suporta 00)', section: 'tax', control: 'select', requiredWhen: 'always', options: [{ label: '00 · Tributação integral', value: '00' }] },
  { key: 'tax_base', path: 'transport_service.tax.base', label: 'Base do tributo', section: 'tax', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'tax_rate', path: 'transport_service.tax.rate', label: 'Alíquota', section: 'tax', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'tax_value', path: 'transport_service.tax.value', label: 'Valor do tributo', section: 'tax', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'cargo_description', path: 'transport_service.cargo.description', label: 'Descrição da carga', section: 'cargo', requiredWhen: 'always' },
  { key: 'cargo_measure_type', path: 'transport_service.cargo.measure_type', label: 'Tipo de medida da carga', section: 'cargo', requiredWhen: 'cargo_measure_needs_type' },
  { key: 'cargo_unit', path: 'transport_service.cargo.unit', label: 'Unidade da carga (00 a 05)', section: 'cargo', requiredWhen: 'always' },
  { key: 'cargo_quantity', path: 'transport_service.cargo.quantity', label: 'Quantidade da carga', section: 'cargo', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'cargo_value', path: 'transport_service.cargo.value', label: 'Valor da carga', section: 'cargo', requiredWhen: 'always', inputMode: 'decimal' },
  ...(['sender', 'receiver'] as const).flatMap((role) => {
    const party = role === 'sender' ? 'remetente' : 'destinatário'
    const prefix = `transport_service.${role}`
    return [
      { key: `${role}_cnpj`, path: `${prefix}.cnpj`, label: `CNPJ ${party}`, section: 'party' as const, requiredWhen: 'always' as const, inputMode: 'numeric' as const },
      { key: `${role}_name`, path: `${prefix}.legal_name`, label: `Nome ${party}`, section: 'party' as const, requiredWhen: 'always' as const },
      { key: `${role}_state_registration`, path: `${prefix}.state_registration`, label: `IE ${party}`, section: 'party' as const },
      { key: `${role}_state_registration_indicator`, path: `${prefix}.state_registration_indicator`, label: `Indicador IE ${party} (1, 2, 9)`, section: 'party' as const, control: 'select' as const, options: [{ label: '1 · Contribuinte ICMS', value: 1 }, { label: '2 · Contribuinte isento', value: 2 }, { label: '9 · Não contribuinte', value: 9 }] },
      ...([
        ['street', 'Logradouro', 'street'], ['number', 'Número', 'number'], ['district', 'Bairro', 'district'],
        ['city_code', 'Código IBGE', 'city_code'], ['city', 'Município', 'city'], ['postal_code', 'CEP', 'postal_code'], ['uf', 'UF', 'uf']
      ] as const).map(([key, label, addressKey]) => ({
        key: `${role}_${key}`,
        path: `${prefix}.address.${addressKey}`,
        ...(['city_code', 'uf'].includes(key) ? { aliases: [`${prefix}.${key}`] } : {}),
        label: `${label} ${party}`,
        section: 'party' as const,
        requiredWhen: 'always' as const,
        ...(key === 'city_code' || key === 'postal_code' ? { inputMode: 'numeric' as const } : {})
      }))
    ]
  }),
  { key: 'taker_role', path: 'transport_service.taker.role', label: 'Papel do tomador (0 a 4)', section: 'party', control: 'select', requiredWhen: 'always', options: [0, 1, 2, 3, 4].map(value => ({ label: `${value} · Papel do tomador`, value })) },
  { key: 'taker_cnpj', path: 'transport_service.taker.cnpj', label: 'CPF ou CNPJ do tomador', section: 'party', requiredWhen: 'taker_is_other', inputMode: 'numeric' },
  { key: 'taker_name', path: 'transport_service.taker.legal_name', label: 'Nome do tomador', section: 'party', requiredWhen: 'taker_is_other' },
  ...([
    ['street', 'Logradouro', 'street'], ['number', 'Número', 'number'], ['district', 'Bairro', 'district'],
    ['city_code', 'Código IBGE', 'city_code'], ['city', 'Município', 'city'], ['postal_code', 'CEP', 'postal_code'], ['uf', 'UF', 'uf']
  ] as const).map(([key, label, addressKey]) => ({
    key: `taker_${key}`,
    path: `transport_service.taker.address.${addressKey}`,
    label: `${label} do tomador`,
    section: 'party' as const,
    requiredWhen: 'taker_is_other' as const
  })),
  ...([
    ['origin_uf', 'UF de origem'], ['origin_city_code', 'Código IBGE de origem'], ['origin_city', 'Município de origem'],
    ['destination_uf', 'UF de destino'], ['destination_city_code', 'Código IBGE de destino'], ['destination_city', 'Município de destino'], ['pickup', 'Retirada (0 não, 1 sim)']
  ] as const).map(([key, label]) => ({
    key,
    path: `transport_service.route.${key}`,
    label,
    section: 'route' as const,
    requiredWhen: 'always' as const,
    ...(key === 'pickup' ? { control: 'select' as const, options: [{ label: '0 · Não', value: 0 }, { label: '1 · Sim', value: 1 }] } : {}),
    ...(key.endsWith('_city_code') ? { inputMode: 'numeric' as const } : {})
  }))
]

const mdfeFields: ErpTypedFiscalFieldDescriptor[] = [
  { key: 'manifest_emission_type', path: 'transport_manifest.emission_type', label: 'Tipo de emissão (fluxo local suporta 1)', section: 'manifest', control: 'select', requiredWhen: 'always', options: [{ label: '1 · Normal', value: 1 }] },
  { key: 'manifest_modal', path: 'transport_manifest.modal', label: 'Modal (fluxo local suporta 1 rodoviário)', section: 'manifest', control: 'select', requiredWhen: 'always', options: [{ label: '1 · Rodoviário', value: 1 }] },
  { key: 'manifest_issuer_type', path: 'transport_manifest.issuer_type', label: 'Tipo do emitente (fluxo local suporta veículo próprio 2)', section: 'manifest', control: 'select', requiredWhen: 'always', options: [{ label: '2 · Transportador de carga própria', value: 2 }] },
  { key: 'manifest_rntrc', path: 'transport_manifest.rntrc', label: 'RNTRC (8 dígitos)', section: 'manifest', requiredWhen: 'always', inputMode: 'numeric', maxlength: 8 },
  { key: 'manifest_route_ufs', path: 'transport_manifest.route_ufs', label: 'UFs do percurso, separadas por vírgula', section: 'route', requiredWhen: 'always' },
  { key: 'manifest_load_municipality_name', path: 'transport_manifest.municipalities.0.name', label: 'Município de carregamento', section: 'route', requiredWhen: 'always' },
  { key: 'manifest_load_municipality_code', path: 'transport_manifest.municipalities.0.code', label: 'Código IBGE de carregamento', section: 'route', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'manifest_unload_municipality_name', path: 'transport_manifest.municipalities.1.name', label: 'Município de descarregamento', section: 'route', requiredWhen: 'always' },
  { key: 'manifest_unload_municipality_code', path: 'transport_manifest.municipalities.1.code', label: 'Código IBGE de descarregamento', section: 'route', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'vehicle_plate', path: 'transport_manifest.vehicle.plate', label: 'Placa', section: 'vehicle', requiredWhen: 'always' },
  { key: 'vehicle_renavam', path: 'transport_manifest.vehicle.renavam', label: 'RENAVAM', section: 'vehicle', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'vehicle_registry', path: 'transport_manifest.vehicle.registry', label: 'Registro', section: 'vehicle', requiredWhen: 'always' },
  { key: 'vehicle_tare', path: 'transport_manifest.vehicle.tare', label: 'Tara', section: 'vehicle', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'vehicle_capacity', path: 'transport_manifest.vehicle.capacity_kg', label: 'Capacidade em kg', section: 'vehicle', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'vehicle_road_type', path: 'transport_manifest.vehicle.road_type', label: 'Tipo rodoviário', section: 'vehicle', requiredWhen: 'always' },
  { key: 'vehicle_trailer_type', path: 'transport_manifest.vehicle.trailer_type', label: 'Tipo de carroceria', section: 'vehicle', requiredWhen: 'always' },
  { key: 'vehicle_uf', path: 'transport_manifest.vehicle.uf', label: 'UF do veículo', section: 'vehicle', requiredWhen: 'always' },
  { key: 'driver_name', path: 'transport_manifest.driver.name', label: 'Nome do motorista', section: 'driver', requiredWhen: 'always' },
  { key: 'driver_cpf', path: 'transport_manifest.driver.cpf', label: 'CPF do motorista', section: 'driver', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'cargo_product_description', path: 'transport_manifest.cargo.product_description', label: 'Descrição do produto predominante', section: 'cargo', requiredWhen: 'always' },
  { key: 'cargo_product_type', path: 'transport_manifest.cargo.product_type', label: 'Tipo de produto', section: 'cargo', requiredWhen: 'always' },
  { key: 'cargo_ncm', path: 'transport_manifest.cargo.ncm', label: 'NCM', section: 'cargo', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'cargo_total_value', path: 'transport_manifest.cargo.total_value', label: 'Valor total da carga', section: 'cargo', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'cargo_unit_manifest', path: 'transport_manifest.cargo.unit', label: 'Unidade da carga', section: 'cargo', requiredWhen: 'always' },
  { key: 'cargo_quantity_manifest', path: 'transport_manifest.cargo.quantity', label: 'Quantidade da carga', section: 'cargo', requiredWhen: 'always', inputMode: 'decimal' },
  { key: 'cte_total', path: 'transport_manifest.total_cte', label: 'Quantidade de CT-e', section: 'manifest', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'nfe_total', path: 'transport_manifest.total_nfe', label: 'Quantidade de NF-e', section: 'manifest', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'mdfe_total', path: 'transport_manifest.total_mdfe', label: 'Quantidade de MDF-e', section: 'manifest', requiredWhen: 'always', inputMode: 'numeric' },
  { key: 'manifest_document_model', path: 'transport_manifest.documents.0.model', label: 'Modelo da referência externa (55, 65 ou 57)', section: 'reference' },
  { key: 'access_key_reference', path: 'transport_manifest.documents.0.access_key', label: 'Chave de acesso da referência (44 dígitos)', section: 'reference', inputMode: 'numeric' }
]

export function erpTypedFiscalFieldDescriptors(model: ErpTypedFiscalModel, kind: ErpTypedFiscalKind): ErpTypedFiscalFieldDescriptor[] {
  if (model === '57' && cteKinds.includes(kind)) return cteFields
  if (model === '58' && kind === 'transport_manifest') return mdfeFields

  return []
}

export function requiredErpTypedFiscalFields(model: ErpTypedFiscalModel, kind: ErpTypedFiscalKind, facts: Record<string, string>): ErpTypedFiscalFieldDescriptor[] {
  return erpTypedFiscalFieldDescriptors(model, kind).filter((field) => {
    if (field.requiredWhen === 'always') return true
    if (field.requiredWhen === 'taker_is_other') return facts.taker_role === '4'
    if (field.requiredWhen === 'cargo_measure_needs_type') return (facts.cargo_description ?? '').length > 20

    return false
  })
}

export interface ErpTypedFiscalFieldErrors {
  fields: Record<string, string>
  summary: string[]
  firstFieldKey: string | null
}

function flattenErrors(value: unknown, prefix = ''): Array<[string, string]> {
  if (typeof value === 'string') return [[prefix, value]]
  if (Array.isArray(value)) return value.flatMap(item => typeof item === 'string' ? [[prefix, item] as [string, string]] : flattenErrors(item, prefix))
  if (!value || typeof value !== 'object') return []

  return Object.entries(value as Record<string, unknown>).flatMap(([key, item]) => flattenErrors(item, prefix ? `${prefix}.${key}` : key))
}

function exactSourcePaths(message: string): string[] | null {
  const match = /^Dados fiscais obrigatórios ausentes ou inválidos: ((?:transport_service|transport_manifest)\.[a-z0-9_.]+(?:, (?:transport_service|transport_manifest)\.[a-z0-9_.]+)*)\.$/.exec(message)
    ?? /^Dados (?:CT-e|MDF-e) obrigatórios ausentes: ((?:transport_service|transport_manifest)\.[a-z0-9_.]+)\.$/.exec(message)

  return match?.[1]?.split(', ') ?? null
}

export function mapErpTypedFiscalFieldErrors(errors: Record<string, unknown>, model: ErpTypedFiscalModel, kind: ErpTypedFiscalKind): ErpTypedFiscalFieldErrors {
  const fields = erpTypedFiscalFieldDescriptors(model, kind)
  const byPath = new Map(fields.flatMap(field => [field.path, ...(field.aliases ?? [])].map(path => [path, field] as const)))
  const mapped: Record<string, string> = {}
  const summary: string[] = []
  const entries = flattenErrors(errors)

  for (const [path, message] of entries) {
    if (path === 'source') {
      const paths = exactSourcePaths(message)
      if (!paths) {
        summary.push(message)
        continue
      }
      for (const sourcePath of paths) {
        const field = byPath.get(sourcePath)
        if (field) mapped[field.key] = `Revise ${field.label.toLocaleLowerCase('pt-BR')} informado.`
        else summary.push(message)
      }
      continue
    }

    const sourcePath = path.startsWith('source.') ? path.slice('source.'.length) : path
    const field = byPath.get(sourcePath)
    if (field) mapped[field.key] = message
    else summary.push(message)
  }

  const firstFieldKey = fields.find(field => Object.hasOwn(mapped, field.key))?.key ?? null

  return { fields: mapped, summary: [...new Set(summary)], firstFieldKey }
}

export function focusErpTypedFiscalField(root: Pick<HTMLElement, 'querySelector'> | null | undefined, key: string | null): boolean {
  if (!root || !key) return false
  const control = root.querySelector<HTMLElement>(`[data-fiscal-field-key="${key}"]`)
  if (!control) return false

  control.focus()
  return true
}
