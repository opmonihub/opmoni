<script setup lang="ts">
import type { ErpPurchaseDraftLine, ErpPurchaseDraftPayload, ErpPurchaseEstablishment, ErpPurchaseSupplier, ErpPurchaseUnit, ErpPurchaseVariant } from '#shared/types/erpBusiness'
import { addPurchaseMoney, calculatePurchaseLineTotal, convertPurchaseQuantity, formatPurchaseDecimal, purchaseUnitFactor, replacePurchaseTax, samePurchaseDecimal } from '#shared/utils/erpPurchase'
import { erpProductVariantQuery, formatErpProductVariantLabel } from '~/utils/erpProductVariantReferences'

const props = defineProps<{
  suppliers: ErpPurchaseSupplier[]
  establishments: ErpPurchaseEstablishment[]
  variants: ErpPurchaseVariant[]
  units: ErpPurchaseUnit[]
  fieldErrors?: Record<string, string>
  operationError?: string
  loading?: boolean
  xmlOrigin?: boolean
  status?: 'draft' | 'posted'
}>()
const draft = defineModel<ErpPurchaseDraftPayload>({ required: true })
const emit = defineEmits<{ submit: [payload: ErpPurchaseDraftPayload], cancel: [] }>()
const localError = ref('')
type VariantMetadata = { name: string, baseUnitId: number }
type VariantSelection = { id: number | null, record: { id: number, name?: string, product?: { name?: string, erp_unit_id?: number } } | null, source: 'selection' | 'hydration' }
const variantQuery = erpProductVariantQuery('purchase')
const selectedVariants = reactive(new Map<number, VariantMetadata>())
const supplierOptions = computed(() => props.suppliers.map(row => ({ id: row.id, label: row.trade_name || row.name, description: row.tax_id || '' })))
const establishmentOptions = computed(() => props.establishments.map(row => ({ id: row.id, label: row.name })))

