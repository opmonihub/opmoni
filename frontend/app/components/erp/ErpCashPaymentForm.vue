<script setup lang="ts">
import type { ErpCashSession } from '#shared/types/erpBusiness'
import { decimalTextIsValid } from '#shared/utils/erpFinance'

const props = withDefaults(defineProps<{ session: ErpCashSession, accounts?: { id: number, name: string, currency: string, erp_establishment_id: number | null }[], busy?: boolean, errors?: Record<string, string> }>(), { accounts: () => [], busy: false, errors: () => ({}) })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ direction: 'in', amount: '', erp_financial_account_id: 'none' })
const localError = ref('')
function submit() {
  localError.value = ''
  if (!decimalTextIsValid(state.amount)) {
    localError.value = 'Informe um valor positivo com até quatro casas decimais.'
    return
  }
  if (!props.session.currency || props.session.needs_reconciliation || !props.session.can_operate || props.session.status !== 'open') {
    localError.value = 'Esta sessão não pode receber pagamentos.'
    return
  }
  const accountId = state.erp_financial_account_id === 'none' ? null : Number(state.erp_financial_account_id)
  emit('submit', {
    direction: state.direction,
    amount: state.amount,
    method: 'cash',
    currency: props.session.currency,
    erp_cash_session_id: props.session.id,
    erp_establishment_id: props.session.erp_establishment_id,
    erp_financial_account_id: accountId
  })
}
defineExpose({ state, submit })
</script>

<template>
  <section data-cash-payment-form class="space-y-4">
    <p class="text-sm text-muted">
      O pagamento será registrado no caixa selecionado. Nenhuma cobrança ou transação bancária será iniciada.
    </p>
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2" @submit="submit">
      <UFormField
        label="Direção"
        name="direction"
        required
        :error="errors?.direction"
      >
        <USelect v-model="state.direction" :items="[{ label: 'Entrada', value: 'in' }, { label: 'Saída', value: 'out' }]" />
      </UFormField>
      <UFormField
        label="Valor"
        name="amount"
        required
        :error="errors?.amount"
      >
        <UInput
          v-model="state.amount"
          type="text"
          inputmode="decimal"
          autocomplete="off"
        />
      </UFormField>
      <UFormField label="Conta financeira" name="erp_financial_account_id" :error="errors?.erp_financial_account_id">
        <USelect v-model="state.erp_financial_account_id" :items="[{ label: 'Sem vínculo de conta', value: 'none' }, ...props.accounts.filter(account => account.currency === props.session.currency && (account.erp_establishment_id === null || account.erp_establishment_id === props.session.erp_establishment_id)).map(account => ({ label: account.name, value: String(account.id) }))]" />
      </UFormField>
      <div class="flex items-end">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy"
          label="Registrar pagamento em dinheiro"
        />
      </div>
    </UForm>
  </section>
</template>
