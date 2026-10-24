<script setup lang="ts">
import type { ErpFiscalConfiguration, ErpFiscalDocument, ErpFiscalModel, ErpFiscalSale, ErpFiscalSaleLine } from '#shared/types/erpFiscal'
import { buildErpFiscalDraftSource, type ErpFiscalSaleFormFacts } from '#shared/utils/erpFiscal'
import { erpTaxRecord, erpTaxSourceLabel } from '#shared/utils/erpCommercialTaxReview'
import { focusErpTypedFiscalField, mapErpTypedFiscalFieldErrors, requiredErpTypedFiscalFields, erpTypedFiscalFieldDescriptors, type ErpTypedFiscalFieldDescriptor } from '~/utils/erpTypedFiscalForm'

const props = defineProps<{
  establishmentId: number
  configuration: ErpFiscalConfiguration | null
  model: ErpFiscalModel
  sales: ErpFiscalSale[]
  selectedSale: ErpFiscalSale | null
  returns: ErpFiscalSale[]
  selectedReturn: ErpFiscalSale | null
  originalDocuments: ErpFiscalDocument[]
  canCreate: boolean
  busy?: boolean
  fieldErrors?: Record<string, string>
}>()

const emit = defineEmits<{
  'update:model': [model: ErpFiscalModel]
  'create': [body: Record<string, unknown>]
  'select-sale': [id: number]
  'select-return': [id: number]
  'dirty': [dirty: boolean]
}>()

const selectedModel = ref<ErpFiscalModel>(props.model)
const defaultKind = (model: ErpFiscalModel) => model === '57' ? 'transport_service' : model === '58' ? 'transport_manifest' : 'posted_sale'
const kind = ref(defaultKind(props.model))
const idempotencyKey = ref(crypto.randomUUID())
const selectedOriginalId = ref<number | undefined>(undefined)
const selectedManifestDocumentId = ref<number | undefined>(undefined)
const facts = reactive<Record<string, string>>({
  operation_type: '', destination_type: '', final_consumer: '', presence_indicator: '', print_type: '',
  recipient_state_registration_indicator: '', recipient_street: '', recipient_number: '', recipient_district: '', recipient_city_code: '', recipient_city_name: '', recipient_zip_code: '', recipient_uf: '',
  freight_modality: '',
  payment_method: '', payment_amount: '',
  service_type: '', emission_type: '', operation_nature: '', cfop: '', sender_cnpj: '', sender_name: '', sender_state_registration: '', sender_state_registration_indicator: '', sender_street: '', sender_number: '', sender_district: '', sender_city_code: '', sender_city: '', sender_postal_code: '', sender_uf: '', receiver_cnpj: '', receiver_name: '', receiver_state_registration: '', receiver_state_registration_indicator: '', receiver_street: '', receiver_number: '', receiver_district: '', receiver_city_code: '', receiver_city: '', receiver_postal_code: '', receiver_uf: '', taker_role: '',
  origin_uf: '', origin_city_code: '', origin_city: '', destination_uf: '', destination_city_code: '', destination_city: '', pickup: '', rntrc: '',
  service_value: '', received_value: '', tax_cst: '', tax_base: '', tax_rate: '', tax_value: '', original_cte_access_key: '', cargo_description: '', cargo_measure_type: '', cargo_unit: '', cargo_quantity: '', cargo_value: '',
  manifest_emission_type: '', manifest_modal: '', manifest_issuer_type: '', manifest_document_model: '', manifest_rntrc: '', manifest_route_ufs: '', manifest_load_municipality_name: '', manifest_load_municipality_code: '', manifest_unload_municipality_name: '', manifest_unload_municipality_code: '', vehicle_plate: '', vehicle_renavam: '', vehicle_registry: '', vehicle_tare: '', vehicle_capacity: '', vehicle_road_type: '', vehicle_trailer_type: '', vehicle_uf: '', driver_name: '', driver_cpf: '',
  cargo_product_description: '', cargo_product_type: '', cargo_ncm: '', cargo_total_value: '', cargo_unit_manifest: '', cargo_quantity_manifest: '', cte_total: '', nfe_total: '', mdfe_total: '', access_key_reference: '', offline_mode: '', contingency_started_at: '', contingency_justification: ''
})
type ErpFiscalLineFormFacts = { description: string, ind_total: string, quantity: string, unit_price: string, gross_total: string, icms_base: string, icms_rate: string, icms_value: string, pis_value: string, cofins_value: string }
const lineFacts = ref<ErpFiscalLineFormFacts[]>([])
const totals = reactive<Record<string, string>>({})
const localError = ref('')
const formRoot = ref<HTMLElement | null>(null)
const typedKind = computed(() => selectedModel.value === '57' ? kind.value as 'transport_service' | 'complement' | 'substitution' : 'transport_manifest')
const typedDescriptors = computed(() => ['57', '58'].includes(selectedModel.value) ? erpTypedFiscalFieldDescriptors(selectedModel.value as '57' | '58', typedKind.value) : [])
const typedRequiredKeys = computed(() => new Set(requiredErpTypedFiscalFields(selectedModel.value as '57' | '58', typedKind.value, facts).map(field => field.key)))
const typedFieldErrors = computed(() => selectedModel.value === '57' || selectedModel.value === '58'
  ? mapErpTypedFiscalFieldErrors(props.fieldErrors ?? {}, selectedModel.value, typedKind.value)
  : { fields: {}, summary: [], corrections: [], firstFieldKey: null })
const cteServiceFields = computed(() => typedDescriptors.value.filter(field => ['service', 'tax', 'cargo'].includes(field.section)))
const ctePartyFields = computed(() => typedDescriptors.value.filter(field => field.section === 'party' && !field.key.startsWith('taker_')))
const cteTakerFields = computed(() => typedDescriptors.value.filter(field => field.key.startsWith('taker_')))
const cteRouteFields = computed(() => typedDescriptors.value.filter(field => field.section === 'route'))
const mdfeFields = computed(() => typedDescriptors.value)

function errorForTypedField(field: ErpTypedFiscalFieldDescriptor): string | undefined {
  return typedFieldErrors.value.fields[field.key]
}

