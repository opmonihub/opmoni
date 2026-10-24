<script setup lang="ts">
import { decimalTextIsValid } from '#shared/utils/erpFinance'
import { erpFinancialChoiceError, retainedChoiceItem } from '~/utils/erpFinancialChoiceCompatibility'

const props = withDefaults(defineProps<{ busy?: boolean, errors?: Record<string, string>, referencesCurrent?: boolean, establishments?: { id: number, name: string, status?: string }[], accounts?: { id: number, name: string, currency: string, erp_establishment_id: number | null, status?: string }[], sessions?: { id: number, currency: string | null, erp_establishment_id: number | null, status?: string, can_operate?: boolean, needs_reconciliation?: boolean }[] }>(), { referencesCurrent: true, establishments: () => [], accounts: () => [], sessions: () => [] })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ direction: 'in', amount: '', method: 'bank_transfer', currency: 'BRL', erp_establishment_id: 'all', erp_financial_account_id: 'none', erp_cash_session_id: 'none' })
const localError = ref('')
const compatibleSessions = computed(() => props.sessions.filter(session => session.currency === state.currency && (state.erp_establishment_id === 'all' || session.erp_establishment_id === null || String(session.erp_establishment_id) === state.erp_establishment_id)))
const compatibleAccounts = computed(() => props.accounts.filter(account => (account.status === undefined || account.status === 'active') && account.currency === state.currency && (account.erp_establishment_id === null || String(account.erp_establishment_id) === state.erp_establishment_id)))
const availableEstablishments = computed(() => props.establishments.filter(establishment => establishment.status !== 'inactive'))
const establishmentItems = computed(() => retainedChoiceItem(availableEstablishments.value, state.erp_establishment_id, establishment => establishment.name, id => `Estabelecimento #${id} (seleção indisponível)`))
const accountItems = computed(() => retainedChoiceItem(compatibleAccounts.value, state.erp_financial_account_id, account => account.name, id => `Conta #${id} (seleção incompatível)`))
const sessionItems = computed(() => retainedChoiceItem(compatibleSessions.value, state.erp_cash_session_id, session => `Caixa #${session.id} · ${session.currency ?? 'moeda indisponível'}`, id => `Caixa #${id} (seleção incompatível)`))
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
  const compatibilityError = erpFinancialChoiceError({
    currency: state.currency,
    establishmentSelected: state.erp_establishment_id !== 'all',
    establishmentId: state.erp_establishment_id !== 'all' ? Number(state.erp_establishment_id) : null,
    accountId: state.erp_financial_account_id !== 'none' ? Number(state.erp_financial_account_id) : null,
    sessionId: state.erp_cash_session_id !== 'none' ? Number(state.erp_cash_session_id) : null,
    method: state.method,
    establishments: props.establishments,
    accounts: props.accounts,
    sessions: props.sessions,
    referencesCurrent: props.referencesCurrent
  })
  if (compatibilityError) {
    localError.value = compatibilityError
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
defineExpose({ state, submit, localError, establishmentItems, accountItems, sessionItems })
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
        <USelect v-model="state.erp_establishment_id" :items="[{ label: 'Toda a empresa', value: 'all' }, ...establishmentItems]" />
      </UFormField>
      <UFormField label="Conta financeira" name="erp_financial_account_id" :error="errors?.erp_financial_account_id">
        <USelect v-model="state.erp_financial_account_id" :items="[{ label: 'Sem vínculo de conta', value: 'none' }, ...accountItems]" />
      </UFormField>
      <UFormField
        v-if="state.method === 'cash' || state.erp_cash_session_id !== 'none'"
        label="Caixa aberto"
        name="erp_cash_session_id"
        required
        :error="errors?.erp_cash_session_id"
      >
        <USelect v-model="state.erp_cash_session_id" :items="[{ label: 'Selecione um caixa', value: 'none' }, ...sessionItems]" />
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
