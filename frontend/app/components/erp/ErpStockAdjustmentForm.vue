<script setup lang="ts">
import type { ErpStockEstablishment, ErpStockVariant } from '#shared/types/erpBusiness'
import { buildErpStockAdjustmentPayload } from '#shared/utils/erpStock'

const props = withDefaults(defineProps<{
  establishments: ErpStockEstablishment[]
  variants: ErpStockVariant[]
  busy?: boolean
  locked?: boolean
  errors?: Record<string, string>
}>(), { busy: false, locked: false, errors: () => ({}) })
const emit = defineEmits<{ submit: [payload: { erp_establishment_id: string, erp_product_variant_id: string, quantity_delta: string, reason: string }] }>()
const state = reactive({ erp_establishment_id: '', erp_product_variant_id: '', quantity_delta: '', reason: '' })
const localError = ref('')

function submit() {
  localError.value = ''
  try {
    const payload = buildErpStockAdjustmentPayload(state)
    emit('submit', {
      erp_establishment_id: String(payload.erp_establishment_id),
      erp_product_variant_id: String(payload.erp_product_variant_id),
      quantity_delta: payload.quantity_delta,
      reason: payload.reason
    })
  } catch (error) {
    localError.value = error instanceof Error ? error.message : 'Revise a diferença e o motivo.'
  }
}

defineExpose({ state, submit })
</script>

<template>
  <div class="space-y-4" data-stock-adjustment-form>
    <p class="text-sm text-muted">
      Use um valor positivo para entrada e negativo para saída. Uma saída não pode exceder o saldo disponível.
    </p>
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2" @submit="submit">
      <UFormField
        label="Estabelecimento"
        name="erp_establishment_id"
        required
        :error="props.errors.erp_establishment_id"
      >
        <USelect v-model="state.erp_establishment_id" :disabled="props.locked" :items="props.establishments.map(row => ({ label: row.name, value: String(row.id) }))" />
      </UFormField>
      <UFormField
        label="Produto / variante"
        name="erp_product_variant_id"
        required
        :error="props.errors.erp_product_variant_id"
      >
        <USelect v-model="state.erp_product_variant_id" :disabled="props.locked" :items="props.variants.map(row => ({ label: `${row.product.name} · ${row.name}${row.sku ? ` · ${row.sku}` : ''}`, value: String(row.id) }))" />
      </UFormField>
      <UFormField
        label="Diferença de quantidade"
        name="quantity_delta"
        required
        description="Até quatro casas decimais."
        :error="props.errors.quantity_delta"
      >
        <UInput
          v-model="state.quantity_delta"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          :disabled="props.locked"
        />
      </UFormField>
      <UFormField
        label="Motivo"
        name="reason"
        required
        :error="props.errors.reason"
        class="sm:col-span-2"
      >
        <UTextarea
          v-model="state.reason"
          :rows="3"
          maxlength="255"
          :disabled="props.locked"
        />
      </UFormField>
      <div class="flex justify-end sm:col-span-2">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy || props.locked"
          label="Registrar ajuste"
        />
      </div>
    </UForm>
  </div>
</template>