function typedFieldRequired(field: ErpTypedFiscalFieldDescriptor): boolean {
  return typedRequiredKeys.value.has(field.key)
}

function focusTypedErrorTarget(key: string): void {
  focusErpTypedFiscalField(formRoot.value, key)
}

watch(() => [props.fieldErrors, selectedModel.value, typedKind.value] as const, async () => {
  if (selectedModel.value !== '57' && selectedModel.value !== '58') return
  await nextTick()
  focusErpTypedFiscalField(formRoot.value, typedFieldErrors.value.firstFieldKey)
}, { deep: true, flush: 'post', immediate: true })

function numericFact(key: string): number | undefined {
  const value = facts[key]
  return value === undefined || value === '' ? undefined : Number(value)
}

function validContingencyStartedAt(value: string | undefined): boolean {
  if (!value || value.length !== 25) return false
  const match = /^(20[0-9]{2})-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:[+-](?:0[0-9]|1[01]):00|\+12:00)$/.exec(value)
  if (!match) return false
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  const daysInMonth = [31, year % 4 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
  return day <= daysInMonth[month - 1]!
}

const models = [
  { label: 'NF-e (55)', value: '55' },
  { label: 'NFC-e (65)', value: '65' },
  { label: 'CT-e (57)', value: '57' },
  { label: 'MDF-e (58)', value: '58' }
]
const kinds = computed(() => {
  if (selectedModel.value === '55') return [{ label: 'Venda lançada', value: 'posted_sale' }, { label: 'Devolução lançada', value: 'return' }, { label: 'Complemento', value: 'complement' }]
  if (selectedModel.value === '57') return [{ label: 'Serviço de transporte', value: 'transport_service' }, { label: 'Complemento', value: 'complement' }, { label: 'Substituição', value: 'substitution' }]
  if (selectedModel.value === '58') return [{ label: 'Manifesto rodoviário', value: 'transport_manifest' }]
  return [{ label: 'Venda lançada', value: 'posted_sale' }]
})
const salesDocumentPurpose = computed(() => kind.value === 'return' ? 4 : kind.value === 'complement' ? 2 : 1)
const totalFields = [
  ['products', 'Produtos'], ['invoice', 'Total do documento'], ['icms_base', 'Base ICMS'], ['icms', 'ICMS'], ['icms_desonerated', 'ICMS desonerado'], ['fcp', 'FCP'], ['st_base', 'Base ICMS-ST'], ['st', 'ICMS-ST'], ['fcp_st', 'FCP-ST'], ['fcp_st_retained', 'FCP-ST retido'], ['freight', 'Frete'], ['insurance', 'Seguro'], ['discount', 'Desconto'], ['ii', 'Imposto de importação'], ['ipi', 'IPI'], ['ipi_returned', 'IPI devolvido'], ['pis', 'PIS'], ['cofins', 'COFINS'], ['other', 'Outras despesas'], ['taxes_total', 'Tributos aproximados']
] as const
const saleLines = computed<ErpFiscalSaleLine[]>(() => props.selectedSale?.commercial_snapshot?.lines ?? [])
const acceptedSaleSource = computed(() => {
  const accepted = props.selectedSale?.commercial_snapshot?.accepted
  return props.selectedSale?.status === 'posted' && accepted !== null && typeof accepted === 'object' && !Array.isArray(accepted)
})
const savedSourceContext = computed(() => erpTaxRecord(props.selectedSale?.commercial_snapshot?.fiscal_context))
const savedFiscalIntent = computed(() => {
  const model = props.selectedSale?.fiscal_model
  if (model === '55') return 'Intenção fiscal salva: NF-e (55)'
  if (model === '65') return 'Intenção fiscal salva: NFC-e (65)'
  return model === null ? 'Sem intenção fiscal declarada' : 'Intenção fiscal não informada neste histórico'
})
const sourceModelMismatch = computed(() => ['55', '65'].includes(props.selectedSale?.fiscal_model ?? '') && props.selectedSale?.fiscal_model !== selectedModel.value)
const frozenSaleRows = computed(() => saleLines.value.map((line) => {
  const facts = erpTaxRecord(line.product_snapshot)
  const product = erpTaxRecord(facts.product)
  const nature = erpTaxRecord(facts.operation_nature)
  const cfop = erpTaxRecord(facts.cfop_resolution)
  const taxSources = erpTaxRecord(erpTaxRecord(facts.product_tax_resolution).sources)
  return {
    id: line.id, ncm: savedValue(product.ncm), origin: savedValue(product.origin_code),
    effectiveCfop: savedValue(nature.cfop), defaultCfop: savedValue(cfop.default_cfop),
    cfopSource: erpTaxSourceLabel(cfop), cfopRevision: savedValue(cfop.configuration_revision),
    hasProvenance: Object.keys(cfop).length > 0 || Object.keys(taxSources).length > 0,
    taxSources: Object.entries(taxSources).map(([field, source]) => ({ field, value: savedValue(product[field]), label: erpTaxSourceLabel(source) }))
  }
}))
function savedValue(value: unknown): string {
  return (typeof value === 'string' && value.trim() !== '') || typeof value === 'number' ? String(value) : 'Não informado'
}
const sourceLines = computed<ErpFiscalSaleLine[]>(() => kind.value === 'return'
  ? (props.selectedReturn?.lines ?? []).map(line => ({ ...line, product_snapshot: line.source_snapshot?.line?.product_snapshot }))
  : saleLines.value)
const productionBlocked = computed(() => props.configuration?.release_gates.production_transmission_enabled !== true)
const offlineNfce = computed(() => selectedModel.value === '65' && props.configuration?.models['65']?.nfce_qr?.mode === 'offline')
const manifestDocuments = computed(() => props.originalDocuments.filter(row => ['55', '65', '57'].includes(row.model)
  && row.status === 'authorized'
  && row.erp_establishment_id === props.establishmentId
  && row.environment === props.configuration?.environment))
const selectedManifestDocument = computed(() => manifestDocuments.value.find(row => row.id === selectedManifestDocumentId.value) ?? null)

