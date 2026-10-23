<script setup lang="ts">
import { decimalTextIsValid } from '#shared/utils/erpFinance'

const props = withDefaults(defineProps<{ busy?: boolean, errors?: Record<string, string>, establishments?: { id: number, name: string }[], accounts?: { id: number, name: string, currency: string, erp_establishment_id: number | null }[] }>(), { establishments: () => [], accounts: () => [] })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ direction: 'receivable', category: '', amount: '', currency: 'BRL', due_on: '', erp_establishment_id: 'all', erp_financial_account_id: 'none' })
const localError = ref('')
function submit() {
  localError.value = ''
  if (!state.category.trim()) {
    localError.value = 'Informe a categoria da obrigação.'
    return
  }
  if (!decimalTextIsValid(state.amount)) {
    localError.value = 'Informe um valor positivo com até quatro casas decimais.'
    return
  }
  if (!/^[A-Z]{3}$/.test(state.currency)) {
    localError.value = 'Informe a moeda em três letras maiúsculas.'
    return
  }
  emit('submit', {
    direction: state.direction,
    category: state.category.trim(),
    amount: state.amount,
    currency: state.currency,
    due_on: state.due_on || null,
    erp_establishment_id: state.erp_establishment_id !== 'all' ? Number(state.erp_establishment_id) : null,
    erp_financial_account_id: state.erp_financial_account_id !== 'none' ? Number(state.erp_financial_account_id) : null
  })
}
defineExpose({ state, submit })
</script>

<template>
  <section class="space-y-4" data-finance-form="obligation">
    <p class="text-sm text-muted">
      Lançamento manual sem cobrança automática ou movimento bancário.
    </p>
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" @submit="submit">
      <UFormField
        label="Natureza"
        name="direction"
        required
        :error="errors?.direction"
      >
        <USelect v-model="state.direction" :items="[{ label: 'A receber', value: 'receivable' }, { label: 'A pagar', value: 'payable' }]" />
      </UFormField>
      <UFormField
        label="Categoria"
        name="category"
        required
        :error="errors?.category"
      >
        <UInput v-model="state.category" maxlength="32" />
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
        label="Moeda"
        name="currency"
        required
        :error="errors?.currency"
      >
        <UInput v-model="state.currency" maxlength="3" autocapitalize="characters" />
      </UFormField>
      <UFormField label="Vencimento" name="due_on" :error="errors?.due_on">
        <UInput v-model="state.due_on" type="date" />
      </UFormField>
      <UFormField label="Estabelecimento" name="erp_establishment_id" :error="errors?.erp_establishment_id">
        <USelect v-model="state.erp_establishment_id" :items="[{ label: 'Toda a empresa', value: 'all' }, ...props.establishments.map(row => ({ label: row.name, value: String(row.id) }))]" />
      </UFormField>
      <UFormField label="Conta financeira" name="erp_financial_account_id" :error="errors?.erp_financial_account_id">
        <USelect v-model="state.erp_financial_account_id" :items="[{ label: 'Sem vínculo de conta', value: 'none' }, ...props.accounts.filter(account => account.currency === state.currency && (account.erp_establishment_id === null || String(account.erp_establishment_id) === state.erp_establishment_id)).map(account => ({ label: account.name, value: String(account.id) }))]" />
      </UFormField>
      <div class="flex items-end">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy"
          icon="i-lucide-plus"
          label="Criar obrigação"
        />
      </div>
    </UForm>
  </section>
</template>
