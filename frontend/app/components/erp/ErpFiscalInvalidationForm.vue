<script setup lang="ts">
import type { ErpFiscalConfiguration, ErpFiscalModel } from '#shared/types/erpFiscal'

const props = defineProps<{ configuration: ErpFiscalConfiguration | null, canSubmit: boolean, busy?: boolean, fieldErrors?: Record<string, string> }>()
const emit = defineEmits<{ submit: [body: Record<string, unknown>, idempotencyKey: string] }>()
const form = reactive({ model: '55' as '55' | '65', year: String(new Date().getFullYear()), series: '', number_from: '', number_to: '', justification: '' })
const idempotencyKey = ref(crypto.randomUUID())
const error = ref('')
const availableModels = computed(() => (['55', '65'] as ErpFiscalModel[]).filter(model => props.configuration?.models[model]))

function submit() {
  error.value = ''
  const from = Number(form.number_from)
  const to = Number(form.number_to)
  const series = Number(form.series)
  const currentYear = new Date().getFullYear()
  const year = Number(form.year)
  if (!props.configuration || !availableModels.value.includes(form.model) || !Number.isInteger(year) || year !== currentYear || form.series === '' || !Number.isInteger(series) || series < 0 || series > 999 || !Number.isInteger(from) || !Number.isInteger(to) || from < 1 || to < from || to > 999999999 || form.justification.trim().length < 15 || form.justification.trim().length > 255) {
    error.value = 'Informe o ano atual, modelo e série configurados, uma faixa crescente e uma justificativa de 15 a 255 caracteres.'
    return
  }
  const body = {
    erp_establishment_id: props.configuration.establishment.id,
    model: form.model,
    year,
    environment: props.configuration.environment,
    series,
    number_from: from,
    number_to: to,
    justification: form.justification,
    idempotency_key: idempotencyKey.value
  }
  emit('submit', body, idempotencyKey.value)
}
</script>

<template>
  <UForm :state="form" class="space-y-4" @submit="submit">
    <p class="text-sm text-muted">
      Inutilize apenas números fiscais efetivamente não utilizados no ano e série informados. A solicitação fica sujeita à validação do provedor.
    </p>
    <div class="grid gap-3 sm:grid-cols-2">
      <UFormField label="Modelo" name="model" :error="fieldErrors?.model">
        <USelect
          v-model="form.model"
          :items="availableModels.map(model => ({ label: model === '55' ? 'NF-e (55)' : 'NFC-e (65)', value: model }))"
          value-key="value"
          label-key="label"
          :disabled="busy"
        />
      </UFormField>
      <UFormField label="Ano atual" name="year" :error="fieldErrors?.year">
        <UInput v-model="form.year" inputmode="numeric" :disabled="busy" />
      </UFormField>
      <UFormField label="Série" name="series" :error="fieldErrors?.series">
        <UInput v-model="form.series" inputmode="numeric" :placeholder="String(configuration?.models[form.model]?.series ?? '')" />
      </UFormField>
      <div class="grid grid-cols-2 gap-3">
        <UFormField label="Número inicial" name="number_from" :error="fieldErrors?.number_from">
          <UInput v-model="form.number_from" inputmode="numeric" />
        </UFormField>
        <UFormField label="Número final" name="number_to" :error="fieldErrors?.number_to">
          <UInput v-model="form.number_to" inputmode="numeric" />
        </UFormField>
      </div>
    </div>
    <UFormField label="Justificativa (15 a 255 caracteres)" name="justification" :error="fieldErrors?.justification">
      <UTextarea v-model="form.justification" :rows="3" maxlength="255" />
    </UFormField>
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :description="error"
    />
    <div class="flex justify-end">
      <UButton type="submit" :disabled="!canSubmit || busy || !configuration" :loading="busy">
        Solicitar inutilização
      </UButton>
    </div>
  </UForm>
</template>
