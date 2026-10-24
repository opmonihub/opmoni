<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpBarcodeType, ErpCatalogEditorMode, ErpProductRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

type FormVariant = {
  id?: number
  name: string
  sku: string
  sale_price: string
  barcode: string
  barcode_type: ErpBarcodeType | null
  status?: 'archived'
  archiveRequested: boolean
}

const props = defineProps<{
  formId?: string
  record: ErpProductRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  canArchive?: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ 'save': [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const barcodeTypeItems: Array<{ label: string, value: ErpBarcodeType }> = [
  { label: 'Code 128', value: 'C128' },
  { label: 'Code 39', value: 'C39' },
  { label: 'EAN-13', value: 'EAN13' },
  { label: 'EAN-8', value: 'EAN8' },
  { label: 'UPC-A', value: 'UPCA' },
  { label: 'UPC-E', value: 'UPCE' }
]
const variantBarcodeTypeItems: Array<{ label: string, value: ErpBarcodeType | null }> = [
  { label: 'Herdar formato do produto', value: null },
  ...barcodeTypeItems
]
const productSectionItems = [
  { label: 'Detalhes comerciais', value: 'commercial', slot: 'commercial' },
  { label: 'Fiscal', value: 'fiscal', slot: 'fiscal' },
  { label: 'Variantes', value: 'variants', slot: 'variants' }
]
const newVariant = (): FormVariant => ({ name: 'Padrão', sku: '', sale_price: '', barcode: '', barcode_type: null, archiveRequested: false })
const form = reactive({
  name: '', sku: '', kind: 'goods' as 'goods' | 'service', track_stock: true,
  erp_unit_id: null as number | null, erp_brand_id: null as number | null, erp_category_id: null as number | null,
  sale_price: '0.0000', warranty_months: '', warranty_description: '', barcode: '', barcode_type: 'C128' as ErpBarcodeType,
  ncm: '', cest: '', origin_code: '', icms_cst: '', icms_csosn: '', icms_rate: '', simple_national_credit_rate: '',
  pis_cst: '', pis_rate: '', cofins_cst: '', cofins_rate: '', ipi_cst: '', ipi_rate: '',
  ibs_cbs_cst: '', ibs_cbs_classification_code: '', variants: [] as FormVariant[]
})
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', dirty => emit('dirty-change', dirty))
const openProductSections = ref<string[]>([])
const pendingVariantArchive = ref<number | null>(null)
const variantArchiveOpen = computed({
  get: () => pendingVariantArchive.value !== null,
  set: (open: boolean) => {
    if (!open && !props.busy)
      pendingVariantArchive.value = null
  }
})
const variantArchiveDescription = computed(() => {
  const variant = pendingVariantArchive.value === null ? null : form.variants[pendingVariantArchive.value]
  return variant
    ? `Arquivar a variante ${variant.name}? O arquivamento será aplicado ao salvar o produto. O histórico será mantido.`
    : 'O arquivamento será aplicado ao salvar o produto. O histórico será mantido.'
})

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.sku = record?.sku ?? ''
  form.kind = record?.kind ?? 'goods'
  form.track_stock = record?.track_stock ?? true
  form.erp_unit_id = record?.erp_unit_id ?? null
  form.erp_brand_id = record?.erp_brand_id ?? null
  form.erp_category_id = record?.erp_category_id ?? null
  form.sale_price = record?.sale_price ?? '0.0000'
  form.warranty_months = record?.warranty_months === null || record?.warranty_months === undefined ? '' : String(record.warranty_months)
  form.warranty_description = record?.warranty_description ?? ''
  form.barcode = record?.barcode ?? ''
  form.barcode_type = record?.barcode_type ?? 'C128'
  form.ncm = record?.ncm ?? ''
  form.cest = record?.cest ?? ''
  form.origin_code = record?.origin_code ?? ''
  form.icms_cst = record?.icms_cst ?? ''
  form.icms_csosn = record?.icms_csosn ?? ''
  form.icms_rate = record?.icms_rate ?? ''
  form.simple_national_credit_rate = record?.simple_national_credit_rate ?? ''
  form.pis_cst = record?.pis_cst ?? ''
  form.pis_rate = record?.pis_rate ?? ''
  form.cofins_cst = record?.cofins_cst ?? ''
  form.cofins_rate = record?.cofins_rate ?? ''
  form.ipi_cst = record?.ipi_cst ?? ''
  form.ipi_rate = record?.ipi_rate ?? ''
  form.ibs_cbs_cst = record?.ibs_cbs_cst ?? ''
  form.ibs_cbs_classification_code = record?.ibs_cbs_classification_code ?? ''
  form.variants = record?.variants.length
    ? record.variants.map(variant => ({
        id: variant.id,
        name: variant.name,
        sku: variant.sku ?? '',
        sale_price: variant.sale_price ?? '',
        barcode: variant.barcode ?? '',
        barcode_type: variant.barcode_type ?? null,
        ...(variant.status === 'archived' ? { status: 'archived' as const } : {}),
        archiveRequested: variant.status === 'archived'
      }))
    : [newVariant()]
  pendingVariantArchive.value = null
  void nextTick(markClean)
}, { immediate: true })

