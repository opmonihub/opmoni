<script setup lang="ts">
import type { ErpCommercialDraftPayload, ErpCommercialKind, ErpSalesContact, ErpSalesEstablishment, ErpSalesUnit, ErpSalesVariant } from '#shared/types/erpBusiness'

const props = defineProps<{
  kind: ErpCommercialKind
  draft: ErpCommercialDraftPayload
  references: { establishments: ErpSalesEstablishment[], contacts: ErpSalesContact[], units: ErpSalesUnit[], variants: ErpSalesVariant[] }
  errors: Record<string, string>
  saving: boolean
  canOverridePrice: boolean
  canOverrideDiscount: boolean
  disabled?: boolean
}>()
const emit = defineEmits<{ save: [payload: ErpCommercialDraftPayload], cancel: [] }>()
const draft = ref(JSON.parse(JSON.stringify(props.draft)) as ErpCommercialDraftPayload)

const title = computed(() => props.kind === 'quote' ? 'Orçamento' : props.kind === 'order' ? 'Pedido' : 'Venda de balcão')
const headerDiscountAmount = computed({
  get: () => draft.value.discount?.discount_amount ?? '',
  set: (value: string) => { draft.value.discount = { discount_type: draft.value.discount?.discount_type ?? 'fixed', discount_amount: value } }
})
const headerDiscountType = computed({
  get: () => draft.value.discount?.discount_type ?? 'fixed',
  set: (value: string) => { draft.value.discount = { discount_type: value === 'percentage' ? 'percentage' : 'fixed', discount_amount: draft.value.discount?.discount_amount ?? '0.0000' } }
})
const headerTaxRate = computed({
  get: () => draft.value.tax_rate_percent ?? '',
  set: (value: string) => { draft.value.tax_rate_percent = value }
})
const establishmentItems = computed(() => props.references.establishments.map(row => ({ label: row.name, value: row.id })))
const contactItems = computed(() => [{ label: 'Sem cliente vinculado', value: 0 }, ...props.references.contacts.map(row => ({ label: row.trade_name || row.name, value: row.id }))])
const unitItems = computed(() => [{ label: 'Unidade do catálogo', value: 0 }, ...props.references.units.map(row => ({ label: row.symbol ? `${row.name} (${row.symbol})` : row.name, value: row.id }))])
const variantItems = computed(() => [{ label: 'Item sem produto', value: 0 }, ...props.references.variants.map(row => ({ label: `${row.name}${row.sku ? ` · ${row.sku}` : ''}`, value: row.id }))])
const formName = (field: string) => props.errors[field] ?? ''

function selectValue(value: unknown): number | null {
  if (value === null || value === undefined || value === '' || value === 0 || value === '0') return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}
function addLine() {
  draft.value.lines.push({ erp_product_variant_id: null, erp_unit_id: null, description: '', quantity: '1.0000', discount_amount: '0.0000', tax_amount: '0.0000' })
}
function removeLine(index: number) {
  if (draft.value.lines.length > 1) draft.value.lines.splice(index, 1)
}
function submit() {
  const payload = JSON.parse(JSON.stringify(draft.value)) as ErpCommercialDraftPayload
  payload.currency = 'BRL'
  if (!payload.discount?.discount_amount.trim()) delete payload.discount
  if (!payload.tax_rate_percent?.trim()) delete payload.tax_rate_percent
  payload.lines = payload.lines.map((line) => {
    if (!line.description?.trim()) delete line.description
    if (!line.unit_price?.trim()) delete line.unit_price
    if (!line.unit_conversion_factor?.trim()) delete line.unit_conversion_factor
    if (!line.erp_tax_rate_id) delete line.erp_tax_rate_id
    if (!line.erp_discount_rule_id) delete line.erp_discount_rule_id
    if (!line.line_total?.trim()) delete line.line_total
    return line
  })
  emit('save', payload)
}
defineExpose({ submit })
</script>

