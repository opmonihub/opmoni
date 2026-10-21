<script setup lang="ts">
import type { ErpFiscalConfiguration, ErpFiscalModel } from '#shared/types/erpFiscal'
import type { ErpOperationNatureRecord } from '#shared/types/erpCatalogs'

const props = defineProps<{
  configuration: ErpFiscalConfiguration | null
  canWrite: boolean
  busy?: boolean
  fieldErrors?: Record<string, string>
  operationNatures?: ErpOperationNatureRecord[]
}>()

const emit = defineEmits<{ save: [body: Record<string, unknown>] }>()
const modelIds: ErpFiscalModel[] = ['55', '65', '57', '58']
const form = reactive<Record<string, string | number | undefined>>({})
const transport = reactive<Record<string, string>>({})
const environment = ref('homologation')
const selectedModels = ref(new Set<ErpFiscalModel>())
const error = ref('')
const models = [
  { id: '55', label: 'NF-e (55)' },
  { id: '65', label: 'NFC-e (65)' },
  { id: '57', label: 'CT-e (57)' },
  { id: '58', label: 'MDF-e (58)' }
]
const operationNatureItems = () => (props.operationNatures ?? [])
  .filter(row => row.status === 'active' && (row.erp_establishment_id === null || row.erp_establishment_id === props.configuration?.establishment.id))
  .map(row => ({ label: `${row.cfop} · ${row.description}`, value: row.id }))
function toggleModel(model: ErpFiscalModel, checked: boolean) {
  const next = new Set(selectedModels.value)
  if (checked) next.add(model)
  else next.delete(model)
  selectedModels.value = next
}
const fiscalPrerequisiteLabels: Record<string, string> = {
  issuer: 'dados do emitente', certificate: 'certificado A1', operation_nature: 'natureza de operação ativa',
  nfce_qr: 'parâmetros do QR Code', transport: 'dados tipados de transporte', transport_service: 'dados do CT-e', transport_manifest: 'dados do MDF-e'
}
const prerequisiteSummary = (model: ErpFiscalModel) => {
  const prerequisites = props.configuration?.models[model]?.transport_prerequisites ?? []
  return prerequisites.length ? prerequisites.map(key => fiscalPrerequisiteLabels[key] ?? 'pré-requisito fiscal informado').join(', ') : 'nenhum informado'
}
const blockerSummary = (model: ErpFiscalModel) => {
  const blockers = props.configuration?.models[model]?.readiness.blockers ?? []
  return blockers.length ? 'Esta configuração ainda tem pré-requisitos fiscais pendentes.' : ''
}

watch(() => props.configuration, (configuration) => {
  if (!configuration) return
  selectedModels.value = new Set()
  for (const key of Object.keys(transport)) transport[key] = ''
  environment.value = configuration.environment ?? 'homologation'
  Object.assign(form, {
    tax_regime: configuration.issuer?.tax_regime ?? '',
    state_registration: configuration.issuer?.state_registration ?? '',
    uf: configuration.issuer?.uf ?? '',
    street: configuration.issuer?.address.street ?? '',
    number: configuration.issuer?.address.number ?? '',
    district: configuration.issuer?.address.district ?? '',
    city_code: configuration.issuer?.address.city_code ?? '',
    city_name: configuration.issuer?.address.city_name ?? '',
    zip_code: configuration.issuer?.address.zip_code ?? '',
    complement: configuration.issuer?.address.complement ?? ''
  })
  for (const id of modelIds) {
    const settings = configuration.models[id]
    if (settings?.series !== null && settings?.series !== undefined) selectedModels.value.add(id)
    form[`series_${id}`] = settings?.series ?? ''
    form[`next_number_${id}`] = settings?.next_number ?? ''
    form[`operation_nature_id_${id}`] = settings?.operation_nature_id ?? ''
  }
  form.qr_mode = configuration.models['65']?.nfce_qr?.mode ?? 'online'
  form.qr_url = configuration.models['65']?.nfce_qr?.consultation_url ?? ''
}, { immediate: true })

