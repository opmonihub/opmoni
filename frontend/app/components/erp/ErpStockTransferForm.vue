<script setup lang="ts">
import type { ErpStockEstablishment, ErpStockVariant } from '#shared/types/erpBusiness'
import { buildErpStockTransferPayload } from '#shared/utils/erpStock'

const props = withDefaults(defineProps<{
  establishments: ErpStockEstablishment[]
  variants: ErpStockVariant[]
  busy?: boolean
  locked?: boolean
  errors?: Record<string, string>
}>(), { busy: false, locked: false, errors: () => ({}) })
const emit = defineEmits<{ submit: [payload: { from_establishment_id: string, to_establishment_id: string, erp_product_variant_id: string, quantity: string }] }>()
const state = reactive({ from_establishment_id: '', to_establishment_id: '', erp_product_variant_id: '', quantity: '' })
const localError = ref('')
const fromOptions = computed(() => props.establishments.map(row => ({ label: row.name, value: String(row.id) })))
const toOptions = computed(() => props.establishments.filter(row => String(row.id) !== state.from_establishment_id).map(row => ({ label: row.name, value: String(row.id) })))

watch(() => state.from_establishment_id, (next) => {
  if (state.to_establishment_id === next) state.to_establishment_id = ''
})

function submit() {
  localError.value = ''
  try {
    const payload = buildErpStockTransferPayload(state)
    emit('submit', {
      from_establishment_id: String(payload.from_establishment_id),
      to_establishment_id: String(payload.to_establishment_id),
      erp_product_variant_id: String(payload.erp_product_variant_id),
      quantity: payload.quantity
    })
  } catch (error) {
    localError.value = error instanceof Error ? error.message : 'Revise os estabelecimentos e a quantidade.'
  }
}

defineExpose({ state, submit })
</script>

<template>
  <div class="space-y-4" data-stock-transfer-form>
    <p class="text-sm text-muted">
      A transferência cria as duas movimentações juntas e conserva a origem das camadas de custo.
    </p>
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2" @submit="submit">
      <UFormField
        label="Estabelecimento de origem"
        name="from_establishment_id"
        required
        :error="props.errors.from_establishment_id"
      >
        <USelect v-model="state.from_establishment_id" :disabled="props.locked" :items="fromOptions" />
      </UFormField>
      <UFormField
        label="Estabelecimento de destino"
        name="to_establishment_id"
        required
        :error="props.errors.to_establishment_id"
      >
        <USelect v-model="state.to_establishment_id" :disabled="props.locked" :items="toOptions" />
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
        label="Quantidade"
        name="quantity"
        required
        description="Quantidade positiva, até quatro casas decimais."
        :error="props.errors.quantity"
      >
        <UInput
          v-model="state.quantity"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          :disabled="props.locked"
        />
      </UFormField>
      <div class="flex justify-end sm:col-span-2">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy || props.locked"
          label="Transferir estoque"
        />
      </div>
    </UForm>
  </div>
</template>