<template>
  <UForm :state="draft" class="min-w-0 space-y-5" @submit="submit">
    <div class="grid min-w-0 gap-4 sm:grid-cols-2">
      <UFormField
        name="erp_establishment_id"
        label="Estabelecimento"
        required
        :error="formName('erp_establishment_id')"
      >
        <USelect
          :model-value="draft.erp_establishment_id ?? undefined"
          :items="establishmentItems"
          placeholder="Selecione"
          class="w-full"
          :disabled="disabled || saving"
          @update:model-value="draft.erp_establishment_id = selectValue($event)"
        />
      </UFormField>
      <UFormField name="erp_contact_id" label="Cliente" :error="formName('erp_contact_id')">
        <USelect
          :model-value="draft.erp_contact_id ?? 0"
          :items="contactItems"
          class="w-full"
          :disabled="disabled || saving"
          @update:model-value="draft.erp_contact_id = selectValue($event)"
        />
      </UFormField>
      <UFormField
        v-if="kind === 'quote'"
        name="quote_no"
        label="Número do orçamento"
        :error="formName('quote_no')"
      >
        <UInput v-model="draft.quote_no" class="w-full" :disabled="disabled || saving" />
      </UFormField>
      <UFormField
        v-else-if="kind === 'order'"
        name="order_no"
        label="Número do pedido"
        :error="formName('order_no')"
      >
        <UInput v-model="draft.order_no" class="w-full" :disabled="disabled || saving" />
      </UFormField>
      <UFormField
        v-else
        name="invoice_no"
        label="Documento de balcão"
        :error="formName('invoice_no')"
      >
        <UInput v-model="draft.invoice_no" class="w-full" :disabled="disabled || saving" />
      </UFormField>
      <div v-if="draft.erp_discount_rule_id" class="self-end text-xs text-muted">
        Regra de desconto do documento #{{ draft.erp_discount_rule_id }} será mantida.
      </div>
      <UFormField
        v-if="canOverrideDiscount || draft.discount"
        name="discount.discount_amount"
        label="Desconto do documento"
        :error="formName('discount.discount_amount')"
      >
        <div class="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)] gap-2">
          <USelect v-model="headerDiscountType" :items="[{ label: 'Valor fixo', value: 'fixed' }, { label: 'Percentual', value: 'percentage' }]" :disabled="disabled || saving || !canOverrideDiscount || Boolean(draft.erp_discount_rule_id)" />
          <UInput
            v-model="headerDiscountAmount"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            class="w-full"
            :disabled="disabled || saving || !canOverrideDiscount || Boolean(draft.erp_discount_rule_id)"
          />
        </div>
      </UFormField>
      <UFormField
        v-if="draft.tax_rate_percent !== undefined"
        name="tax_rate_percent"
        label="Alíquota informada no documento (%)"
        :error="formName('tax_rate_percent')"
      >
        <UInput
          v-model="headerTaxRate"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          class="w-full"
          :disabled="disabled || saving"
        />
      </UFormField>
    </div>

    <section class="min-w-0 space-y-3" aria-labelledby="commercial-lines-title">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="commercial-lines-title" class="text-sm font-semibold text-highlighted">
          Itens
        </h3>
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-plus"
          label="Adicionar item"
          :disabled="disabled || saving"
          @click="addLine"
        />
      </div>
      <article v-for="(line, index) in draft.lines" :key="line.id ?? `new-${index}`" class="min-w-0 space-y-3 rounded-lg border border-muted p-3">
        <div class="flex min-w-0 items-start justify-between gap-2">
          <p class="text-sm font-medium text-highlighted">
            Item {{ index + 1 }}
          </p>
          <UButton
            v-if="draft.lines.length > 1"
            type="button"
            color="neutral"
            variant="ghost"
            size="xs"
            icon="i-lucide-trash-2"
            :aria-label="`Remover item ${index + 1}`"
            :disabled="disabled || saving"
            @click="removeLine(index)"
          />
        </div>
        <div class="grid min-w-0 gap-3 sm:grid-cols-2">
          <UFormField :name="`lines.${index}.erp_product_variant_id`" label="Produto ou serviço" :error="formName(`lines.${index}.erp_product_variant_id`)">
            <USelect
              :model-value="line.erp_product_variant_id ?? 0"
              :items="variantItems"
              class="w-full"
              :disabled="disabled || saving"
              @update:model-value="line.erp_product_variant_id = selectValue($event)"
            />
          </UFormField>
          <UFormField :name="`lines.${index}.description`" label="Descrição" :error="formName(`lines.${index}.description`)">
            <UInput v-model="line.description" class="w-full" :disabled="disabled || saving" />
          </UFormField>
          <UFormField :name="`lines.${index}.erp_unit_id`" label="Unidade" :error="formName(`lines.${index}.erp_unit_id`)">
            <USelect
              :model-value="line.erp_unit_id ?? 0"
              :items="unitItems"
              class="w-full"
              :disabled="disabled || saving"
              @update:model-value="line.erp_unit_id = selectValue($event)"
            />
          </UFormField>
          <UFormField
            :name="`lines.${index}.quantity`"
            label="Quantidade"
            required
            :error="formName(`lines.${index}.quantity`)"
          >
            <UInput
              v-model="line.quantity"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving"
            />
          </UFormField>
          <UFormField :name="`lines.${index}.discount_amount`" label="Desconto (R$)" :error="formName(`lines.${index}.discount_amount`)">
            <UInput
              v-model="line.discount_amount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving || Boolean(draft.discount || draft.erp_discount_rule_id) || (!canOverrideDiscount && line.discount_amount !== '0.0000')"
            />
          </UFormField>
          <UFormField :name="`lines.${index}.tax_amount`" label="Imposto informado (R$)" :error="formName(`lines.${index}.tax_amount`)">
            <UInput
              v-model="line.tax_amount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving"
            />
          </UFormField>
          <UFormField
            v-if="canOverridePrice || line.unit_price"
            :name="`lines.${index}.unit_price`"
            :label="canOverridePrice ? 'Preço unitário manual (R$)' : 'Preço unitário confirmado (R$)'"
            :error="formName(`lines.${index}.unit_price`)"
          >
            <UInput
              v-model="line.unit_price"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving || !canOverridePrice"
              placeholder="Em branco: usar preço do catálogo"
            />
          </UFormField>
          <p v-else class="self-end text-xs text-muted">
            O preço do catálogo será confirmado pelo servidor ao salvar.
          </p>
        </div>
      </article>
    </section>

    <UAlert
      color="info"
      variant="subtle"
      title="Totais calculados pelo servidor"
      description="Preços, unidade, descontos, impostos e total serão atualizados depois de salvar e consultar o documento autorizado."
    />
    <div class="flex flex-wrap justify-end gap-2 border-t border-muted pt-4">
      <UButton
        type="button"
        color="neutral"
        variant="ghost"
        label="Cancelar"
        :disabled="saving"
        @click="emit('cancel')"
      />
      <UButton
        type="submit"
        color="primary"
        :loading="saving"
        :disabled="disabled || saving"
        :label="`Salvar ${title.toLocaleLowerCase('pt-BR')}`"
      />
    </div>
  </UForm>
</template>
