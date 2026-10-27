<script setup lang="ts">
import type { ErpOperationNatureRecord } from '#shared/types/erpCatalogs'
import type { ErpCommercialDraftPayload, ErpCommercialKind, ErpCommercialTaxContext, ErpSalesContact, ErpSalesEstablishment, ErpSalesUnit, ErpSalesVariant } from '#shared/types/erpBusiness'
import { erpProductVariantQuery, formatErpProductVariantLabel } from '~/utils/erpProductVariantReferences'

const props = defineProps<{
  kind: ErpCommercialKind
  draft: ErpCommercialDraftPayload
  references: { establishments: ErpSalesEstablishment[], contacts: ErpSalesContact[], units: ErpSalesUnit[], variants: ErpSalesVariant[], operationNatures?: ErpOperationNatureRecord[] }
  errors: Record<string, string>
  saving: boolean
  canOverridePrice: boolean
  canOverrideDiscount: boolean
  disabled?: boolean
  editing?: boolean
  natureState?: { permitted: boolean, loading: boolean, confirmed: boolean, error: string }
}>()
const emit = defineEmits<{ 'update:draft': [payload: ErpCommercialDraftPayload], 'save': [payload: ErpCommercialDraftPayload], 'cancel': [] }>()
const draft = ref(JSON.parse(JSON.stringify(props.draft)) as ErpCommercialDraftPayload)
watch(draft, value => emit('update:draft', JSON.parse(JSON.stringify(value)) as ErpCommercialDraftPayload), { deep: true, flush: 'sync' })

