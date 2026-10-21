<script setup lang="ts">
import type { ErpFiscalConfiguration, ErpFiscalDocument, ErpFiscalModel, ErpFiscalSale, ErpFiscalSaleLine } from '#shared/types/erpFiscal'
import { buildErpFiscalDraftSource, type ErpFiscalSaleFormFacts } from '#shared/utils/erpFiscal'

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
  'create': [body: Record<string, unknown>]
  'select-sale': [id: number]
  'select-return': [id: number]
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

watch(selectedModel, (model, previous) => {
  kind.value = defaultKind(model)
  selectedOriginalId.value = undefined
  selectedManifestDocumentId.value = undefined
  localError.value = ''
  idempotencyKey.value = crypto.randomUUID()
  if (model !== previous) {
    for (const key of Object.keys(facts)) facts[key] = ''
    for (const key of Object.keys(totals)) totals[key] = ''
  }
})
watch(() => props.model, (value) => {
  selectedModel.value = value
})
watch(() => [kind.value, props.selectedSale?.id, props.selectedReturn?.id], () => {
  lineFacts.value = sourceLines.value.map(line => ({ description: line.product_snapshot?.product?.name ?? '', ind_total: '', quantity: '', unit_price: '', gross_total: '', icms_base: '', icms_rate: '', icms_value: '', pis_value: '', cofins_value: '' }))
}, { immediate: true })

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
    const party = (prefix: 'sender' | 'receiver') => ({ cnpj: facts[`${prefix}_cnpj`], legal_name: facts[`${prefix}_name`], state_registration: facts[`${prefix}_state_registration`], state_registration_indicator: Number(facts[`${prefix}_state_registration_indicator`]), address: { street: facts[`${prefix}_street`], number: facts[`${prefix}_number`], district: facts[`${prefix}_district`], city_code: facts[`${prefix}_city_code`], city: facts[`${prefix}_city`], postal_code: facts[`${prefix}_postal_code`], uf: facts[`${prefix}_uf`] } })
    return { transport_service: {
      cte_type: cteType, service_type: Number(facts.service_type), emission_type: Number(facts.emission_type), modal: '01', operation_nature: facts.operation_nature,
      cfop: facts.cfop, service_value: facts.service_value, received_value: facts.received_value, rntrc: facts.rntrc,
      sender: party('sender'), receiver: party('receiver'), taker: { role: Number(facts.taker_role) },
      route: { origin_uf: facts.origin_uf, origin_city_code: facts.origin_city_code, origin_city: facts.origin_city, destination_uf: facts.destination_uf, destination_city_code: facts.destination_city_code, destination_city: facts.destination_city, pickup: Number(facts.pickup) },
      tax: { cst: facts.tax_cst, base: facts.tax_base, rate: facts.tax_rate, value: facts.tax_value },
      cargo: { description: facts.cargo_description, measure_type: facts.cargo_measure_type, unit: facts.cargo_unit, quantity: facts.cargo_quantity, value: facts.cargo_value },
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
  const transportRequired = selectedModel.value === '57'
    ? ['service_type', 'emission_type', 'operation_nature', 'cfop', 'sender_cnpj', 'sender_name', 'sender_state_registration_indicator', 'sender_street', 'sender_number', 'sender_district', 'sender_city_code', 'sender_city', 'sender_postal_code', 'sender_uf', 'receiver_cnpj', 'receiver_name', 'receiver_state_registration_indicator', 'receiver_street', 'receiver_number', 'receiver_district', 'receiver_city_code', 'receiver_city', 'receiver_postal_code', 'receiver_uf', 'taker_role', 'origin_uf', 'origin_city_code', 'origin_city', 'destination_uf', 'destination_city_code', 'destination_city', 'pickup', 'rntrc', 'service_value', 'received_value', 'tax_cst', 'tax_base', 'tax_rate', 'tax_value', 'cargo_description', 'cargo_measure_type', 'cargo_unit', 'cargo_quantity', 'cargo_value']
    : ['manifest_emission_type', 'manifest_modal', 'manifest_issuer_type', 'manifest_rntrc', 'manifest_route_ufs', 'manifest_load_municipality_name', 'manifest_load_municipality_code', 'manifest_unload_municipality_name', 'manifest_unload_municipality_code', 'vehicle_plate', 'vehicle_renavam', 'vehicle_registry', 'vehicle_tare', 'vehicle_capacity', 'vehicle_road_type', 'vehicle_trailer_type', 'vehicle_uf', 'driver_name', 'driver_cpf', 'cargo_product_description', 'cargo_product_type', 'cargo_ncm', 'cargo_total_value', 'cargo_unit_manifest', 'cargo_quantity_manifest', 'cte_total', 'nfe_total', 'mdfe_total']
  if (transportRequired.some(field => facts[field] === '' || facts[field] === null || facts[field] === undefined)) throw new Error('Preencha os dados reais obrigatórios deste modelo. O formulário não completa valores ausentes.')
  if (selectedModel.value === '58') {
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
  <UForm :state="facts" class="space-y-5" @submit="submit">
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
          @update:model-value="emit('select-sale', Number($event))"
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
          @update:model-value="emit('select-return', Number($event))"
        />
      </UFormField>
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
          Serviço e transporte
        </legend>
        <UFormField
          v-for="field in [{ key: 'service_type', label: 'Tipo de serviço (0 a 4)' }, { key: 'emission_type', label: 'Tipo de emissão (fluxo local suporta 1)' }, { key: 'operation_nature', label: 'Natureza da operação' }, { key: 'cfop', label: 'CFOP' }, { key: 'rntrc', label: 'RNTRC (8 dígitos)' }, { key: 'service_value', label: 'Valor da prestação' }, { key: 'received_value', label: 'Valor a receber' }, { key: 'tax_cst', label: 'CST (fluxo local suporta 00)' }, { key: 'tax_base', label: 'Base do tributo' }, { key: 'tax_rate', label: 'Alíquota' }, { key: 'tax_value', label: 'Valor do tributo' }, { key: 'cargo_description', label: 'Descrição da carga' }, { key: 'cargo_measure_type', label: 'Tipo de medida da carga' }, { key: 'cargo_unit', label: 'Unidade da carga (00 a 05)' }, { key: 'cargo_quantity', label: 'Quantidade da carga' }, { key: 'cargo_value', label: 'Valor da carga' }]"
          :key="field.key"
          :label="field.label"
          :name="`transport_service.${field.key}`"
          :error="fieldErrors?.[`source.transport_service.${field.key}`]"
        >
          <USelect
            v-if="field.key === 'service_type'"
            :model-value="numericFact(field.key)"
            :items="[0, 1, 2, 3, 4].map(value => ({ label: `${value} · Serviço de transporte ${value}`, value }))"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <USelect
            v-else-if="field.key === 'emission_type'"
            :model-value="numericFact(field.key)"
            :items="[{ label: '1 · Normal', value: 1 }]"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <USelect
            v-else-if="field.key === 'tax_cst'"
            v-model="facts[field.key]"
            :items="[{ label: '00 · Tributação integral', value: '00' }]"
            value-key="value"
            label-key="label"
          />
          <UInput
            v-else
            v-model="facts[field.key]"
            :maxlength="field.key === 'rntrc' ? 8 : undefined"
            :inputmode="field.key === 'rntrc' ? 'numeric' : undefined"
          />
        </UFormField>
        <p class="text-muted text-sm sm:col-span-2">
          O suporte local exige modal rodoviário. Informe participantes e município de origem/destino com os dados do CT-e; a substituição também exige comprovação externa ainda não disponível para transmissão.
        </p>
        <UFormField
          v-for="field in [{ key: 'sender_cnpj', label: 'CNPJ remetente' }, { key: 'sender_name', label: 'Nome remetente' }, { key: 'sender_state_registration', label: 'IE remetente' }, { key: 'sender_state_registration_indicator', label: 'Indicador IE remetente (1, 2, 9)' }, { key: 'sender_street', label: 'Logradouro remetente' }, { key: 'sender_number', label: 'Número remetente' }, { key: 'sender_district', label: 'Bairro remetente' }, { key: 'sender_city_code', label: 'Código IBGE remetente' }, { key: 'sender_city', label: 'Município remetente' }, { key: 'sender_postal_code', label: 'CEP remetente' }, { key: 'sender_uf', label: 'UF remetente' }, { key: 'receiver_cnpj', label: 'CNPJ destinatário' }, { key: 'receiver_name', label: 'Nome destinatário' }, { key: 'receiver_state_registration', label: 'IE destinatário' }, { key: 'receiver_state_registration_indicator', label: 'Indicador IE destinatário (1, 2, 9)' }, { key: 'receiver_street', label: 'Logradouro destinatário' }, { key: 'receiver_number', label: 'Número destinatário' }, { key: 'receiver_district', label: 'Bairro destinatário' }, { key: 'receiver_city_code', label: 'Código IBGE destinatário' }, { key: 'receiver_city', label: 'Município destinatário' }, { key: 'receiver_postal_code', label: 'CEP destinatário' }, { key: 'receiver_uf', label: 'UF destinatário' }, { key: 'taker_role', label: 'Papel do tomador (0 a 3)' }, { key: 'origin_uf', label: 'UF de origem' }, { key: 'origin_city_code', label: 'Código IBGE de origem' }, { key: 'origin_city', label: 'Município de origem' }, { key: 'destination_uf', label: 'UF de destino' }, { key: 'destination_city_code', label: 'Código IBGE de destino' }, { key: 'destination_city', label: 'Município de destino' }, { key: 'pickup', label: 'Retirada (0 não, 1 sim)' }]"
          :key="field.key"
          :label="field.label"
          :name="field.key"
        >
          <USelect
            v-if="field.key === 'sender_state_registration_indicator' || field.key === 'receiver_state_registration_indicator'"
            :model-value="numericFact(field.key)"
            :items="[{ label: '1 · Contribuinte ICMS', value: 1 }, { label: '2 · Contribuinte isento', value: 2 }, { label: '9 · Não contribuinte', value: 9 }]"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <USelect
            v-else-if="field.key === 'taker_role'"
            :model-value="numericFact(field.key)"
            :items="[0, 1, 2, 3].map(value => ({ label: `${value} · Papel do tomador`, value }))"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <USelect
            v-else-if="field.key === 'pickup'"
            :model-value="numericFact(field.key)"
            :items="[{ label: '0 · Não', value: 0 }, { label: '1 · Sim', value: 1 }]"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <UInput v-else v-model="facts[field.key]" />
        </UFormField>
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
          v-for="field in [{ key: 'manifest_emission_type', label: 'Tipo de emissão (fluxo local suporta 1)' }, { key: 'manifest_modal', label: 'Modal (fluxo local suporta 1 rodoviário)' }, { key: 'manifest_issuer_type', label: 'Tipo do emitente (fluxo local suporta veículo próprio 2)' }, { key: 'manifest_rntrc', label: 'RNTRC (8 dígitos)' }, { key: 'manifest_route_ufs', label: 'UFs do percurso, separadas por vírgula' }, { key: 'manifest_load_municipality_name', label: 'Município de carregamento' }, { key: 'manifest_load_municipality_code', label: 'Código IBGE de carregamento' }, { key: 'manifest_unload_municipality_name', label: 'Município de descarregamento' }, { key: 'manifest_unload_municipality_code', label: 'Código IBGE de descarregamento' }, { key: 'vehicle_plate', label: 'Placa' }, { key: 'vehicle_renavam', label: 'RENAVAM' }, { key: 'vehicle_registry', label: 'Registro' }, { key: 'vehicle_tare', label: 'Tara' }, { key: 'vehicle_capacity', label: 'Capacidade em kg' }, { key: 'vehicle_road_type', label: 'Tipo rodoviário' }, { key: 'vehicle_trailer_type', label: 'Tipo de carroceria' }, { key: 'vehicle_uf', label: 'UF do veículo' }, { key: 'driver_name', label: 'Nome do motorista' }, { key: 'driver_cpf', label: 'CPF do motorista' }, { key: 'cargo_product_description', label: 'Descrição do produto predominante' }, { key: 'cargo_product_type', label: 'Tipo de produto' }, { key: 'cargo_ncm', label: 'NCM' }, { key: 'cargo_total_value', label: 'Valor total da carga' }, { key: 'cargo_unit_manifest', label: 'Unidade da carga' }, { key: 'cargo_quantity_manifest', label: 'Quantidade da carga' }, { key: 'cte_total', label: 'Quantidade de CT-e' }, { key: 'nfe_total', label: 'Quantidade de NF-e' }, { key: 'mdfe_total', label: 'Quantidade de MDF-e' }]"
          :key="field.key"
          :label="field.label"
          :name="`transport_manifest.${field.key}`"
          :error="fieldErrors?.[`source.transport_manifest.${field.key}`]"
        >
          <USelect
            v-if="field.key === 'manifest_emission_type'"
            :model-value="facts[field.key] === '' ? undefined : Number(facts[field.key])"
            :items="[{ label: '1 · Normal', value: 1 }]"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <USelect
            v-else-if="field.key === 'manifest_modal'"
            :model-value="facts[field.key] === '' ? undefined : Number(facts[field.key])"
            :items="[{ label: '1 · Rodoviário', value: 1 }]"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <USelect
            v-else-if="field.key === 'manifest_issuer_type'"
            :model-value="facts[field.key] === '' ? undefined : Number(facts[field.key])"
            :items="[{ label: '2 · Transportador de carga própria', value: 2 }]"
            value-key="value"
            label-key="label"
            @update:model-value="facts[field.key] = String($event ?? '')"
          />
          <UInput
            v-else
            v-model="facts[field.key]"
            :maxlength="field.key === 'manifest_rntrc' ? 8 : undefined"
            :inputmode="field.key === 'manifest_rntrc' ? 'numeric' : undefined"
          />
        </UFormField>
        <UFormField label="Documento autorizado disponível" name="transport_manifest.document">
          <USelect
            v-model="selectedManifestDocumentId"
            :items="manifestDocuments.map(row => ({ label: `${row.model} · ${row.access_key}`, value: row.id }))"
            value-key="value"
            label-key="label"
            placeholder="Selecione um documento autorizado"
          />
        </UFormField>
        <UFormField label="Modelo da referência externa (55, 65 ou 57)" name="transport_manifest.external_model">
          <USelect
            v-model="facts.manifest_document_model"
            :items="[{ label: 'NF-e (55)', value: '55' }, { label: 'NFC-e (65)', value: '65' }, { label: 'CT-e (57)', value: '57' }]"
            value-key="value"
            label-key="label"
          />
        </UFormField>
        <UFormField label="Chave de acesso da referência (44 dígitos)" name="transport_manifest.external_access_key">
          <UInput v-model="facts.access_key_reference" maxlength="44" inputmode="numeric" />
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
      v-if="fieldErrors?.source"
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
</template>