watch(() => form.kind, (kind) => {
  if (kind === 'service')
    form.track_stock = false
})

watch(() => props.fieldErrors, (errors) => {
  const sectionsWithErrors = new Set<string>()
  for (const [field, messages] of Object.entries(errors ?? {})) {
    if (!messages.length)
      continue
    if (['erp_brand_id', 'erp_category_id', 'track_stock', 'warranty_months', 'warranty_description', 'barcode', 'barcode_type'].includes(field))
      sectionsWithErrors.add('commercial')
    else if (['ncm', 'cest', 'origin_code', 'icms_cst', 'icms_csosn', 'icms_rate', 'simple_national_credit_rate', 'pis_cst', 'pis_rate', 'cofins_cst', 'cofins_rate', 'ipi_cst', 'ipi_rate', 'ibs_cbs_cst', 'ibs_cbs_classification_code'].includes(field))
      sectionsWithErrors.add('fiscal')
    else if (field === 'variants' || field.startsWith('variants.'))
      sectionsWithErrors.add('variants')
  }
  openProductSections.value = [...new Set([...openProductSections.value, ...sectionsWithErrors])]
}, { deep: true })

function addVariant() {
  if (!disabled.value)
    form.variants.push(newVariant())
}

function openVariantArchive(index: number) {
  const variant = form.variants[index]
  if (disabled.value || !props.canArchive || !variant?.id || variant.status === 'archived')
    return
  pendingVariantArchive.value = index
}

function confirmVariantArchive() {
  const index = pendingVariantArchive.value
  if (disabled.value || index === null || !props.canArchive || !form.variants[index])
    return
  form.variants[index]!.archiveRequested = true
  form.variants[index]!.status = 'archived'
  pendingVariantArchive.value = null
}

function variantIdError(index: number): string | undefined {
  return props.fieldErrors?.[`variants.${index}.id`]?.[0]
}

function nullableFiscalValue(value: string): string | null {
  return value === '' ? null : value
}

function submit() {
  if (disabled.value)
    return
  const variants = form.variants.map((variant) => {
    const row: Record<string, unknown> = {
      ...(variant.id ? { id: variant.id } : {}),
      name: variant.name,
      sku: variant.sku || null,
      sale_price: variant.sale_price || null,
      barcode: variant.barcode || null,
      barcode_type: variant.barcode_type
    }
    if (variant.archiveRequested && props.canArchive)
      row.status = 'archived'
    return row
  })
  emit('save', {
    name: form.name,
    sku: form.sku || null,
    kind: form.kind,
    track_stock: form.kind === 'service' ? false : form.track_stock,
    erp_unit_id: form.erp_unit_id,
    erp_brand_id: form.erp_brand_id,
    erp_category_id: form.erp_category_id,
    sale_price: form.sale_price,
    warranty_months: form.warranty_months === '' ? null : Number(form.warranty_months),
    warranty_description: form.warranty_description || null,
    barcode: form.barcode || null,
    barcode_type: form.barcode_type,
    ncm: nullableFiscalValue(form.ncm),
    cest: nullableFiscalValue(form.cest),
    origin_code: nullableFiscalValue(form.origin_code),
    icms_cst: nullableFiscalValue(form.icms_cst),
    icms_csosn: nullableFiscalValue(form.icms_csosn),
    icms_rate: nullableFiscalValue(form.icms_rate),
    simple_national_credit_rate: nullableFiscalValue(form.simple_national_credit_rate),
    pis_cst: nullableFiscalValue(form.pis_cst),
    pis_rate: nullableFiscalValue(form.pis_rate),
    cofins_cst: nullableFiscalValue(form.cofins_cst),
    cofins_rate: nullableFiscalValue(form.cofins_rate),
    ipi_cst: nullableFiscalValue(form.ipi_cst),
    ipi_rate: nullableFiscalValue(form.ipi_rate),
    ibs_cbs_cst: nullableFiscalValue(form.ibs_cbs_cst),
    ibs_cbs_classification_code: nullableFiscalValue(form.ibs_cbs_classification_code),
    variants
  })
}
</script>

