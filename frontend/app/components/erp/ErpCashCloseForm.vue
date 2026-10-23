<script setup lang="ts">
import type { ErpCashSession } from '#shared/types/erpBusiness'
import { decimalTextIsValid } from '#shared/utils/erpFinance'

const props = withDefaults(defineProps<{ session: ErpCashSession, busy?: boolean, errors?: Record<string, string> }>(), { busy: false, errors: () => ({}) })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ declared_closing_amount: '', confirmed: false })
const localError = ref('')
const zero = (value: string) => /^0+(?:\.0{1,4})?$/.test(value)
function submit() {
  localError.value = ''
  if (!(decimalTextIsValid(state.declared_closing_amount) || zero(state.declared_closing_amount))) {
    localError.value = 'Informe o valor contado, incluindo zero, com até quatro casas decimais.'
    return
  }
  if (!state.confirmed) {
    localError.value = 'Confirme a contagem física antes de fechar a sessão.'
    return
  }
  emit('submit', { declared_closing_amount: state.declared_closing_amount })
}
function clear() {
  state.declared_closing_amount = ''
  state.confirmed = false
  localError.value = ''
}
defineExpose({ state, submit, clear })
</script>

<template>
  <section data-cash-close-form class="space-y-4">
    <p class="text-sm text-muted">
      O sistema compara sua contagem com o saldo calculado pelos movimentos confirmados.
    </p>
    <p v-if="props.session.currency && props.session.expected_balance !== null" class="text-sm text-muted">
      Saldo esperado: {{ props.session.expected_balance }} {{ props.session.currency }}
    </p>
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="space-y-3" @submit="submit">
      <UFormField
        label="Valor contado"
        name="declared_closing_amount"
        required
        :error="errors?.declared_closing_amount"
      >
        <UInput
          v-model="state.declared_closing_amount"
          type="text"
          inputmode="decimal"
          autocomplete="off"
        />
      </UFormField>
      <UCheckbox v-model="state.confirmed" label="Confirmo que conferi fisicamente o dinheiro deste caixa." />
      <UButton
        type="submit"
        :loading="busy"
        :disabled="busy || !props.session.can_operate || props.session.status !== 'open' || props.session.needs_reconciliation"
        color="primary"
        label="Confirmar fechamento"
      />
    </UForm>
  </section>
</template>