type LocalDraft = {
  facts: Record<string, string>
  totals: Record<string, string>
  lines: ErpFiscalLineFormFacts[]
  originalId?: number
  manifestId?: number
  key: ReturnType<typeof crypto.randomUUID>
  source: string
  baseline: string
  dirty: boolean
  sourceRequested: boolean
}
const localDrafts: Record<string, LocalDraft> = {}
const lastKinds: Partial<Record<ErpFiscalModel, string>> = {}
let activeModel = selectedModel.value
let activeKind = kind.value
let baseline = ''
let restoring = false
let sourceRequested = false
let activeSource = ''

function sourceIdentity() {
  if (activeModel !== '55' && activeModel !== '65') return ''
  return activeKind === 'return' ? `return:${props.selectedReturn?.id ?? ''}` : `sale:${props.selectedSale?.id ?? ''}`
}
function initialLines(): ErpFiscalLineFormFacts[] {
  return sourceLines.value.map(line => ({ description: line.product_snapshot?.product?.name ?? '', ind_total: '', quantity: '', unit_price: '', gross_total: '', icms_base: '', icms_rate: '', icms_value: '', pis_value: '', cofins_value: '' }))
}
function draftContent() {
  return JSON.stringify({ facts, totals, lines: lineFacts.value, originalId: selectedOriginalId.value, manifestId: selectedManifestDocumentId.value, source: activeSource })
}
function reportDirty() {
  emit('dirty', sourceRequested || draftContent() !== baseline || Object.entries(localDrafts).some(([key, draft]) => key !== `${activeModel}:${activeKind}` && draft.dirty))
}
function saveLocalDraft() {
  localDrafts[`${activeModel}:${activeKind}`] = JSON.parse(JSON.stringify({
    facts, totals, lines: lineFacts.value, originalId: selectedOriginalId.value, manifestId: selectedManifestDocumentId.value,
    key: idempotencyKey.value, source: activeSource, baseline, dirty: sourceRequested || draftContent() !== baseline, sourceRequested
  })) as LocalDraft
}
function activateDraft(model: ErpFiscalModel, documentKind: string) {
  activeModel = model
  activeKind = documentKind
  lastKinds[model] = documentKind
  const draft = localDrafts[`${model}:${documentKind}`]
  for (const field of Object.keys(facts)) facts[field] = draft?.facts[field] ?? ''
  for (const field of Object.keys(totals)) Reflect.deleteProperty(totals, field)
  Object.assign(totals, draft?.totals ?? {})
  selectedOriginalId.value = draft?.originalId
  selectedManifestDocumentId.value = draft?.manifestId
  idempotencyKey.value = draft?.key ?? crypto.randomUUID()
  lineFacts.value = draft?.source === sourceIdentity() ? draft.lines : initialLines()
  activeSource = sourceIdentity()
  baseline = draft && (draft.dirty || draft.source === activeSource) ? draft.baseline : draftContent()
  sourceRequested = draft?.sourceRequested ?? false
  localError.value = ''
  reportDirty()
}
activateDraft(activeModel, activeKind)

watch(selectedModel, (model, previous) => {
  if (restoring || model === previous) return
  if (props.busy) {
    restoring = true
    selectedModel.value = previous
    restoring = false
    return
  }
  saveLocalDraft()
  restoring = true
  kind.value = lastKinds[model] ?? defaultKind(model)
  activateDraft(model, kind.value)
  restoring = false
  if (model !== props.model) emit('update:model', model)
}, { flush: 'sync' })
watch(kind, (documentKind, previous) => {
  if (restoring || documentKind === previous) return
  if (props.busy) {
    restoring = true
    kind.value = previous
    restoring = false
    return
  }
  saveLocalDraft()
  activateDraft(selectedModel.value, documentKind)
}, { flush: 'sync' })
watch(() => props.model, (value) => {
  selectedModel.value = value
})
watch(() => [props.selectedSale?.id, props.selectedReturn?.id], () => {
  const pristine = !sourceRequested && draftContent() === baseline
  lineFacts.value = initialLines()
  activeSource = sourceIdentity()
  if (pristine) baseline = draftContent()
  sourceRequested = false
  reportDirty()
}, { flush: 'sync' })
watch([facts, totals, lineFacts, selectedOriginalId, selectedManifestDocumentId], reportDirty, { deep: true, flush: 'post' })

function selectSource(type: 'sale' | 'return', id: number) {
  sourceRequested = true
  reportDirty()
  if (type === 'sale') emit('select-sale', id)
  else emit('select-return', id)
}

function makeTypedSaleFacts(): ErpFiscalSaleFormFacts {
  const payment = facts.payment_method && facts.payment_amount ? [{ method: facts.payment_method, amount: facts.payment_amount }] : []
  return {
    identity: {
      operation_type: Number(facts.operation_type), destination_type: Number(facts.destination_type), purpose: salesDocumentPurpose.value,
      final_consumer: Number(facts.final_consumer), presence_indicator: Number(facts.presence_indicator), print_type: Number(facts.print_type),
      ...(offlineNfce.value ? { contingency_started_at: facts.contingency_started_at, contingency_justification: facts.contingency_justification } : {})
    },
    recipient: {
      state_registration_indicator: Number(facts.recipient_state_registration_indicator),
      address: { street: facts.recipient_street, number: facts.recipient_number, district: facts.recipient_district, city_code: facts.recipient_city_code, city: facts.recipient_city_name, postal_code: facts.recipient_zip_code, uf: facts.recipient_uf }
    },
    totals: Object.fromEntries(totalFields.map(([key]) => [key, totals[key] ?? ''])),
    freight: { modality: Number(facts.freight_modality) },
    payment,
    itemFacts: lineFacts.value.map(line => ({
      description: line.description,
      ind_total: Number(line.ind_total) as 0 | 1,
      tax: { icms_base: line.icms_base, icms_rate: line.icms_rate, icms_value: line.icms_value, pis_value: line.pis_value, cofins_value: line.cofins_value }
    }))
  }
}