function variantFor(line: ErpPurchaseDraftLine): VariantMetadata | undefined {
  const id = line.erp_product_variant_id
  if (!id) return undefined
  const cached = selectedVariants.get(id)
  if (cached) return cached
  const variant = props.variants.find(row => row.id === id)
  return variant ? { name: variant.product.name, baseUnitId: variant.product.erp_unit_id } : undefined
}
function compatibleUnits(line: ErpPurchaseDraftLine) {
  const variant = variantFor(line)
  if (!variant) return props.units
  return props.units.filter(unit => purchaseUnitFactor(unit.id, variant.baseUnitId, props.units) !== null)
}
function factorFor(line: ErpPurchaseDraftLine): string | null {
  const variant = variantFor(line)
  if (!variant) return line.erp_unit_id === null ? '1.00000000' : null
  if (line.erp_unit_id === null) return '1.00000000'
  return purchaseUnitFactor(line.erp_unit_id, variant.baseUnitId, props.units)
}
function displayedBaseQuantity(line: ErpPurchaseDraftLine): string {
  try {
    const factor = factorFor(line)
    return factor ? convertPurchaseQuantity(line.quantity, factor) : '—'
  } catch {
    return '—'
  }
}
function lineTotal(line: ErpPurchaseDraftLine): string {
  const accepted = line.accepted_amounts
  const unchangedSourceBasis = props.xmlOrigin && accepted !== undefined
    && samePurchaseDecimal(line.quantity, accepted.quantity)
    && samePurchaseDecimal(line.unit_cost, accepted.unit_cost)
    && samePurchaseDecimal(line.discount_amount || '0.0000', accepted.discount_amount)
  if (unchangedSourceBasis) {
    const tax = line.tax_amount || '0.0000'
    if (line.erp_tax_rate_id && !samePurchaseDecimal(tax, accepted.tax_amount)) return '—'
    try {
      return replacePurchaseTax(accepted.line_total, accepted.tax_amount, tax)
    } catch {
      return '—'
    }
  }
  if (line.erp_tax_rate_id) return '—'
  try {
    return calculatePurchaseLineTotal(line.quantity, line.unit_cost, line.discount_amount || '0.0000', line.tax_amount || '0.0000')
  } catch {
    return '—'
  }
}
const totalAmount = computed(() => {
  if (draft.value.lines.some(line => lineTotal(line) === '—')) return null
  return draft.value.lines.reduce((sum, line) => {
    const amount = lineTotal(line)
    return amount === '—' ? sum : addPurchaseMoney(sum, amount)
  }, '0.0000')
})
function selectVariant(line: ErpPurchaseDraftLine, selection: VariantSelection) {
  const record = selection.record
  const baseUnitId = record?.product?.erp_unit_id
  if (record?.product && typeof baseUnitId === 'number' && Number.isSafeInteger(baseUnitId))
    selectedVariants.set(record.id, { name: record.product.name || record.name || '', baseUnitId })
  if (selection.source === 'hydration') return
  line.erp_product_variant_id = selection.id
  const variant = variantFor(line)
  if (!variant) {
    line.erp_unit_id = null
    line.unit_conversion_factor = '1.00000000'
    return
  }
  line.erp_unit_id = variant.baseUnitId
  line.unit_conversion_factor = '1.00000000'
  if (!line.description.trim()) line.description = variant.name
}
function selectUnit(line: ErpPurchaseDraftLine, unitId: number | null | undefined) {
  line.erp_unit_id = typeof unitId === 'number' ? unitId : null
  line.unit_conversion_factor = factorFor(line) ?? ''
}
function addLine() {
  draft.value.lines.push({
    erp_product_variant_id: null,
    erp_unit_id: null,
    description: '',
    quantity: '1.0000',
    unit_cost: '0.0000',
    unit_conversion_factor: '1.00000000',
    discount_amount: '0.0000',
    tax_amount: '0.0000'
  })
}
function removeLine(index: number) {
  if (!props.xmlOrigin && draft.value.lines.length > 1) draft.value.lines.splice(index, 1)
}
function submit() {
  localError.value = ''
  if (!draft.value.erp_establishment_id || !draft.value.erp_contact_id) {
    localError.value = 'Selecione o estabelecimento e o fornecedor.'
    return
  }
  if (draft.value.lines.length < 1 || draft.value.lines.length > 500) {
    localError.value = 'Informe de 1 a 500 itens.'
    return
  }
  try {
    const lines = draft.value.lines.map((line, index) => {
      if (!line.description.trim()) throw new Error(`Descreva o item ${index + 1}.`)
      const factor = factorFor(line)
      if (!factor) throw new Error(`Selecione uma unidade compatível no item ${index + 1}.`)
      const quantity = convertPurchaseQuantity(line.quantity, factor)
      if (quantity === '0.0000') throw new Error(`A quantidade do item ${index + 1} deve ser positiva.`)
      const accepted = line.accepted_amounts
      const unchangedSourceBasis = Boolean(props.xmlOrigin && accepted
        && samePurchaseDecimal(line.quantity, accepted.quantity)
        && samePurchaseDecimal(line.unit_cost, accepted.unit_cost)
        && samePurchaseDecimal(line.discount_amount || '0.0000', accepted.discount_amount))
      if (!unchangedSourceBasis) calculatePurchaseLineTotal(line.quantity, line.unit_cost, line.discount_amount || '0.0000', line.tax_amount || '0.0000')
      const unchangedAcceptedBasis = Boolean(props.xmlOrigin && accepted
        && samePurchaseDecimal(line.quantity, accepted.quantity)
        && samePurchaseDecimal(line.unit_cost, accepted.unit_cost)
        && samePurchaseDecimal(line.discount_amount || '0.0000', accepted.discount_amount))
      return {
        ...(line.id ? { id: line.id } : {}),
        erp_product_variant_id: line.erp_product_variant_id,
        erp_unit_id: line.erp_unit_id,
        description: line.description.trim(),
        quantity: line.quantity,
        unit_cost: line.unit_cost,
        unit_conversion_factor: factor,
        discount_amount: line.discount_amount || '0.0000',
        ...((!line.erp_tax_rate_id || unchangedAcceptedBasis) ? { tax_amount: line.tax_amount || '0.0000' } : {}),
        ...(line.erp_tax_rate_id ? { erp_tax_rate_id: line.erp_tax_rate_id } : {}),
        ...(line.erp_operation_nature_id ? { erp_operation_nature_id: line.erp_operation_nature_id } : {})
      }
    })
    const payload: ErpPurchaseDraftPayload = {
      erp_establishment_id: draft.value.erp_establishment_id,
      erp_contact_id: draft.value.erp_contact_id,
      currency: draft.value.currency,
      document_no: draft.value.document_no.trim(),
      ...(draft.value.draft_revision === undefined ? {} : { draft_revision: draft.value.draft_revision }),
      ...(totalAmount.value === null ? {} : { expected_total: totalAmount.value }),
      lines
    }
    emit('submit', payload)
  } catch (error) {
    localError.value = error instanceof Error ? error.message : 'Revise as quantidades e os valores informados.'
  }
}