const cteConfigFields = [
  { key: 'cte_type', label: 'Tipo de CT-e (0 normal, 1 complementar, 3 substituição)' }, { key: 'service_type', label: 'Tipo de serviço (0 a 4)' }, { key: 'emission_type', label: 'Tipo de emissão (fluxo local suporta 1)' }, { key: 'operation_nature', label: 'Natureza da operação' }, { key: 'cfop', label: 'CFOP' }, { key: 'rntrc', label: 'RNTRC' }, { key: 'service_value', label: 'Valor da prestação' }, { key: 'received_value', label: 'Valor a receber' }, { key: 'tax_cst', label: 'CST (fluxo local suporta 00)' }, { key: 'tax_base', label: 'Base do tributo' }, { key: 'tax_rate', label: 'Alíquota' }, { key: 'tax_value', label: 'Valor do tributo' }, { key: 'cargo_description', label: 'Descrição da carga' }, { key: 'cargo_measure_type', label: 'Tipo de medida' }, { key: 'cargo_unit', label: 'Unidade (00 a 05)' }, { key: 'cargo_quantity', label: 'Quantidade' }, { key: 'cargo_value', label: 'Valor da carga' },
  { key: 'sender_cnpj', label: 'CNPJ remetente' }, { key: 'sender_name', label: 'Nome remetente' }, { key: 'sender_state_registration', label: 'IE remetente' }, { key: 'sender_state_registration_indicator', label: 'Indicador IE remetente' }, { key: 'sender_street', label: 'Logradouro remetente' }, { key: 'sender_number', label: 'Número remetente' }, { key: 'sender_district', label: 'Bairro remetente' }, { key: 'sender_city_code', label: 'Código IBGE remetente' }, { key: 'sender_city', label: 'Município remetente' }, { key: 'sender_postal_code', label: 'CEP remetente' }, { key: 'sender_uf', label: 'UF remetente' },
  { key: 'receiver_cnpj', label: 'CNPJ destinatário' }, { key: 'receiver_name', label: 'Nome destinatário' }, { key: 'receiver_state_registration', label: 'IE destinatário' }, { key: 'receiver_state_registration_indicator', label: 'Indicador IE destinatário' }, { key: 'receiver_street', label: 'Logradouro destinatário' }, { key: 'receiver_number', label: 'Número destinatário' }, { key: 'receiver_district', label: 'Bairro destinatário' }, { key: 'receiver_city_code', label: 'Código IBGE destinatário' }, { key: 'receiver_city', label: 'Município destinatário' }, { key: 'receiver_postal_code', label: 'CEP destinatário' }, { key: 'receiver_uf', label: 'UF destinatário' }, { key: 'taker_role', label: 'Papel do tomador (0 a 3)' }, { key: 'origin_uf', label: 'UF de origem' }, { key: 'origin_city_code', label: 'Código IBGE de origem' }, { key: 'origin_city', label: 'Município de origem' }, { key: 'destination_uf', label: 'UF de destino' }, { key: 'destination_city_code', label: 'Código IBGE de destino' }, { key: 'destination_city', label: 'Município de destino' }, { key: 'pickup', label: 'Retirada (0 não, 1 sim)' }
]
const mdfeConfigFields = [
  { key: 'manifest_emission_type', label: 'Tipo de emissão (fluxo local suporta 1)' }, { key: 'manifest_issuer_type', label: 'Tipo do emitente (fluxo local suporta veículo próprio 2)' }, { key: 'manifest_rntrc', label: 'RNTRC' }, { key: 'manifest_route_ufs', label: 'UFs do percurso, separadas por vírgula' }, { key: 'load_municipality', label: 'Município de carregamento' }, { key: 'load_municipality_code', label: 'Código IBGE de carregamento' }, { key: 'unload_municipality', label: 'Município de descarregamento' }, { key: 'unload_municipality_code', label: 'Código IBGE de descarregamento' }, { key: 'vehicle_plate', label: 'Placa' }, { key: 'vehicle_renavam', label: 'RENAVAM' }, { key: 'vehicle_registry', label: 'Registro do veículo' }, { key: 'vehicle_tare', label: 'Tara' }, { key: 'vehicle_capacity', label: 'Capacidade em kg' }, { key: 'vehicle_road_type', label: 'Tipo rodoviário' }, { key: 'vehicle_trailer_type', label: 'Tipo de carroceria' }, { key: 'vehicle_uf', label: 'UF do veículo' }, { key: 'driver_name', label: 'Nome do motorista' }, { key: 'driver_cpf', label: 'CPF do motorista' }, { key: 'manifest_document_model', label: 'Modelo vinculado (55, 65 ou 57)' }, { key: 'manifest_access_key', label: 'Chave de acesso vinculada' }, { key: 'cargo_product_description', label: 'Descrição do produto predominante' }, { key: 'cargo_product_type', label: 'Tipo de produto' }, { key: 'cargo_ncm', label: 'NCM' }, { key: 'cargo_total_value', label: 'Valor total da carga' }, { key: 'cargo_unit_manifest', label: 'Unidade da carga' }, { key: 'cargo_quantity_manifest', label: 'Quantidade da carga' }
]