function createSaleBody() {
  const sale = kind.value === 'return' ? props.selectedReturn : props.selectedSale
  if (kind.value === 'complement' && !selectedOriginalId.value) throw new Error('Selecione o documento fiscal original autorizado.')
  if (!sale) throw new Error(kind.value === 'return' ? 'Selecione uma devolução lançada.' : 'Selecione uma venda lançada.')
  if (kind.value === 'return' && !selectedOriginalId.value) throw new Error('Selecione o documento fiscal original autorizado.')
  const required = [facts.operation_type, facts.destination_type, facts.final_consumer, facts.presence_indicator, facts.print_type, facts.recipient_state_registration_indicator, facts.recipient_street, facts.recipient_number, facts.recipient_district, facts.recipient_city_code, facts.recipient_city_name, facts.recipient_zip_code, facts.recipient_uf, facts.freight_modality, facts.payment_method, facts.payment_amount, ...totalFields.map(([key]) => totals[key]), ...lineFacts.value.flatMap(line => [line.description, line.ind_total, line.icms_base, line.icms_rate, line.icms_value, line.pis_value, line.cofins_value, ...(kind.value === 'complement' ? [line.quantity, line.unit_price, line.gross_total] : [])])]
  if (required.some(value => value === '' || value === null || value === undefined)) throw new Error('Preencha todos os campos fiscais obrigatórios. Valores zero precisam ser informados explicitamente.')
  if (offlineNfce.value && !validContingencyStartedAt(facts.contingency_started_at)) {
    throw new Error('Informe uma data válida de início da contingência com segundos e fuso UTC numérico, como 2026-10-21T14:00:00-03:00. O fuso deve ser uma hora inteira entre -11:00 e +12:00.')
  }
  if (offlineNfce.value && ((facts.contingency_justification ?? '').trim().length < 15 || (facts.contingency_justification ?? '').trim().length > 256)) {
    throw new Error('Para NFC-e offline, informe uma justificativa entre 15 e 256 caracteres.')
  }
  const formFacts = makeTypedSaleFacts()
  if (kind.value === 'return' && !facts.cfop) throw new Error('Informe o CFOP de entrada configurado para a devolução (1202 ou 2202).')
  formFacts.operationNature = { cfop: String(facts.cfop || (kind.value === 'return' ? '' : sale.commercial_snapshot?.lines?.[0]?.product_snapshot?.operation_nature?.cfop) || ''), ...(kind.value === 'return' ? { movement: 'in' as const } : {}) }
  if (!formFacts.operationNature.cfop) throw new Error('Informe o CFOP confirmado para a operação.')
  const sourceKind = kind.value === 'return' ? 'return' : kind.value === 'complement' ? 'complement' : 'posted_sale'
  const generated = buildErpFiscalDraftSource({ kind: sourceKind, ...(kind.value === 'return' ? { returnDocument: sale, returnLines: props.selectedReturn?.lines } : { sale }), facts: { ...formFacts, itemFacts: formFacts.itemFacts.map((line, index) => ({ ...line, ...(kind.value === 'complement' ? { quantity: lineFacts.value[index]?.quantity, unitPrice: lineFacts.value[index]?.unit_price, grossTotal: lineFacts.value[index]?.gross_total } : {}) })) } })
  return {
    erp_establishment_id: props.establishmentId,
    ...(kind.value === 'return' ? { erp_sale_return_id: generated.erp_sale_return_id, source_document_id: selectedOriginalId.value } : { ...(kind.value === 'posted_sale' ? { erp_sale_id: generated.erp_sale_id } : {}), ...(kind.value === 'complement' ? { source_document_id: selectedOriginalId.value } : {}) }),
    model: selectedModel.value,
    environment: props.configuration?.environment,
    document_kind: kind.value,
    idempotency_key: idempotencyKey.value,
    source: generated.source
  }
}

function transportSource() {
  if (selectedModel.value === '57') {
    const cteType = kind.value === 'complement' ? 1 : kind.value === 'substitution' ? 3 : 0
    const party = (prefix: 'sender' | 'receiver') => ({ cnpj: facts[`${prefix}_cnpj`], legal_name: facts[`${prefix}_name`], ...(facts[`${prefix}_state_registration`] ? { state_registration: facts[`${prefix}_state_registration`] } : {}), ...(facts[`${prefix}_state_registration_indicator`] ? { state_registration_indicator: Number(facts[`${prefix}_state_registration_indicator`]) } : {}), city_code: facts[`${prefix}_city_code`], uf: facts[`${prefix}_uf`], address: { street: facts[`${prefix}_street`], number: facts[`${prefix}_number`], district: facts[`${prefix}_district`], city_code: facts[`${prefix}_city_code`], city: facts[`${prefix}_city`], postal_code: facts[`${prefix}_postal_code`], uf: facts[`${prefix}_uf`] } })
    const taker = { role: Number(facts.taker_role), ...(facts.taker_role === '4' ? { cnpj: facts.taker_cnpj, legal_name: facts.taker_name, address: { street: facts.taker_street, number: facts.taker_number, district: facts.taker_district, city_code: facts.taker_city_code, city: facts.taker_city, postal_code: facts.taker_postal_code, uf: facts.taker_uf } } : {}) }
    return { transport_service: {
      cte_type: cteType, service_type: Number(facts.service_type), emission_type: Number(facts.emission_type), modal: '01', operation_nature: facts.operation_nature,
      cfop: facts.cfop, service_value: facts.service_value, received_value: facts.received_value, rntrc: facts.rntrc,
      sender: party('sender'), receiver: party('receiver'), taker,
      route: { origin_uf: facts.origin_uf, origin_city_code: facts.origin_city_code, origin_city: facts.origin_city, destination_uf: facts.destination_uf, destination_city_code: facts.destination_city_code, destination_city: facts.destination_city, pickup: Number(facts.pickup) },
      tax: { cst: facts.tax_cst, base: facts.tax_base, rate: facts.tax_rate, value: facts.tax_value },
      cargo: { description: facts.cargo_description, ...(facts.cargo_measure_type ? { measure_type: facts.cargo_measure_type } : {}), unit: facts.cargo_unit, quantity: facts.cargo_quantity, value: facts.cargo_value },
      ...(kind.value !== 'transport_service' ? { original_cte_access_key: props.originalDocuments.find(row => row.id === selectedOriginalId.value)?.access_key ?? '' } : {})
    } }
  }
  return { transport_manifest: {
    emission_type: Number(facts.manifest_emission_type), modal: facts.manifest_modal, issuer_type: Number(facts.manifest_issuer_type), rntrc: facts.manifest_rntrc,
    route_ufs: (facts.manifest_route_ufs ?? '').split(',').map((value: string) => value.trim()).filter(Boolean),
    municipalities: [{ name: facts.manifest_load_municipality_name, code: facts.manifest_load_municipality_code }, { name: facts.manifest_unload_municipality_name, code: facts.manifest_unload_municipality_code }],
    vehicle: { plate: facts.vehicle_plate, renavam: facts.vehicle_renavam, registry: facts.vehicle_registry, tare: facts.vehicle_tare, capacity_kg: facts.vehicle_capacity, road_type: facts.vehicle_road_type, trailer_type: facts.vehicle_trailer_type, uf: facts.vehicle_uf },
    driver: { name: facts.driver_name, cpf: facts.driver_cpf },
    documents: [{ model: selectedManifestDocument.value?.model ?? facts.manifest_document_model, access_key: selectedManifestDocument.value?.access_key ?? facts.access_key_reference, municipality_code: facts.manifest_unload_municipality_code }],
    cargo: { product_description: facts.cargo_product_description, product_type: facts.cargo_product_type, ncm: facts.cargo_ncm, total_value: facts.cargo_total_value, unit: facts.cargo_unit_manifest, quantity: facts.cargo_quantity_manifest },
    total_cte: Number(facts.cte_total), total_nfe: Number(facts.nfe_total), total_mdfe: Number(facts.mdfe_total)
  } }
}