<template>
  <UForm
    :id="formId"
    :state="form"
    :disabled="disabled"
    class="space-y-5"
    @submit="submit"
  >
    <section aria-labelledby="product-basic-title" class="space-y-4">
      <div>
        <h3 id="product-basic-title" class="text-highlighted font-medium">
          Dados básicos
        </h3>
        <p class="text-muted text-sm">
          Informe o nome, o tipo e a unidade do produto. Os demais dados podem ser preenchidos depois.
        </p>
      </div>
      <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2">
        <legend class="text-highlighted font-medium sm:col-span-2">
          Identificação
        </legend>
        <ErpCatalogField
          label="Nome"
          required
          name="name"
          :error="fieldErrors?.name?.[0]"
        >
          <UInput
            v-model="form.name"
            name="name"
            required
            maxlength="255"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="SKU" name="sku" :error="fieldErrors?.sku?.[0]">
          <UInput
            v-model="form.sku"
            name="sku"
            maxlength="64"
            autocomplete="off"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField
          label="Tipo"
          required
          name="kind"
          :error="fieldErrors?.kind?.[0]"
        >
          <USelect
            v-model="form.kind"
            name="kind"
            data-erp-field-name="kind"
            :items="[{ label: 'Mercadoria', value: 'goods' }, { label: 'Serviço', value: 'service' }]"
            required
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogReferenceSelect
          v-model="form.erp_unit_id"
          :path="erpCatalogRoutes.units"
          label="Unidade"
          label-key="short_name"
          name="erp_unit_id"
          :disabled="disabled"
          :error="fieldErrors?.erp_unit_id?.[0]"
        />
        <ErpCatalogField label="Preço de venda" name="sale_price" :error="fieldErrors?.sale_price?.[0]">
          <UInput
            v-model="form.sale_price"
            name="sale_price"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            class="w-full"
          />
        </ErpCatalogField>
      </fieldset>
    </section>

    <UAccordion
      v-model="openProductSections"
      :items="productSectionItems"
      type="multiple"
      :unmount-on-hide="false"
      class="space-y-2"
    >
      <template #commercial>
        <section aria-labelledby="product-commercial-title" class="space-y-4">
          <div>
            <h3 id="product-commercial-title" class="text-highlighted font-medium">
              Detalhes comerciais
            </h3>
          </div>
          <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend class="text-highlighted font-medium sm:col-span-2">
              Vínculos e estoque
            </legend>
            <ErpCatalogReferenceSelect
              v-model="form.erp_brand_id"
              :path="erpCatalogRoutes.brands"
              label="Marca"
              label-key="name"
              name="erp_brand_id"
              optional
              :disabled="disabled"
              :error="fieldErrors?.erp_brand_id?.[0]"
            />
            <ErpCatalogReferenceSelect
              v-model="form.erp_category_id"
              :path="erpCatalogRoutes.categories"
              label="Categoria"
              label-key="name"
              name="erp_category_id"
              optional
              :disabled="disabled"
              :error="fieldErrors?.erp_category_id?.[0]"
            />
            <UFormField name="track_stock" :error="fieldErrors?.track_stock?.[0]" class="sm:col-span-2">
              <UCheckbox
                v-model="form.track_stock"
                name="track_stock"
                label="Controla estoque"
                :disabled="disabled || form.kind === 'service'"
              />
            </UFormField>
          </fieldset>

          <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend class="text-highlighted font-medium sm:col-span-2">
              Garantia e identificação adicional
            </legend>
            <ErpCatalogField label="Garantia (meses)" name="warranty_months" :error="fieldErrors?.warranty_months?.[0]">
              <UInput
                v-model="form.warranty_months"
                name="warranty_months"
                type="number"
                min="0"
                max="600"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Descrição da garantia" name="warranty_description" :error="fieldErrors?.warranty_description?.[0]">
              <UInput
                v-model="form.warranty_description"
                name="warranty_description"
                maxlength="255"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Código de barras" name="barcode" :error="fieldErrors?.barcode?.[0]">
              <UInput
                v-model="form.barcode"
                name="barcode"
                maxlength="64"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Simbologia do código de barras" name="barcode_type" :error="fieldErrors?.barcode_type?.[0]">
              <USelect
                v-model="form.barcode_type"
                name="barcode_type"
                data-erp-field-name="barcode_type"
                :items="barcodeTypeItems"
                value-key="value"
                :disabled="disabled"
                class="w-full"
              />
            </ErpCatalogField>
          </fieldset>
        </section>
      </template>

      <template #fiscal>
        <section aria-labelledby="product-fiscal-title" class="space-y-4">
          <div>
            <h3 id="product-fiscal-title" class="text-highlighted font-medium">
              Dados fiscais
            </h3>
            <p class="text-muted text-sm">
              O cadastro pode ficar incompleto. Informe os códigos e percentuais já definidos para este produto.
            </p>
          </div>

          <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <legend class="text-highlighted font-medium sm:col-span-2 xl:col-span-3">
              Classificação
            </legend>
            <ErpCatalogField label="NCM" name="ncm" :error="fieldErrors?.ncm?.[0]">
              <UInput
                v-model="form.ncm"
                name="ncm"
                type="text"
                inputmode="numeric"
                maxlength="8"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="CEST" name="cest" :error="fieldErrors?.cest?.[0]">
              <UInput
                v-model="form.cest"
                name="cest"
                type="text"
                inputmode="numeric"
                maxlength="7"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Origem" name="origin_code" :error="fieldErrors?.origin_code?.[0]">
              <UInput
                v-model="form.origin_code"
                name="origin_code"
                type="text"
                inputmode="numeric"
                maxlength="1"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
          </fieldset>

          <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <legend class="text-highlighted font-medium sm:col-span-2 xl:col-span-3">
              ICMS
            </legend>
            <ErpCatalogField label="CST do ICMS" name="icms_cst" :error="fieldErrors?.icms_cst?.[0]">
              <UInput
                v-model="form.icms_cst"
                name="icms_cst"
                type="text"
                inputmode="numeric"
                maxlength="2"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="CSOSN" name="icms_csosn" :error="fieldErrors?.icms_csosn?.[0]">
              <UInput
                v-model="form.icms_csosn"
                name="icms_csosn"
                type="text"
                inputmode="numeric"
                maxlength="3"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Alíquota de ICMS (%)" name="icms_rate" :error="fieldErrors?.icms_rate?.[0]">
              <UInput
                v-model="form.icms_rate"
                name="icms_rate"
                type="text"
                inputmode="decimal"
                maxlength="9"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Crédito do Simples Nacional (%)" name="simple_national_credit_rate" :error="fieldErrors?.simple_national_credit_rate?.[0]">
              <UInput
                v-model="form.simple_national_credit_rate"
                name="simple_national_credit_rate"
                type="text"
                inputmode="decimal"
                maxlength="9"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
          </fieldset>

          <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend class="text-highlighted font-medium sm:col-span-2">
              PIS, COFINS e IPI
            </legend>
            <ErpCatalogField label="CST do PIS" name="pis_cst" :error="fieldErrors?.pis_cst?.[0]">
              <UInput
                v-model="form.pis_cst"
                name="pis_cst"
                type="text"
                inputmode="numeric"
                maxlength="2"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Alíquota do PIS (%)" name="pis_rate" :error="fieldErrors?.pis_rate?.[0]">
              <UInput
                v-model="form.pis_rate"
                name="pis_rate"
                type="text"
                inputmode="decimal"
                maxlength="9"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="CST da COFINS" name="cofins_cst" :error="fieldErrors?.cofins_cst?.[0]">
              <UInput
                v-model="form.cofins_cst"
                name="cofins_cst"
                type="text"
                inputmode="numeric"
                maxlength="2"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Alíquota da COFINS (%)" name="cofins_rate" :error="fieldErrors?.cofins_rate?.[0]">
              <UInput
                v-model="form.cofins_rate"
                name="cofins_rate"
                type="text"
                inputmode="decimal"
                maxlength="9"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="CST do IPI" name="ipi_cst" :error="fieldErrors?.ipi_cst?.[0]">
              <UInput
                v-model="form.ipi_cst"
                name="ipi_cst"
                type="text"
                inputmode="numeric"
                maxlength="2"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Alíquota do IPI (%)" name="ipi_rate" :error="fieldErrors?.ipi_rate?.[0]">
              <UInput
                v-model="form.ipi_rate"
                name="ipi_rate"
                type="text"
                inputmode="decimal"
                maxlength="9"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
          </fieldset>

          <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <legend class="text-highlighted font-medium sm:col-span-2 xl:col-span-3">
              Reforma tributária (IBS/CBS)
            </legend>
            <ErpCatalogField label="CST IBS/CBS" name="ibs_cbs_cst" :error="fieldErrors?.ibs_cbs_cst?.[0]">
              <UInput
                v-model="form.ibs_cbs_cst"
                name="ibs_cbs_cst"
                type="text"
                inputmode="numeric"
                maxlength="3"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Classificação IBS/CBS" name="ibs_cbs_classification_code" :error="fieldErrors?.ibs_cbs_classification_code?.[0]">
              <UInput
                v-model="form.ibs_cbs_classification_code"
                name="ibs_cbs_classification_code"
                type="text"
                inputmode="numeric"
                maxlength="6"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
          </fieldset>
        </section>
      </template>

      <template #variants>
        <section aria-labelledby="product-variants-title" class="space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 id="product-variants-title" class="text-highlighted font-medium">
                Variantes
              </h3>
              <p class="text-muted text-xs">
                Use variantes para opções do mesmo produto.
              </p>
            </div>
            <UButton
              v-if="mode !== 'detail'"
              label="Adicionar variante"
              color="neutral"
              variant="soft"
              :disabled="disabled"
              @click="addVariant"
            />
          </div>
          <div v-for="(variant, index) in form.variants" :key="variant.id ?? `new-${index}`" class="border-default grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
            <p class="text-muted text-xs sm:col-span-2">
              {{ variant.id ? `Variante #${variant.id}` : 'Nova variante' }}<span v-if="variant.status === 'archived'"> · arquivada</span>
            </p>
            <ErpCatalogField
              label="Nome da variante"
              required
              :name="`variants.${index}.name`"
              :error="fieldErrors?.[`variants.${index}.name`]?.[0]"
            >
              <UInput
                v-model="variant.name"
                :name="`variants.${index}.name`"
                :disabled="disabled"
                required
                maxlength="255"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="SKU da variante" :name="`variants.${index}.sku`" :error="fieldErrors?.[`variants.${index}.sku`]?.[0]">
              <UInput
                v-model="variant.sku"
                :name="`variants.${index}.sku`"
                :disabled="disabled"
                maxlength="64"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Preço da variante" :name="`variants.${index}.sale_price`" :error="fieldErrors?.[`variants.${index}.sale_price`]?.[0]">
              <UInput
                v-model="variant.sale_price"
                :name="`variants.${index}.sale_price`"
                :disabled="disabled"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField label="Código de barras da variante" :name="`variants.${index}.barcode`" :error="fieldErrors?.[`variants.${index}.barcode`]?.[0]">
              <UInput
                v-model="variant.barcode"
                :name="`variants.${index}.barcode`"
                :disabled="disabled"
                maxlength="64"
                autocomplete="off"
                class="w-full"
              />
            </ErpCatalogField>
            <ErpCatalogField
              label="Simbologia da variante"
              :name="`variants.${index}.barcode_type`"
              :error="fieldErrors?.[`variants.${index}.barcode_type`]?.[0]"
              description="Herdar usa a simbologia definida no produto."
            >
              <USelect
                v-model="variant.barcode_type"
                :name="`variants.${index}.barcode_type`"
                :data-erp-field-name="`variants.${index}.barcode_type`"
                :items="variantBarcodeTypeItems"
                value-key="value"
                :disabled="disabled"
                class="w-full"
              />
            </ErpCatalogField>
            <div v-if="mode !== 'detail' && !variant.id" class="sm:col-span-2">
              <UButton
                label="Remover variante nova"
                color="neutral"
                variant="ghost"
                :disabled="disabled || form.variants.length === 1"
                @click="form.variants.splice(index, 1)"
              />
            </div>
            <div v-else-if="mode !== 'detail' && variant.id && variant.status !== 'archived' && canArchive" class="sm:col-span-2">
              <UButton
                label="Arquivar variante"
                color="error"
                variant="soft"
                :disabled="disabled || busy"
                @click="openVariantArchive(index)"
              />
            </div>
            <p v-if="variantIdError(index)" role="alert" class="text-error text-xs sm:col-span-2">
              {{ variantIdError(index) }}
            </p>
          </div>
          <p v-if="fieldErrors?.variants?.[0]" role="alert" class="text-error text-xs">
            {{ fieldErrors.variants[0] }}
          </p>
        </section>
      </template>
    </UAccordion>
  </UForm>
  <UModal
    v-model:open="variantArchiveOpen"
    title="Arquivar variante"
    :description="variantArchiveDescription"
  >
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          label="Cancelar"
          color="neutral"
          variant="ghost"
          :disabled="busy"
          @click="variantArchiveOpen = false"
        />
        <UButton
          label="Confirmar arquivamento"
          color="error"
          :disabled="disabled || !canArchive"
          :loading="busy"
          @click="confirmVariantArchive"
        />
      </div>
    </template>
  </UModal>
</template>
