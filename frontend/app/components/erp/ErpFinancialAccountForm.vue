<script setup lang="ts">
defineProps<{ busy?: boolean, errors?: Record<string, string>, establishments?: { id: number, name: string }[] }>()
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ name: '', account_type: 'saving_current', currency: 'BRL', erp_establishment_id: 'all' })
const localError = ref('')
function submit() {
  localError.value = ''
  if (!state.name.trim()) {
    localError.value = 'Informe o nome da conta.'
    return
  }
  if (!/^[A-Z]{3}$/.test(state.currency)) {
    localError.value = 'Informe a moeda em três letras maiúsculas.'
    return
  }
  emit('submit', { name: state.name.trim(), account_type: state.account_type, currency: state.currency, erp_establishment_id: state.erp_establishment_id !== 'all' ? Number(state.erp_establishment_id) : null })
}
defineExpose({ state, submit })
</script>

<template>
  <section class="space-y-4 rounded-lg border border-muted bg-elevated p-4" data-finance-form="account">
    <div>
      <h2 class="font-semibold text-highlighted">
        Nova conta financeira
      </h2><p class="text-sm text-muted">
        A conta pertence à empresa ERP selecionada.
      </p>
    </div>
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2" @submit="submit">
      <UFormField
        label="Nome"
        name="name"
        required
        :error="errors?.name"
      >
        <UInput v-model="state.name" autocomplete="off" />
      </UFormField>
      <UFormField
        label="Tipo de conta"
        name="account_type"
        required
        :error="errors?.account_type"
      >
        <USelect v-model="state.account_type" :items="[{ label: 'Conta corrente ou poupança', value: 'saving_current' }, { label: 'Caixa', value: 'cash' }, { label: 'Investimento', value: 'investment' }, { label: 'Outro', value: 'other' }]" />
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
        <USelect v-model="state.erp_establishment_id" :items="[{ label: 'Toda a empresa', value: 'all' }, ...(establishments ?? []).map(row => ({ label: row.name, value: String(row.id) }))]" />
      </UFormField>
      <div class="sm:col-span-2">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy"
          icon="i-lucide-plus"
          label="Criar conta"
        />
      </div>
    </UForm>
  </section>
</template>