function createTransportBody() {
  const kindValue = kind.value
  const transportRequired = requiredErpTypedFiscalFields(selectedModel.value as '57' | '58', typedKind.value, facts)
  if (transportRequired.some(field => facts[field.key] === '' || facts[field.key] === null || facts[field.key] === undefined)) throw new Error('Preencha os dados reais obrigatórios deste modelo. O formulário não completa valores ausentes.')
  if (selectedModel.value === '58') {
    if ((facts.manifest_route_ufs ?? '').split(',').map(value => value.trim()).filter(Boolean).length < 2) throw new Error('Informe ao menos duas UFs reais para o percurso do MDF-e.')
    const referenceModel = selectedManifestDocument.value?.model ?? facts.manifest_document_model
    const referenceKey = selectedManifestDocument.value?.access_key ?? facts.access_key_reference
    if (!['55', '65', '57'].includes(referenceModel ?? '') || !/^\d{44}$/.test(referenceKey ?? '')) throw new Error('Informe um documento autorizado para referência: selecione um documento disponível ou digite o modelo e a chave de acesso de 44 dígitos.')
  }
  const source = transportSource()
  if (kindValue !== 'transport_service' && selectedModel.value === '57' && !selectedOriginalId.value) throw new Error('Selecione o CT-e original autorizado.')
  return {
    erp_establishment_id: props.establishmentId,
    ...(selectedOriginalId.value ? { source_document_id: selectedOriginalId.value } : {}),
    model: selectedModel.value,
    environment: props.configuration?.environment,
    document_kind: kindValue,
    idempotency_key: idempotencyKey.value,
    source: { identity: {}, ...source }
  }
}

function submit() {
  localError.value = ''
  try {
    emit('create', ['55', '65'].includes(selectedModel.value) ? createSaleBody() : createTransportBody())
  } catch (error) {
    localError.value = error instanceof Error ? error.message : 'Revise os dados fiscais informados.'
  }
}
</script>

