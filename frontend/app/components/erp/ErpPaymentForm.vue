<script setup lang="ts">
import { decimalTextIsValid } from '#shared/utils/erpFinance'

const props = withDefaults(defineProps<{ busy?: boolean, errors?: Record<string, string>, establishments?: { id: number, name: string }[], accounts?: { id: number, name: string, currency: string, erp_establishment_id: number | null }[], sessions?: { id: number, currency: string, erp_establishment_id: number | null }[] }>(), { establishments: () => [], accounts: () => [], sessions: () => [] })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ direction: 'in', amount: '', method: 'bank_transfer', currency: 'BRL', erp_establishment_id: 'all', erp_financial_account_id: 'none', erp_cash_session_id: 'none' })
const localError = ref('')
const sessions = computed(() => props.sessions.filter(session => session.currency === state.currency && (session.erp_establishment_id === null || String(session.erp_establishment_id) === state.erp_establishment_id)))
function submit() {
  localError.value = ''
  if (!decimalTextIsValid(state.amount)) {
    localError.value = 'Informe um valor positivo com até quatro casas decimais.'
    return
  }
  if (!/^[A-Z]{3}$/.test(state.currency)) {
    localError.value = 'Informe a moeda em três letras maiúsculas.'
    return
  }
  if (state.method === 'cash' && state.erp_cash_session_id === 'none') {
    localError.value = 'Selecione explicitamente um caixa aberto para pagamento em dinheiro.'
    return
  }
  emit('submit', {
    direction: state.direction,
    amount: state.amount,
    method: state.method,
    currency: state.currency,
    erp_establishment_id: state.erp_establishment_id !== 'all' ? Number(state.erp_establishment_id) : null,
    erp_financial_account_id: state.erp_financial_account_id !== 'none' ? Number(state.erp_financial_account_id) : null,
    erp_cash_session_id: state.method === 'cash' && state.erp_cash_session_id !== 'none' ? Number(state.erp_cash_session_id) : null
  })
}
defineExpose({ state, submit, localError })
</script>

<template>
  <section class="space-y-4" data-finance-form="payment">
    <p class="text-sm text-muted">
      Este registro não envia cobrança nem inicia transação bancária.
    </p>
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" @submit="submit">
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
        description="Até quatro casas decimais."
        :error="errors?.amount"
      >
        <UInput
          v-model="state.amount"
          type="text"
          inputmode="decimal"
          autocomplete="off"
        />
      </UFormField>
      <UFormField
        label="Método"
        name="method"
        required
        :error="errors?.method"
      >
        <USelect v-model="state.method" :items="[{ label: 'Transferência', value: 'bank_transfer' }, { label: 'Cartão', value: 'card' }, { label: 'Dinheiro', value: 'cash' }, { label: 'Outro', value: 'other' }]" />
      </UFormField>
      <UFormField
        label="Moeda"
        name="currency"
        required
        :error="errors?.currency"
      >
        <UInput v-model="state.currency" maxlength="3" autocapitalize="characters" />
      </UFormField>
      <UFormField label="Estabelecimento" name="erp_establishment_id" :error="errors?.erp_establishment_id">
        <USelect v-model="state.erp_establishment_id" :items="[{ label: 'Toda a empresa', value: 'all' }, ...props.establishments.map(row => ({ label: row.name, value: String(row.id) }))]" />
      </UFormField>
      <UFormField label="Conta financeira" name="erp_financial_account_id" :error="errors?.erp_financial_account_id">
        <USelect v-model="state.erp_financial_account_id" :items="[{ label: 'Sem vínculo de conta', value: 'none' }, ...props.accounts.filter(account => account.currency === state.currency && (account.erp_establishment_id === null || String(account.erp_establishment_id) === state.erp_establishment_id)).map(account => ({ label: account.name, value: String(account.id) }))]" />
      </UFormField>
      <UFormField
        v-if="state.method === 'cash'"
        label="Caixa aberto"
        name="erp_cash_session_id"
        required
        :error="errors?.erp_cash_session_id"
      >
        <USelect v-model="state.erp_cash_session_id" :items="[{ label: 'Selecione um caixa', value: 'none' }, ...sessions.map(session => ({ label: `Caixa #${session.id} · ${session.currency}`, value: String(session.id) }))]" />
      </UFormField>
      <div class="flex items-end">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy"
          icon="i-lucide-plus"
          label="Registrar pagamento"
        />
      </div>
    </UForm>
  </section>
</template>