function buildTransportConfig(model: '57' | '58') {
  const keys = model === '57' ? cteConfigFields.map(field => field.key) : mdfeConfigFields.map(field => field.key)
  const values = keys.map(key => transport[key])
  const hasValues = values.some(value => value !== '' && value !== null && value !== undefined)
  const hasExisting = props.configuration?.environment === environment.value
    && (props.configuration?.models[model]?.transport_prerequisites?.length ?? 0) > 0
  if (!hasValues && hasExisting) return undefined
  if (!hasValues && !selectedModels.value.has(model)) return undefined
  if (values.some(value => value === '' || value === null || value === undefined)) throw new Error(`Preencha todos os dados tipados de transporte do modelo ${model} ou mantenha o modelo fora desta revisão.`)
  const rntrc = model === '57' ? transport.rntrc : transport.manifest_rntrc
  if (!/^\d{8}$/.test(rntrc ?? '')) throw new Error('Informe o RNTRC com exatamente 8 dígitos.')
  if (model === '58') {
    const routeUfs = (transport.manifest_route_ufs ?? '').split(',').map(value => value.trim().toUpperCase()).filter(Boolean)
    if (routeUfs.length < 2 || new Set(routeUfs).size !== routeUfs.length || routeUfs.some(uf => !/^[A-Z]{2}$/.test(uf))) {
      throw new Error('Informe ao menos duas UFs válidas e distintas no percurso do MDF-e.')
    }
  }
  if (model === '57') {
    const party = (prefix: string) => ({ cnpj: transport[`${prefix}_cnpj`], legal_name: transport[`${prefix}_name`], state_registration: transport[`${prefix}_state_registration`], state_registration_indicator: Number(transport[`${prefix}_state_registration_indicator`]), address: { street: transport[`${prefix}_street`], number: transport[`${prefix}_number`], district: transport[`${prefix}_district`], city_code: transport[`${prefix}_city_code`], city: transport[`${prefix}_city`], postal_code: transport[`${prefix}_postal_code`], uf: transport[`${prefix}_uf`] } })
    return { cte_type: Number(transport.cte_type), service_type: Number(transport.service_type), emission_type: Number(transport.emission_type), modal: '01', operation_nature: transport.operation_nature, cfop: transport.cfop, rntrc: transport.rntrc, service_value: transport.service_value, received_value: transport.received_value, sender: party('sender'), receiver: party('receiver'), taker: { role: Number(transport.taker_role) }, route: { origin_uf: transport.origin_uf, origin_city_code: transport.origin_city_code, origin_city: transport.origin_city, destination_uf: transport.destination_uf, destination_city_code: transport.destination_city_code, destination_city: transport.destination_city, pickup: Number(transport.pickup) }, tax: { cst: transport.tax_cst, base: transport.tax_base, rate: transport.tax_rate, value: transport.tax_value }, cargo: { description: transport.cargo_description, measure_type: transport.cargo_measure_type, unit: transport.cargo_unit, quantity: transport.cargo_quantity, value: transport.cargo_value } }
  }
  return { emission_type: Number(transport.manifest_emission_type), modal: '1', issuer_type: Number(transport.manifest_issuer_type), rntrc: transport.manifest_rntrc, route_ufs: (transport.manifest_route_ufs ?? '').split(',').map(value => value.trim()).filter(Boolean), municipalities: [{ name: transport.load_municipality, code: transport.load_municipality_code }, { name: transport.unload_municipality, code: transport.unload_municipality_code }], vehicle: { plate: transport.vehicle_plate, renavam: transport.vehicle_renavam, registry: transport.vehicle_registry, tare: transport.vehicle_tare, capacity_kg: transport.vehicle_capacity, road_type: transport.vehicle_road_type, trailer_type: transport.vehicle_trailer_type, uf: transport.vehicle_uf }, driver: { name: transport.driver_name, cpf: transport.driver_cpf }, documents: [{ model: transport.manifest_document_model, access_key: transport.manifest_access_key, municipality_code: transport.unload_municipality_code }], cargo: { product_description: transport.cargo_product_description, product_type: transport.cargo_product_type, ncm: transport.cargo_ncm, total_value: transport.cargo_total_value, unit: transport.cargo_unit_manifest, quantity: transport.cargo_quantity_manifest } }
}