<template>
  <div ref="formRoot">
    <UForm :state="facts" class="space-y-5" @submit="submit">
      <UAlert
        v-for="message in typedFieldErrors.summary"
        :key="message"
        color="error"
        variant="subtle"
        title="Revise os dados fiscais rejeitados"
        :description="message"
      />
      <section
        v-for="correction in typedFieldErrors.corrections"
        :key="correction.sourcePath"
        role="group"
        :aria-label="correction.title"
        class="space-y-2"
      >
        <UAlert
          color="error"
          variant="subtle"
          :title="correction.title"
          :description="correction.description"
        />
        <div class="flex flex-wrap gap-2">
          <UButton
            v-for="target in correction.targets"
            :key="target.key"
            type="button"
            variant="outline"
            :aria-label="`Ir para ${target.label}`"
            :data-fiscal-error-target="target.key"
            @click="focusTypedErrorTarget(target.key)"
          >
            {{ target.label }}
          </UButton>
        </div>
      </section>
      <UAlert
        v-if="configuration?.release_gates.provider === 'unavailable'"
        color="warning"
        variant="subtle"
        title="Transmissão indisponível"
        description="A emissão permanece bloqueada enquanto o serviço fiscal não estiver disponível. O rascunho pode ser preparado para validação local."
      />
      <UAlert
        v-else-if="productionBlocked"
        color="warning"
        variant="subtle"
        title="Produção bloqueada"
        description="A transmissão em produção continua desabilitada pelas regras atuais."
      />
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField label="Modelo fiscal" name="model">
          <USelect
            v-model="selectedModel"
            :items="models"
            value-key="value"
            label-key="label"
            :disabled="busy"
          />
        </UFormField>
        <UFormField label="Tipo de documento" name="document_kind">
          <USelect
            v-model="kind"
            :items="kinds"
            value-key="value"
            label-key="label"
            :disabled="busy"
          />
        </UFormField>
      </div>
      <p class="text-muted text-sm">
        Série configurada: {{ configuration?.models[selectedModel]?.series ?? 'não configurada' }} · Próximo número atual: {{ configuration?.models[selectedModel]?.next_number ?? 'indisponível' }}. O número é reservado pelo servidor ao criar o rascunho.
      </p>

      <template v-if="selectedModel === '55' || selectedModel === '65'">
        <UFormField
          v-if="kind !== 'return'"
          label="Venda lançada para preservar o snapshot comercial"
          name="erp_sale_id"
          :error="fieldErrors?.erp_sale_id"
        >
          <USelect
            :model-value="selectedSale?.id ?? undefined"
            :items="sales.map(sale => ({ label: `Venda #${sale.id} · ${sale.status}`, value: sale.id }))"
            value-key="value"
            label-key="label"
            placeholder="Selecione uma venda lançada"
            @update:model-value="selectSource('sale', Number($event))"
          />
        </UFormField>
        <UFormField
          v-else
          label="Devolução lançada"
          name="erp_sale_return_id"
          :error="fieldErrors?.erp_sale_return_id"
        >
          <USelect
            :model-value="selectedReturn?.id ?? undefined"
            :items="returns.map(row => ({ label: `Devolução #${row.id} · ${row.status}`, value: row.id }))"
            value-key="value"
            label-key="label"
            placeholder="Selecione uma devolução lançada"
            @update:model-value="selectSource('return', Number($event))"
          />
        </UFormField>
        <section v-if="kind === 'posted_sale' && selectedSale" aria-label="Fonte comercial da venda" class="space-y-3 rounded-lg border border-default p-4 text-sm">
          <template v-if="acceptedSaleSource">
            <h3 class="font-semibold">
              Fonte comercial congelada · Venda #{{ selectedSale.id }}
            </h3>
            <p class="text-muted">
              {{ savedFiscalIntent }}
            </p>
            <p v-if="savedSourceContext.readiness === 'source_prerequisites_checked'" class="text-muted">
              Pré-requisitos da fonte comercial verificados no lançamento.
            </p>
            <p v-else class="text-muted">
              A verificação dos pré-requisitos da fonte comercial não está confirmada neste histórico.
            </p>
            <UAlert
              v-if="sourceModelMismatch"
              color="warning"
              variant="subtle"
              title="Modelo selecionado para emissão difere da intenção fiscal salva"
              description="Revise a seleção. O servidor mantém a validação de compatibilidade da fonte comercial."
            />
            <div v-for="line in frozenSaleRows" :key="line.id" class="space-y-1 rounded-md bg-elevated p-3">
              <p class="font-medium">
                Linha #{{ line.id }} · NCM {{ line.ncm }} · Origem {{ line.origin }}
              </p>
              <p>CFOP efetivo: {{ line.effectiveCfop }} · Padrão da natureza salvo: {{ line.defaultCfop }}</p>
              <p class="text-muted">
                {{ line.cfopSource }} · Revisão CFOP: {{ line.cfopRevision }}
              </p>
              <p v-for="source in line.taxSources" :key="source.field" class="text-muted">
                {{ source.field }}: {{ source.value }} · {{ source.label }}
              </p>
            </div>
            <p v-if="!frozenSaleRows.length" class="text-muted">
              Não há classificação por item disponível neste histórico.
            </p>
            <p v-if="frozenSaleRows.some(line => !line.hasProvenance)" class="text-muted">
              Metadados de resolução não disponíveis neste histórico. A validação fiscal mantém as verificações da fonte aceita.
            </p>
          </template>
          <UAlert
            v-else
            color="warning"
            variant="subtle"
            title="Fonte comercial sem confirmação de snapshot aceito"
            description="Os fatos congelados não podem ser confirmados nesta seleção. A validação fiscal indicará os dados necessários; o catálogo atual não será usado para preencher o histórico."
          />
          <p class="text-muted">
            A emissão ainda valida os dados próprios do modelo, identidade fiscal, inscrição do destinatário, certificado e valores tributários. Este resumo não representa autorização.
          </p>
        </section>
        <template v-if="selectedSale || selectedReturn">
          <p class="text-muted text-sm">
            Os itens e a identidade comercial vêm do snapshot lançado. Dados fiscais sem fonte confirmada precisam ser informados; nenhum valor tributário será preenchido automaticamente.
          </p>
          <UFormField
            v-if="['return', 'complement'].includes(kind)"
            label="Documento fiscal original autorizado"
            name="source_document_id"
            :error="fieldErrors?.source_document_id"
          >
            <USelect
              v-model="selectedOriginalId"
              :items="originalDocuments.filter(row => ['55', '65'].includes(row.model) && row.status === 'authorized' && row.erp_establishment_id === establishmentId && row.environment === configuration?.environment).map(row => ({ label: `${row.model} · ${row.access_key}`, value: row.id }))"
              value-key="value"
              label-key="label"
              placeholder="Selecione o documento original"
            />
          </UFormField>
          <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium">
              Identificação da operação
            </legend>
            <UFormField
              v-for="field in [{ key: 'operation_type', label: 'Tipo da operação (0 entrada, 1 saída)' }, { key: 'destination_type', label: 'Destino (1 interna, 2 interestadual, 3 exterior)' }, { key: 'final_consumer', label: 'Consumidor final (0 não, 1 sim)' }, { key: 'presence_indicator', label: 'Presença do comprador' }, { key: 'print_type', label: 'Tipo de impressão' }]"
              :key="field.key"
              :label="field.label"
              :name="`identity.${field.key}`"
              :error="fieldErrors?.[`source.identity.${field.key}`]"
            >
              <UInput v-model="facts[field.key]" inputmode="numeric" />
            </UFormField>
            <UFormField label="Finalidade definida pelo tipo de documento" name="identity.purpose" :error="fieldErrors?.['source.identity.purpose']">
              <UInput :model-value="String(salesDocumentPurpose)" readonly />
            </UFormField>
            <UFormField label="CFOP confirmado para esta operação" name="operation_nature.cfop" :error="fieldErrors?.['source.operation_nature.cfop']">
              <UInput v-model="facts.cfop" inputmode="numeric" />
            </UFormField>
          </fieldset>
          <fieldset v-if="offlineNfce" class="grid gap-3 rounded-lg border border-warning p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium">
              Contingência offline NFC-e
            </legend>
            <p class="text-muted text-sm sm:col-span-2">
              Informe fatos reais da contingência. O sistema não cria horário nem justificativa automaticamente.
            </p>
            <UFormField
              label="Início da contingência (data, hora, segundos e fuso UTC)"
              description="Informe o fuso real em horas inteiras, entre -11:00 e +12:00. Exemplo: 2026-10-21T14:00:00-03:00."
              name="identity.contingency_started_at"
              :error="fieldErrors?.['source.identity.contingency_started_at']"
            >
              <UInput
                v-model="facts.contingency_started_at"
                type="text"
                placeholder="2026-10-21T14:00:00-03:00"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Justificativa (15 a 256 caracteres)" name="identity.contingency_justification" :error="fieldErrors?.['source.identity.contingency_justification']">
              <UTextarea v-model="facts.contingency_justification" maxlength="256" />
            </UFormField>
          </fieldset>
          <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium">
              Endereço fiscal do destinatário
            </legend>
            <UFormField label="Indicador de inscrição estadual" name="recipient.state_registration_indicator" :error="fieldErrors?.['source.recipient.state_registration_indicator']">
              <UInput v-model="facts.recipient_state_registration_indicator" inputmode="numeric" />
            </UFormField>
            <UFormField
              v-for="field in [{ key: 'recipient_street', path: 'street', label: 'Logradouro' }, { key: 'recipient_number', path: 'number', label: 'Número' }, { key: 'recipient_district', path: 'district', label: 'Bairro' }, { key: 'recipient_city_code', path: 'city_code', label: 'Código IBGE do município' }, { key: 'recipient_city_name', path: 'city', label: 'Município' }, { key: 'recipient_zip_code', path: 'postal_code', label: 'CEP' }, { key: 'recipient_uf', path: 'uf', label: 'UF' }]"
              :key="field.key"
              :label="field.label"
              :name="`recipient.address.${field.path}`"
              :error="fieldErrors?.[`source.recipient.address.${field.path}`]"
            >
              <UInput v-model="facts[field.key]" />
            </UFormField>
          </fieldset>
          <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium">
              Tributação por item
            </legend>
            <div v-for="(line, index) in sourceLines" :key="line.id" class="col-span-full grid gap-3 rounded-md bg-elevated p-3 sm:grid-cols-2">
              <p class="text-sm font-medium sm:col-span-2">
                {{ line.product_snapshot?.product?.name ?? line.product_snapshot?.product?.sku ?? `Item ${index + 1}` }} · {{ line.quantity }} {{ line.product_snapshot?.unit?.short_name }}
              </p>
              <UFormField label="Descrição fiscal" :name="`items.${index}.description`">
                <UInput v-model="lineFacts[index]!.description" />
              </UFormField>
              <template v-if="kind === 'complement'">
                <UFormField label="Quantidade complementar" :name="`items.${index}.quantity`">
                  <UInput v-model="lineFacts[index]!.quantity" inputmode="decimal" />
                </UFormField>
                <UFormField label="Valor unitário complementar" :name="`items.${index}.unit_price`">
                  <UInput v-model="lineFacts[index]!.unit_price" inputmode="decimal" />
                </UFormField>
                <UFormField label="Valor bruto complementar" :name="`items.${index}.gross_total`">
                  <UInput v-model="lineFacts[index]!.gross_total" inputmode="decimal" />
                </UFormField>
              </template>
              <UFormField label="Incluir no total (0 não, 1 sim)" :name="`items.${index}.ind_total`" :error="fieldErrors?.[`source.items.${index}.ind_total`]">
                <UInput v-model="lineFacts[index]!.ind_total" inputmode="numeric" />
              </UFormField>
              <UFormField
                v-for="field in [{ key: 'icms_base', label: 'Base ICMS' }, { key: 'icms_rate', label: 'Alíquota ICMS' }, { key: 'icms_value', label: 'Valor ICMS' }, { key: 'pis_value', label: 'Valor PIS' }, { key: 'cofins_value', label: 'Valor COFINS' }]"
                :key="field.key"
                :label="field.label"
                :name="`items.${index}.tax.${field.key}`"
                :error="fieldErrors?.[`source.items.${index}.tax.${field.key}`]"
              >
                <UInput v-model="lineFacts[index]![field.key]" inputmode="decimal" />
              </UFormField>
            </div>
          </fieldset>
          <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium">
              Totais fiscais
            </legend>
            <UFormField
              v-for="[key, label] in totalFields"
              :key="key"
              :label="label"
              :name="`source.totals.${key}`"
              :error="fieldErrors?.[`source.totals.${key}`]"
            >
              <UInput v-model="totals[key]" inputmode="decimal" />
            </UFormField>
          </fieldset>
          <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium">
              Frete e pagamento
            </legend>
            <UFormField label="Modalidade do frete" name="freight.modality" :error="fieldErrors?.['source.freight.modality']">
              <UInput v-model="facts.freight_modality" inputmode="numeric" />
            </UFormField>
            <UFormField label="Meio de pagamento" name="payment.0.method" :error="fieldErrors?.['source.payment.0.method']">
              <UInput v-model="facts.payment_method" />
            </UFormField>
            <UFormField label="Valor pago" name="payment.0.amount" :error="fieldErrors?.['source.payment.0.amount']">
              <UInput v-model="facts.payment_amount" inputmode="decimal" />
            </UFormField>
          </fieldset>
        </template>
      </template>

      <template v-else-if="selectedModel === '57'">
        <UFormField
          v-if="kind !== 'transport_service'"
          label="CT-e original autorizado"
          name="source_document_id"
          :error="fieldErrors?.source_document_id"
        >
          <USelect
            v-model="selectedOriginalId"
            :items="originalDocuments.filter(row => row.model === '57' && row.status === 'authorized' && row.erp_establishment_id === establishmentId && row.environment === configuration?.environment).map(row => ({ label: `CT-e ${row.access_key}`, value: row.id }))"
            value-key="value"
            label-key="label"
            placeholder="Selecione o documento original"
          />
        </UFormField>
        <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
          <legend class="px-1 text-sm font-medium">
            Serviço, tributos e carga
          </legend>
          <UFormField
            v-for="field in cteServiceFields"
            :key="field.key"
            :label="field.label"
            :name="`source.${field.path}`"
            :required="typedFieldRequired(field)"
            :error="errorForTypedField(field)"
          >
            <USelect
              v-if="field.control === 'select'"
              :data-fiscal-field-key="field.key"
              :model-value="field.key === 'tax_cst' ? facts[field.key] : numericFact(field.key)"
              :items="field.options"
              value-key="value"
              label-key="label"
              @update:model-value="facts[field.key] = String($event ?? '')"
            />
            <UInput
              v-else
              v-model="facts[field.key]"
              :data-fiscal-field-key="field.key"
              :maxlength="field.maxlength"
              :inputmode="field.inputMode"
            />
          </UFormField>
          <p class="text-muted text-sm sm:col-span-2">
            O suporte local exige modal rodoviário. Informe participantes e município de origem/destino com os dados do CT-e; a substituição também exige comprovação externa ainda não disponível para transmissão.
          </p>
        </fieldset>
        <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
          <legend class="px-1 text-sm font-medium">
            Remetente e destinatário
          </legend>
          <UFormField
            v-for="field in ctePartyFields"
            :key="field.key"
            :label="field.label"
            :name="`source.${field.path}`"
            :required="typedFieldRequired(field)"
            :error="errorForTypedField(field)"
          >
            <USelect
              v-if="field.control === 'select'"
              :data-fiscal-field-key="field.key"
              :model-value="numericFact(field.key)"
              :items="field.options"
              value-key="value"
              label-key="label"
              @update:model-value="facts[field.key] = String($event ?? '')"
            />
            <UInput
              v-else
              v-model="facts[field.key]"
              :data-fiscal-field-key="field.key"
              :inputmode="field.inputMode"
            />
          </UFormField>
        </fieldset>
        <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
          <legend class="px-1 text-sm font-medium">
            Tomador e percurso
          </legend>
          <UFormField
            v-for="field in [...cteTakerFields.filter(field => field.key === 'taker_role'), ...cteRouteFields]"
            :key="field.key"
            :label="field.label"
            :name="`source.${field.path}`"
            :required="typedFieldRequired(field)"
            :error="errorForTypedField(field)"
          >
            <USelect
              v-if="field.control === 'select'"
              :data-fiscal-field-key="field.key"
              :model-value="numericFact(field.key)"
              :items="field.options"
              value-key="value"
              label-key="label"
              @update:model-value="facts[field.key] = String($event ?? '')"
            />
            <UInput
              v-else
              v-model="facts[field.key]"
              :data-fiscal-field-key="field.key"
              :inputmode="field.inputMode"
            />
          </UFormField>
          <template v-if="facts.taker_role === '4'">
            <UFormField
              v-for="field in cteTakerFields.filter(field => field.key !== 'taker_role')"
              :key="field.key"
              :label="field.label"
              :name="`source.${field.path}`"
              :required="typedFieldRequired(field)"
              :error="errorForTypedField(field)"
            >
              <UInput v-model="facts[field.key]" :data-fiscal-field-key="field.key" :inputmode="field.inputMode" />
            </UFormField>
          </template>
        </fieldset>
      </template>

      <template v-else>
        <fieldset class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
          <legend class="px-1 text-sm font-medium">
            Manifesto rodoviário
          </legend>
          <p class="text-muted text-sm sm:col-span-2">
            O fluxo local exige veículo próprio, pelo menos dois estados e um documento fiscal correlacionado. Escolha um documento autorizado disponível ou informe seu modelo e chave de acesso. O servidor valida a referência fiscal.
          </p>
          <UFormField
            v-for="field in mdfeFields.filter(field => field.section !== 'reference')"
            :key="field.key"
            :label="field.label"
            :name="`source.${field.path}`"
            :required="typedFieldRequired(field)"
            :error="errorForTypedField(field)"
          >
            <USelect
              v-if="field.control === 'select'"
              :data-fiscal-field-key="field.key"
              :model-value="numericFact(field.key)"
              :items="field.options"
              value-key="value"
              label-key="label"
              @update:model-value="facts[field.key] = String($event ?? '')"
            />
            <UInput
              v-else
              v-model="facts[field.key]"
              :data-fiscal-field-key="field.key"
              :maxlength="field.maxlength"
              :inputmode="field.inputMode"
            />
          </UFormField>
          <UFormField label="Documento autorizado disponível" name="transport_manifest.document">
            <USelect
              v-model="selectedManifestDocumentId"
              data-fiscal-field-key="selected_manifest_document"
              :items="manifestDocuments.map(row => ({ label: `${row.model} · ${row.access_key}`, value: row.id }))"
              value-key="value"
              label-key="label"
              placeholder="Selecione um documento autorizado"
            />
          </UFormField>
          <UFormField label="Modelo da referência externa (55, 65 ou 57)" name="source.transport_manifest.documents.0.model" :error="errorForTypedField(mdfeFields.find(field => field.key === 'manifest_document_model')!)">
            <USelect
              v-model="facts.manifest_document_model"
              data-fiscal-field-key="manifest_document_model"
              :items="[{ label: 'NF-e (55)', value: '55' }, { label: 'NFC-e (65)', value: '65' }, { label: 'CT-e (57)', value: '57' }]"
              value-key="value"
              label-key="label"
            />
          </UFormField>
          <UFormField label="Chave de acesso da referência (44 dígitos)" name="source.transport_manifest.documents.0.access_key" :error="errorForTypedField(mdfeFields.find(field => field.key === 'access_key_reference')!)">
            <UInput
              v-model="facts.access_key_reference"
              data-fiscal-field-key="access_key_reference"
              maxlength="44"
              inputmode="numeric"
            />
          </UFormField>
        </fieldset>
      </template>

      <UAlert
        v-if="localError"
        color="error"
        variant="subtle"
        :description="localError"
      />
      <UAlert
        v-if="fieldErrors?.source && selectedModel !== '57' && selectedModel !== '58'"
        color="error"
        variant="subtle"
        :description="fieldErrors.source"
      />
      <div class="flex justify-end">
        <UButton type="submit" :disabled="!canCreate || busy || !configuration?.models[selectedModel]?.readiness || configuration.models[selectedModel]?.readiness.status === 'blocked'" :loading="busy">
          Preparar rascunho fiscal
        </UButton>
      </div>
    </UForm>
  </div>
</template>