defineExpose({ submit, totalAmount, displayedBaseQuantity, localError })
</script>

<template>
  <form class="space-y-5" @submit.prevent="submit">
    <UAlert v-if="status === 'posted'" color="info" title="Compra lançada">
      O histórico de origem é imutável. Consulte as devoluções pelo detalhe da compra.
    </UAlert>
    <UAlert v-if="operationError" color="error" :title="operationError" />
    <UAlert v-if="xmlOrigin" color="info" title="Compra importada por XML">
      O fornecedor e o estabelecimento confirmados pela nota permanecem vinculados à origem.
    </UAlert>
    <UAlert v-if="localError" color="error" :title="localError" />
    <section class="grid gap-4 sm:grid-cols-2" aria-label="Dados da compra">
      <UFormField label="Estabelecimento" name="erp_establishment_id" :error="fieldErrors?.erp_establishment_id">
        <UInputMenu
          :model-value="draft.erp_establishment_id ?? undefined"
          :items="establishmentOptions"
          value-key="id"
          placeholder="Buscar estabelecimento"
          :disabled="loading || xmlOrigin || status === 'posted'"
          class="w-full"
          aria-label="Estabelecimento da compra"
          @update:model-value="draft.erp_establishment_id = $event ?? null"
        />
      </UFormField>
      <UFormField label="Fornecedor" name="erp_contact_id" :error="fieldErrors?.erp_contact_id">
        <UInputMenu
          :model-value="draft.erp_contact_id ?? undefined"
          :items="supplierOptions"
          value-key="id"
          placeholder="Buscar fornecedor"
          :disabled="loading || xmlOrigin || status === 'posted'"
          class="w-full"
          aria-label="Fornecedor da compra"
          @update:model-value="draft.erp_contact_id = $event ?? null"
        />
      </UFormField>
      <UFormField label="Número do documento" name="document_no" :error="fieldErrors?.document_no">
        <UInput
          v-model="draft.document_no"
          aria-label="Número do documento"
          maxlength="64"
          :disabled="loading || status === 'posted'"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Moeda" name="currency" :error="fieldErrors?.currency">
        <UInput
          v-model="draft.currency"
          maxlength="3"
          :disabled="loading || status === 'posted' || xmlOrigin"
          class="w-full uppercase"
        />
      </UFormField>
    </section>

    <section class="space-y-3" aria-labelledby="purchase-lines-heading">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 id="purchase-lines-heading" class="font-semibold text-highlighted">
            Itens da compra
          </h3>
          <p class="text-sm text-muted">
            Quantidades, custos e conversões permanecem em texto decimal exato.
          </p>
        </div>
        <UButton
          v-if="!xmlOrigin && status !== 'posted'"
          type="button"
          color="neutral"
          variant="outline"
          icon="i-lucide-plus"
          label="Adicionar item"
          @click="addLine"
        />
      </div>

      <div
        v-for="(line, index) in draft.lines"
        :key="line.id ?? `new-${index}`"
        class="space-y-3 border-b border-default pb-4 last:border-0"
        :data-purchase-line="index"
      >
        <div class="flex items-center justify-between gap-3">
          <h4 class="text-sm font-medium text-highlighted">
            Item {{ index + 1 }}<span v-if="line.id"> · linha {{ line.id }}</span>
          </h4>
          <UButton
            v-if="!xmlOrigin && status !== 'posted' && draft.lines.length > 1"
            type="button"
            color="neutral"
            variant="ghost"
            icon="i-lucide-trash-2"
            :aria-label="`Remover item ${index + 1}`"
            @click="removeLine(index)"
          />
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <ErpCatalogReferenceSelect
            :model-value="line.erp_product_variant_id"
            path="/erp/product-variants"
            :query="variantQuery"
            label="Produto ou variante"
            label-key="name"
            :label-formatter="formatErpProductVariantLabel"
            :name="`lines.${index}.erp_product_variant_id`"
            :optional="true"
            :disabled="loading || status === 'posted'"
            class="w-full"
            :error="fieldErrors?.[`lines.${index}.erp_product_variant_id`]"
            @selection-record="selectVariant(line, $event)"
          />
          <UFormField label="Unidade" :name="`lines.${index}.erp_unit_id`" :error="fieldErrors?.[`lines.${index}.erp_unit_id`]">
            <UInputMenu
              :model-value="line.erp_unit_id ?? undefined"
              :items="compatibleUnits(line).map(unit => ({ id: unit.id, label: unit.short_name, description: unit.actual_name }))"
              value-key="id"
              placeholder="Buscar unidade"
              :disabled="loading || status === 'posted' || !line.erp_product_variant_id"
              class="w-full"
              :aria-label="`Unidade do item ${index + 1}`"
              @update:model-value="selectUnit(line, $event)"
            />
          </UFormField>
          <UFormField label="Quantidade" :name="`lines.${index}.quantity`" :error="fieldErrors?.[`lines.${index}.quantity`]">
            <UInput
              v-model="line.quantity"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              placeholder="0.0000"
              :disabled="loading || status === 'posted'"
              class="w-full tabular-nums"
            />
          </UFormField>
          <UFormField label="Custo unitário" :name="`lines.${index}.unit_cost`" :error="fieldErrors?.[`lines.${index}.unit_cost`]">
            <UInput
              v-model="line.unit_cost"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              placeholder="0.0000"
              :disabled="loading || status === 'posted'"
              class="w-full tabular-nums"
            />
          </UFormField>
          <UFormField
            label="Descrição"
            :name="`lines.${index}.description`"
            :error="fieldErrors?.[`lines.${index}.description`]"
            class="sm:col-span-2"
          >
            <UInput
              v-model="line.description"
              maxlength="1000"
              :disabled="loading || status === 'posted'"
              class="w-full"
            />
          </UFormField>
          <UFormField label="Desconto" :name="`lines.${index}.discount_amount`" :error="fieldErrors?.[`lines.${index}.discount_amount`]">
            <UInput
              v-model="line.discount_amount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              :disabled="loading || status === 'posted'"
              class="w-full tabular-nums"
            />
          </UFormField>
          <UFormField label="Imposto" :name="`lines.${index}.tax_amount`" :error="fieldErrors?.[`lines.${index}.tax_amount`]">
            <UInput
              v-model="line.tax_amount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              :disabled="loading || status === 'posted'"
              class="w-full tabular-nums"
            />
          </UFormField>
        </div>
        <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted tabular-nums">
          <span>Conversão: {{ factorFor(line) ?? 'incompatível' }}</span>
          <span>Quantidade base: {{ displayedBaseQuantity(line) }}</span>
          <span>Parcial estimado: {{ lineTotal(line) === '—' ? '—' : `${draft.currency} ${formatPurchaseDecimal(lineTotal(line))}` }}</span>
        </div>
      </div>
      <div class="flex justify-end border-t border-default pt-3">
        <p class="text-right text-sm text-muted">
          Total estimado<br><strong class="text-lg text-highlighted tabular-nums">{{ totalAmount === null ? 'Calculado pelo servidor' : `${draft.currency} ${formatPurchaseDecimal(totalAmount)}` }}</strong>
        </p>
      </div>
    </section>

    <div class="flex flex-wrap justify-end gap-2">
      <UButton
        type="button"
        color="neutral"
        variant="outline"
        label="Fechar"
        @click="emit('cancel')"
      />
      <UButton
        v-if="status !== 'posted'"
        type="submit"
        :loading="loading"
        :disabled="loading"
        icon="i-lucide-save"
        :label="draft.draft_revision ? 'Salvar alterações' : 'Salvar rascunho'"
      />
    </div>
  </form>
</template>