function submit() {
  error.value = ''
  if (!props.configuration?.establishment?.id) {
    error.value = 'Selecione um estabelecimento antes de configurar o emissor.'
    return
  }
  const required = ['tax_regime', 'state_registration', 'uf', 'street', 'number', 'district', 'city_code', 'city_name', 'zip_code']
  if (required.some(key => form[key] === '' || form[key] === null || form[key] === undefined)) {
    error.value = 'Preencha todos os dados fiscais obrigatórios do emissor antes de salvar.'
    return
  }
  if (modelIds.some(id => selectedModels.value.has(id) && [form[`series_${id}`], form[`next_number_${id}`]].some(value => value === '' || value === null || value === undefined))) {
    error.value = 'Informe série e próximo número para cada modelo incluído na revisão.'
    return
  }
  if (selectedModels.value.has('65') && !form.qr_url) {
    error.value = 'Informe a URL pública de consulta do QR Code para incluir a NFC-e (65).'
    return
  }
  if ((['55', '65'] as ErpFiscalModel[]).some(id => selectedModels.value.has(id) && !operationNatureItems().some(row => row.value === Number(form[`operation_nature_id_${id}`] ?? props.configuration?.models[id]?.operation_nature_id)))) {
    error.value = 'Escolha uma natureza de operação ativa e vinculada ao estabelecimento para cada modelo NF-e/NFC-e incluído.'
    return
  }
  try {
    const body: Record<string, unknown> = {
      environment: environment.value,
      issuer: {
        tax_regime: Number(form.tax_regime),
        state_registration: String(form.state_registration),
        uf: String(form.uf),
        address: {
          street: String(form.street), number: String(form.number), district: String(form.district),
          city_code: String(form.city_code), city_name: String(form.city_name), zip_code: String(form.zip_code),
          ...(form.complement ? { complement: String(form.complement) } : {})
        }
      },
      models: Object.fromEntries(modelIds.filter(id => selectedModels.value.has(id)).map((id) => {
        const settings = props.configuration?.models[id]
        const modelConfig: Record<string, unknown> = {
          series: Number(form[`series_${id}`]),
          next_number: Number(form[`next_number_${id}`]),
          ...(settings?.operation_nature_id || form[`operation_nature_id_${id}`]
            ? { operation_nature_id: Number(form[`operation_nature_id_${id}`]) }
            : {})
        }
        if (id === '57' || id === '58') {
          const typedTransport = buildTransportConfig(id)
          if (typedTransport) modelConfig.transport = typedTransport
        }
        return [id, modelConfig]
      }))
    }
    if (selectedModels.value.has('65')) {
      const model65 = (body.models as Record<string, Record<string, unknown>>)['65']
      if (model65) model65.nfce_qr = { version: 3, mode: form.qr_mode, consultation_url: String(form.qr_url) }
    }
    emit('save', body)
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Revise os dados fiscais informados.'
  }
}
</script>