const title = computed(() => props.kind === 'quote' ? 'Orçamento' : props.kind === 'order' ? 'Pedido' : 'Venda de balcão')
const headerDiscountAmount = computed({
  get: () => draft.value.discount?.discount_amount ?? '',
  set: (value: string) => {
    draft.value.discount = { discount_type: draft.value.discount?.discount_type ?? 'fixed', discount_amount: value }
    clearLineTotals()
  }
})
const headerDiscountType = computed({
  get: () => draft.value.discount?.discount_type ?? 'fixed',
  set: (value: string) => {
    draft.value.discount = { discount_type: value === 'percentage' ? 'percentage' : 'fixed', discount_amount: draft.value.discount?.discount_amount ?? '0.0000' }
    clearLineTotals()
  }
})
const headerTaxRate = computed({
  get: () => draft.value.tax_rate_percent ?? '',
  set: (value: string) => {
    draft.value.tax_rate_percent = value
    clearLineTotals()
  }
})
const establishmentItems = computed(() => props.references.establishments.map(row => ({ label: row.name, value: row.id })))
const canUseDefaultCustomer = computed(() => props.kind === 'sale' && !props.editing)
const contactItems = computed(() => [
  ...(canUseDefaultCustomer.value ? [{ label: 'Usar cliente padrão da empresa', value: -1 }] : []),
  { label: 'Sem cliente vinculado', value: 0 },
  ...props.references.contacts.map(row => ({ label: row.trade_name || row.name, value: row.id }))
])
const customerSelection = computed(() => canUseDefaultCustomer.value && !Object.hasOwn(draft.value, 'erp_contact_id') ? -1 : draft.value.erp_contact_id ?? 0)
function selectCustomer(value: unknown) {
  if (canUseDefaultCustomer.value && (value === -1 || value === '-1')) delete draft.value.erp_contact_id
  else draft.value.erp_contact_id = selectValue(value)
}
const currencyItems = computed(() => [
  ...(!props.editing ? [{ label: 'Usar moeda padrão da empresa', value: 'default' }] : []),
  { label: 'BRL · Real brasileiro', value: 'BRL' }
])
const currencySelection = computed(() => draft.value.currency ?? 'default')
function selectCurrency(value: unknown) {
  if (value === 'BRL') draft.value.currency = 'BRL'
  else if (value === 'default' && !props.editing) delete draft.value.currency
}
const unitItems = computed(() => [{ label: 'Unidade do catálogo', value: 0 }, ...props.references.units.map(row => ({ label: row.symbol ? `${row.name} (${row.symbol})` : row.name, value: row.id }))])
const variantQuery = erpProductVariantQuery('sales')
const operationNatureItems = computed(() => {
  const choices = (props.references.operationNatures ?? []).filter(row => row.status === 'active' && (row.erp_establishment_id === null || row.erp_establishment_id === draft.value.erp_establishment_id)).map(row => ({ label: `${row.cfop} · ${row.description}`, value: row.id, disabled: false }))
  const currentId = draft.value.erp_operation_nature_id
  if (currentId && !choices.some(row => row.value === currentId)) choices.unshift({ label: `Natureza #${currentId} indisponível — selecione outra para substituir`, value: currentId, disabled: true })
  return [{ label: 'Sem natureza vinculada', value: 0, disabled: false }, ...choices]
})
watch(() => draft.value.erp_establishment_id, (establishmentId) => {
  const nature = props.references.operationNatures?.find(row => row.id === draft.value.erp_operation_nature_id)
  if (nature?.erp_establishment_id !== null && nature?.erp_establishment_id !== undefined && nature.erp_establishment_id !== establishmentId) draft.value.erp_operation_nature_id = null
}, { flush: 'sync' })
const formName = (field: string) => props.errors[field] ?? ''
const fiscalIntentItems = [{ label: 'Sem intenção fiscal declarada', value: 'none' }, { label: 'NF-e (55)', value: '55' }, { label: 'NFC-e (65)', value: '65' }]
const contextFields = [{ name: 'origin_uf', label: 'UF de origem' }, { name: 'destination_uf', label: 'UF do destinatário' }, { name: 'model', label: 'Modelo do contexto' }, { name: 'purpose', label: 'Finalidade' }, { name: 'presence', label: 'Presença' }] as const
function selectFiscalIntent(value: unknown) {
  if (value === '55' || value === '65') draft.value.fiscal_model = value
  else draft.value.fiscal_model = null
}
function updateTaxContext(field: keyof ErpCommercialTaxContext, value: string | boolean | null) {
  draft.value.tax_context = { ...draft.value.tax_context, [field]: value }
}
function clearLineTotals() {
  for (const line of draft.value.lines) line.line_total = undefined
}
function setLineAmount(line: ErpCommercialDraftPayload['lines'][number], key: 'quantity' | 'unit_price' | 'discount_amount' | 'tax_amount', value: string) {
  line[key] = value
  line.line_total = undefined
}

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
function selectVariant(line: ErpCommercialDraftPayload['lines'][number], selection: { id: number | null, source: 'selection' | 'hydration' }) {
  if (selection.source !== 'selection') return
  const next = selection.id
  if (line.erp_product_variant_id !== next) {
    line.erp_product_variant_id = next
    line.erp_unit_id = null
    line.unit_conversion_factor = undefined
    line.unit_price = undefined
    line.line_total = undefined
  }
}
function selectUnit(line: ErpCommercialDraftPayload['lines'][number], value: unknown) {
  const next = selectValue(value)
  if (line.erp_unit_id !== next) {
    line.erp_unit_id = next
    line.unit_conversion_factor = undefined
    line.unit_price = undefined
    line.line_total = undefined
  }
}
function useCurrentCatalog(line: ErpCommercialDraftPayload['lines'][number]) {
  line.erp_unit_id = null
  line.unit_conversion_factor = undefined
  line.unit_price = undefined
  line.line_total = undefined
}
function submit() {
  const payload = JSON.parse(JSON.stringify(draft.value)) as ErpCommercialDraftPayload
  if (props.kind !== 'sale') delete payload.fiscal_model
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
defineExpose({ submit, draft, selectVariant, selectUnit, useCurrentCatalog })
</script>

<template>
  <UForm :state="draft" class="min-w-0 space-y-5" @submit="submit">
    <details :open="Boolean(draft.fiscal_model || draft.tax_context || errors.fiscal_model || Object.keys(errors).some(key => key.startsWith('tax_context.')))" class="space-y-3 rounded-lg border border-muted p-3">
      <summary class="cursor-pointer text-sm font-medium text-highlighted">
        Intenção e contexto fiscal (opcional)
      </summary>
      <p class="text-sm text-muted">
        A classificação não representa autorização fiscal. Salve o rascunho e revise a prévia antes de aplicar atualizações. Alterar a intenção exige uma revisão explícita no documento salvo.
      </p>
      <UFormField
        v-if="kind === 'sale'"
        name="fiscal_model"
        label="Intenção fiscal da venda"
        :error="formName('fiscal_model')"
      >
        <USelect
          :model-value="draft.fiscal_model ?? 'none'"
          :items="fiscalIntentItems"
          class="w-full"
          :disabled="disabled || saving"
          @update:model-value="selectFiscalIntent"
        />
      </UFormField>
      <div class="grid gap-3 sm:grid-cols-2">
        <UFormField
          v-for="field in contextFields"
          :key="field.name"
          :name="`tax_context.${field.name}`"
          :label="field.label"
          :error="formName(`tax_context.${field.name}`)"
        >
          <UInput
            :model-value="draft.tax_context?.[field.name] ?? ''"
            class="w-full"
            :disabled="disabled || saving"
            @update:model-value="updateTaxContext(field.name, String($event))"
          />
        </UFormField>
        <UFormField name="tax_context.final_consumer" label="Consumidor final" :error="formName('tax_context.final_consumer')">
          <USelect
            :model-value="draft.tax_context?.final_consumer === true ? 'yes' : draft.tax_context?.final_consumer === false ? 'no' : 'unknown'"
            :items="[{ label: 'Não informado', value: 'unknown' }, { label: 'Sim', value: 'yes' }, { label: 'Não', value: 'no' }]"
            class="w-full"
            :disabled="disabled || saving"
            @update:model-value="updateTaxContext('final_consumer', $event === 'yes' ? true : $event === 'no' ? false : null)"
          />
        </UFormField>
      </div>
      <p
        v-for="(message, field) in errors"
        v-show="String(field).startsWith('party.') || field === 'tax_refresh' || String(field).startsWith('configuration')"
        :key="field"
        class="text-sm text-error"
      >
        {{ message }}
      </p>
    </details>
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
          :model-value="customerSelection"
          :items="contactItems"
          class="w-full"
          :disabled="disabled || saving"
          @update:model-value="selectCustomer($event)"
        />
      </UFormField>
      <UFormField name="currency" label="Moeda" :error="formName('currency')">
        <USelect
          :model-value="currencySelection"
          :items="currencyItems"
          class="w-full"
          :disabled="disabled || saving"
          @update:model-value="selectCurrency($event)"
        />
      </UFormField>
      <UFormField name="erp_operation_nature_id" label="Natureza da operação" :error="formName('erp_operation_nature_id')">
        <USelect
          :model-value="draft.erp_operation_nature_id ?? 0"
          :items="operationNatureItems"
          class="w-full"
          :disabled="disabled || saving || !natureState?.permitted || natureState.loading || !natureState.confirmed || Boolean(natureState.error)"
          @update:model-value="draft.erp_operation_nature_id = selectValue($event)"
        />
        <p v-if="natureState?.loading" class="mt-1 text-xs text-muted">
          Carregando naturezas da operação…
        </p>
        <p v-else-if="!natureState?.permitted" class="mt-1 text-xs text-muted">
          Seu acesso não permite consultar naturezas da operação. O vínculo atual será preservado.
        </p>
        <p v-else-if="natureState.error" class="mt-1 text-xs text-error" role="alert">
          {{ natureState.error }} O vínculo atual será preservado.
        </p>
        <p v-else-if="natureState.confirmed && operationNatureItems.length === 1" class="mt-1 text-xs text-muted">
          Nenhuma natureza ativa disponível para este estabelecimento.
        </p>
      </UFormField>
      <UFormField
        v-if="kind === 'quote'"
        name="quote_no"
        label="Número do orçamento"
        :error="formName('quote_no')"
      >
        <UInput
          v-model="draft.quote_no"
          class="w-full"
          :disabled="disabled || saving"
          :readonly="editing"
        />
      </UFormField>
      <UFormField
        v-else-if="kind === 'order'"
        name="order_no"
        label="Número do pedido"
        :error="formName('order_no')"
      >
        <UInput
          v-model="draft.order_no"
          class="w-full"
          :disabled="disabled || saving"
          :readonly="editing"
        />
      </UFormField>
      <UFormField
        v-else
        name="invoice_no"
        label="Documento de balcão"
        :error="formName('invoice_no')"
      >
        <UInput
          v-model="draft.invoice_no"
          class="w-full"
          :disabled="disabled || saving"
          :readonly="editing"
        />
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
          <ErpCatalogReferenceSelect
            :model-value="line.erp_product_variant_id"
            path="/erp/product-variants"
            :query="variantQuery"
            label="Produto ou serviço"
            label-key="name"
            :label-formatter="formatErpProductVariantLabel"
            :name="`lines.${index}.erp_product_variant_id`"
            :optional="true"
            :disabled="disabled || saving"
            :error="formName(`lines.${index}.erp_product_variant_id`)"
            @selection-record="selectVariant(line, $event)"
          />
          <UFormField :name="`lines.${index}.description`" label="Descrição" :error="formName(`lines.${index}.description`)">
            <UInput v-model="line.description" class="w-full" :disabled="disabled || saving" />
          </UFormField>
          <UFormField :name="`lines.${index}.erp_unit_id`" label="Unidade" :error="formName(`lines.${index}.erp_unit_id`)">
            <USelect
              :model-value="line.erp_unit_id ?? 0"
              :items="unitItems"
              class="w-full"
              :disabled="disabled || saving"
              @update:model-value="selectUnit(line, $event)"
            />
          </UFormField>
          <UFormField
            :name="`lines.${index}.quantity`"
            label="Quantidade"
            required
            :error="formName(`lines.${index}.quantity`)"
          >
            <UInput
              :model-value="line.quantity"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving"
              @update:model-value="setLineAmount(line, 'quantity', String($event ?? ''))"
            />
          </UFormField>
          <UFormField :name="`lines.${index}.discount_amount`" label="Desconto (R$)" :error="formName(`lines.${index}.discount_amount`)">
            <UInput
              :model-value="line.discount_amount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving || !canOverrideDiscount || Boolean(draft.discount || draft.erp_discount_rule_id)"
              @update:model-value="setLineAmount(line, 'discount_amount', String($event ?? ''))"
            />
          </UFormField>
          <UFormField :name="`lines.${index}.tax_amount`" label="Imposto informado (R$)" :error="formName(`lines.${index}.tax_amount`)">
            <UInput
              :model-value="line.tax_amount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving"
              @update:model-value="setLineAmount(line, 'tax_amount', String($event ?? ''))"
            />
          </UFormField>
          <UFormField
            v-if="canOverridePrice || line.unit_price"
            :name="`lines.${index}.unit_price`"
            :label="canOverridePrice ? 'Preço unitário manual (R$)' : 'Preço unitário confirmado (R$)'"
            :error="formName(`lines.${index}.unit_price`)"
          >
            <UInput
              :model-value="line.unit_price"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="w-full"
              :disabled="disabled || saving || !canOverridePrice"
              placeholder="Em branco: usar preço do catálogo"
              @update:model-value="setLineAmount(line, 'unit_price', String($event ?? ''))"
            />
          </UFormField>
          <UButton
            v-if="line.unit_price && line.erp_product_variant_id"
            type="button"
            color="neutral"
            variant="outline"
            size="sm"
            label="Usar preço e unidade atuais do catálogo"
            :disabled="disabled || saving"
            @click="useCurrentCatalog(line)"
          />
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
        color="neutral"
        :loading="saving"
        :disabled="disabled || saving"
        :label="`Salvar ${title.toLocaleLowerCase('pt-BR')}`"
      />
    </div>
  </UForm>
</template>
