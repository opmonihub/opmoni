<script setup lang="ts">
import { parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'

const props = withDefaults(defineProps<{
  direction: 'debit' | 'credit'
  correctsAdjustmentId?: number | null
  initialAmount?: string
  initialReason?: string
  errors?: Record<string, string[]>
  error?: string
  busy?: boolean
  unknown?: boolean
  recoveryKey?: string | null
}>(), {
  initialAmount: '',
  initialReason: '',
  errors: () => ({}),
  error: '',
  busy: false,
  unknown: false,
  recoveryKey: null
})

const emit = defineEmits<{
  submit: [payload: { direction: 'debit' | 'credit', amount: string, reason: string, corrects_adjustment_id?: number }]
  recover: [key: string]
  cancel: []
}>()

const amount = ref(props.initialAmount)
const reason = ref(props.initialReason)
const localError = ref('')
const locked = computed(() => props.busy || props.unknown)

function submit() {
  localError.value = ''
  if (locked.value) return
  if (!reason.value.trim()) {
    localError.value = 'Informe o motivo do ajuste.'
    return
  }
  try {
    const canonicalAmount = parseOfficeErpMoney(amount.value)
    if (canonicalAmount === '0.00') {
      localError.value = 'Informe um valor maior que zero.'
      return
    }
    const payload: { direction: 'debit' | 'credit', amount: string, reason: string, corrects_adjustment_id?: number } = {
      direction: props.direction,
      amount: canonicalAmount,
      reason: reason.value.trim()
    }
    if (props.correctsAdjustmentId !== undefined && props.correctsAdjustmentId !== null)
      payload.corrects_adjustment_id = props.correctsAdjustmentId
    emit('submit', payload)
  } catch {
    localError.value = 'Informe um valor válido com até duas casas decimais.'
  }
}
</script>

<template>
  <section class="space-y-4" data-office-erp-adjustment-form>
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
    />
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <div v-if="unknown" class="space-y-3" role="status">
      <p class="text-sm text-muted">
        A confirmação do ajuste está pendente. Consulte a operação antes de tentar novamente.
      </p>
      <UButton
        v-if="recoveryKey"
        type="button"
        variant="outline"
        label="Consultar operação"
        :disabled="busy"
        @click="emit('recover', recoveryKey!)"
      />
    </div>
    <UForm
      v-else
      :state="{ amount, reason }"
      class="grid gap-3 sm:grid-cols-2"
      @submit.prevent="submit"
    >
      <UFormField label="Direção do ajuste" name="direction" required>
        <USelect :model-value="direction" :items="[{ label: 'Acréscimo', value: 'debit' }, { label: 'Crédito', value: 'credit' }]" disabled />
      </UFormField>
      <UFormField
        label="Valor do ajuste"
        name="amount"
        required
        :error="props.errors.amount?.[0]"
      >
        <UInput
          v-model="amount"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          aria-label="Valor do ajuste"
          :disabled="locked"
        />
      </UFormField>
      <UFormField
        label="Motivo"
        name="reason"
        required
        :error="props.errors.reason?.[0]"
        class="sm:col-span-2"
      >
        <UTextarea
          v-model="reason"
          aria-label="Motivo"
          :rows="3"
          maxlength="2000"
          :disabled="locked"
        />
      </UFormField>
      <div class="flex justify-end gap-2 sm:col-span-2">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          label="Cancelar"
          :disabled="busy"
          @click="emit('cancel')"
        />
        <UButton
          type="submit"
          :loading="busy"
          :disabled="locked"
          :label="direction === 'credit' ? 'Registrar crédito' : 'Registrar acréscimo'"
        />
      </div>
    </UForm>
  </section>
</template>