<template>
  <UForm :state="form" class="space-y-5" @submit="submit">
    <div v-if="configuration" class="rounded-lg border border-default bg-elevated p-4 text-sm">
      <p><strong>Estabelecimento:</strong> {{ configuration.establishment.name }} · CNPJ {{ configuration.establishment.cnpj ?? 'não informado' }}</p>
      <p class="mt-1 text-muted">
        Revisão {{ configuration.revision ?? 'ainda não criada' }}. O CNPJ vem do cadastro do estabelecimento e não pode ser alterado nesta tela.
      </p>
    </div>
    <UAlert
      v-else
      color="warning"
      variant="subtle"
      title="Configuração indisponível"
      description="Selecione um estabelecimento fiscal para carregar os dados do emissor."
    />
    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField label="Ambiente" name="environment" :error="fieldErrors?.environment">
        <USelect
          v-model="environment"
          :items="[{ label: 'Homologação', value: 'homologation' }, { label: 'Produção', value: 'production' }]"
          value-key="value"
          label-key="label"
          :disabled="!canWrite || busy"
        />
      </UFormField>
      <UFormField label="Regime tributário" name="issuer.tax_regime" :error="fieldErrors?.['issuer.tax_regime']">
        <USelect
          :model-value="Number(form.tax_regime) || undefined"
          :items="[{ label: 'Simples Nacional', value: 1 }, { label: 'Simples Nacional – excesso de sublimite', value: 2 }, { label: 'Regime normal', value: 3 }, { label: 'MEI', value: 4 }]"
          value-key="value"
          label-key="label"
          :disabled="!canWrite || busy"
          @update:model-value="form.tax_regime = Number($event)"
        />
      </UFormField>
      <UFormField label="Inscrição estadual" name="issuer.state_registration" :error="fieldErrors?.['issuer.state_registration']">
        <UInput v-model="form.state_registration" :disabled="!canWrite || busy" />
      </UFormField>
      <UFormField label="UF do emissor" name="issuer.uf" :error="fieldErrors?.['issuer.uf']">
        <UInput v-model="form.uf" maxlength="2" :disabled="!canWrite || busy" />
      </UFormField>
    </div>
    <fieldset class="grid gap-4 rounded-lg border border-default p-4 sm:grid-cols-2">
      <legend class="px-1 text-sm font-medium">
        Endereço fiscal do emissor
      </legend>
      <UFormField
        v-for="field in [{ key: 'street', label: 'Logradouro' }, { key: 'number', label: 'Número' }, { key: 'district', label: 'Bairro' }, { key: 'city_code', label: 'Código IBGE do município' }, { key: 'city_name', label: 'Município' }, { key: 'zip_code', label: 'CEP' }, { key: 'complement', label: 'Complemento (opcional)' }]"
        :key="field.key"
        :label="field.label"
        :name="`issuer.address.${field.key}`"
        :error="fieldErrors?.[`issuer.address.${field.key}`]"
      >
        <UInput v-model="form[field.key]" :disabled="!canWrite || busy" />
      </UFormField>
    </fieldset>
    <div class="grid gap-4 xl:grid-cols-2">
      <section v-for="model in models" :key="model.id" class="space-y-3 rounded-lg border border-default p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="font-semibold">
              {{ model.label }}
            </h3><p class="text-xs text-muted">
              {{ configuration?.models[model.id as ErpFiscalModel]?.readiness?.status === 'locally_configured' ? 'Configuração local presente' : 'Configuração incompleta' }}
            </p>
          </div><div class="flex items-center gap-2">
            <UBadge :color="configuration?.models[model.id as ErpFiscalModel]?.readiness?.status === 'locally_configured' ? 'success' : 'warning'" variant="subtle">
              {{ configuration?.models[model.id as ErpFiscalModel]?.readiness?.status === 'locally_configured' ? 'Pronto localmente' : 'Bloqueado' }}
            </UBadge><UCheckbox
              :model-value="selectedModels.has(model.id as ErpFiscalModel)"
              :disabled="!canWrite || busy"
              :label="selectedModels.has(model.id as ErpFiscalModel) ? 'Incluir na revisão' : 'Configurar modelo'"
              @update:model-value="toggleModel(model.id as ErpFiscalModel, $event === true)"
            />
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <UFormField label="Série" :name="`models.${model.id}.series`" :error="fieldErrors?.[`models.${model.id}.series`]">
            <UInput v-model="form[`series_${model.id}`]" inputmode="numeric" :disabled="!canWrite || busy" />
          </UFormField>
          <UFormField label="Próximo número" :name="`models.${model.id}.next_number`" :error="fieldErrors?.[`models.${model.id}.next_number`]">
            <UInput v-model="form[`next_number_${model.id}`]" inputmode="numeric" :disabled="!canWrite || busy" />
          </UFormField>
          <UFormField
            v-if="['55', '65'].includes(model.id)"
            label="Natureza de operação ativa"
            :name="`models.${model.id}.operation_nature_id`"
            :error="fieldErrors?.[`models.${model.id}.operation_nature_id`]"
          >
            <USelect
              :model-value="Number(form[`operation_nature_id_${model.id}`]) || undefined"
              :items="operationNatureItems()"
              value-key="value"
              label-key="label"
              placeholder="Selecione a natureza"
              :disabled="!canWrite || busy || !operationNatureItems().length"
              @update:model-value="form[`operation_nature_id_${model.id}`] = Number($event)"
            />
          </UFormField>
        </div>
        <template v-if="model.id === '57'">
          <p class="text-sm text-muted">
            Dados tipados do CT-e ficam privados e não são reidratados pela API. Reinforme o conjunto completo para alterar essa configuração. O suporte local cobre CT-e rodoviário, tributação CST 00 e os tipos aceitos pelo servidor.
          </p>
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField
              v-for="field in cteConfigFields"
              :key="field.key"
              :label="field.label"
              :name="`models.57.transport.${field.key}`"
              :error="fieldErrors?.[`models.57.transport.${field.key}`]"
            >
              <USelect
                v-if="field.key === 'tax_cst'"
                v-model="transport[field.key]"
                :items="[{ label: '00 · Tributação integral', value: '00' }]"
                value-key="value"
                label-key="label"
                :disabled="!canWrite || busy"
              />
              <UInput
                v-else
                v-model="transport[field.key]"
                :maxlength="field.key === 'rntrc' ? 8 : undefined"
                :inputmode="field.key === 'rntrc' ? 'numeric' : undefined"
                :disabled="!canWrite || busy"
              />
            </UFormField>
          </div>
        </template>
        <template v-if="model.id === '58'">
          <p class="text-sm text-muted">
            O MDF-e local suporta modal rodoviário com veículo próprio e emitente transportador de carga própria. RNTRC deve conter 8 dígitos. Esses dados privados não são reidratados; informe novamente o conjunto completo para substituir a configuração.
          </p>
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField
              v-for="field in mdfeConfigFields"
              :key="field.key"
              :label="field.label"
              :name="`models.58.transport.${field.key}`"
              :error="fieldErrors?.[`models.58.transport.${field.key}`]"
            >
              <USelect
                v-if="field.key === 'manifest_issuer_type'"
                :model-value="transport[field.key] === '' ? undefined : Number(transport[field.key])"
                :items="[{ label: '2 · Transportador de carga própria', value: 2 }]"
                value-key="value"
                label-key="label"
                :disabled="!canWrite || busy"
                @update:model-value="transport[field.key] = String($event ?? '')"
              />
              <UInput
                v-else
                v-model="transport[field.key]"
                :maxlength="field.key === 'manifest_rntrc' ? 8 : undefined"
                :inputmode="field.key === 'manifest_rntrc' ? 'numeric' : undefined"
                :disabled="!canWrite || busy"
              />
            </UFormField>
          </div>
        </template>
        <p class="text-xs text-muted">
          Próximo número retornado pela API: {{ configuration?.models[model.id as ErpFiscalModel]?.next_number ?? 'indisponível' }} · Pré-requisitos: {{ prerequisiteSummary(model.id as ErpFiscalModel) }}
        </p>
        <p v-if="blockerSummary(model.id as ErpFiscalModel)" class="text-xs text-warning">
          {{ blockerSummary(model.id as ErpFiscalModel) }}
        </p>
      </section>
    </div>
    <section class="space-y-3 rounded-lg border border-default p-4">
      <h3 class="font-semibold">
        NFC-e · QR Code
      </h3>
      <p class="text-sm text-muted">
        A URL de consulta e o modo são publicados na configuração segura. O QR Code não habilita transmissão quando o provedor está indisponível.
      </p>
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField label="Modo" name="models.65.nfce_qr.mode" :error="fieldErrors?.['models.65.nfce_qr.mode']">
          <USelect
            :model-value="String(form.qr_mode ?? 'online')"
            :items="[{ label: 'Online', value: 'online' }, { label: 'Offline', value: 'offline' }]"
            value-key="value"
            label-key="label"
            :disabled="!canWrite || busy"
            @update:model-value="form.qr_mode = String($event)"
          />
        </UFormField>
        <UFormField label="URL pública de consulta" name="models.65.nfce_qr.consultation_url" :error="fieldErrors?.['models.65.nfce_qr.consultation_url']">
          <UInput v-model="form.qr_url" type="url" :disabled="!canWrite || busy" />
        </UFormField>
      </div>
    </section>
    <UAlert
      v-if="configuration"
      color="warning"
      variant="subtle"
      title="Portões de transmissão"
      :description="`Provedor: ${configuration.release_gates.provider}. Produção: ${configuration.release_gates.production_transmission_enabled ? 'habilitada' : 'bloqueada'}${configuration.release_gates.production_blocker ? ` · ${configuration.release_gates.production_blocker}` : ''}`"
    />
    <UAlert
      v-if="fieldErrors?.issuer"
      color="error"
      variant="subtle"
      :description="fieldErrors.issuer"
    />
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :description="error"
    />
    <div class="flex justify-end">
      <UButton type="submit" :disabled="!canWrite || busy || !configuration || selectedModels.size === 0" :loading="busy">
        Salvar configuração fiscal
      </UButton>
    </div>
  </UForm>
</template>
