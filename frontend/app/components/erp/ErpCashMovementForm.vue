<script setup lang="ts">
import { decimalTextIsValid } from '#shared/utils/erpFinance'

withDefaults(defineProps<{ busy?: boolean, errors?: Record<string, string> }>(), { busy: false, errors: () => ({}) })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ kind: 'deposit' as 'deposit' | 'withdrawal', amount: '', reason: '' })
const localError = ref('')
function submit() {
  localError.value = ''
  if (!decimalTextIsValid(state.amount)) {
    localError.value = 'Informe um valor positivo com até quatro casas decimais.'
    return
  }
  if (!state.reason.trim()) {
    localError.value = 'Informe o motivo do movimento.'
    return
  }
  emit('submit', { direction: state.kind === 'deposit' ? 'in' : 'out', kind: state.kind, amount: state.amount, reason: state.reason.trim() })
}
defineExpose({ state, submit })
</script>

<template>
  <section data-cash-movement-form class="space-y-4">
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2" @submit="submit">
      <UFormField
        label="Movimento"
        name="kind"
        required
        :error="errors?.kind"
      >
        <USelect v-model="state.kind" :items="[{ label: 'Depósito', value: 'deposit' }, { label: 'Retirada', value: 'withdrawal' }]" />
      </UFormField>
      <UFormField
        label="Valor"
        name="amount"
        required
        description="Valor positivo, até quatro casas decimais."
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
        label="Motivo"
        name="reason"
        required
        :error="errors?.reason"
        class="sm:col-span-2"
      >
        <UTextarea v-model="state.reason" :rows="2" maxlength="500" />
      </UFormField>
      <div class="flex items-end">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy"
          label="Registrar movimento"
        />
      </div>
    </UForm>
  </section>
</template>
