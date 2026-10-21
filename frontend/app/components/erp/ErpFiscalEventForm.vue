<script setup lang="ts">
import type { ErpFiscalDocument } from '#shared/types/erpFiscal'

const props = defineProps<{
  document: ErpFiscalDocument | null
  replacementDocuments?: ErpFiscalDocument[]
  canSubmit: boolean
  busy?: boolean
  fieldErrors?: Record<string, string>
}>()
const emit = defineEmits<{ submit: [eventType: string, fields: Record<string, unknown>, idempotencyKey: string] }>()
const eventType = ref('')
const idempotencyKey = ref(crypto.randomUUID())
const form = reactive({ justification: '', correction: '', legal_scope_attested: false, replacement_document_id: undefined as number | undefined, closed_on: '', closing_uf: '', closing_city_code: '', correction_note: '' })
const error = ref('')
const eventLabels: Record<string, string> = { cancel: 'Cancelamento', correction: 'Carta de correção', substitution_cancel: 'Cancelamento por substituição', close: 'Encerramento do MDF-e' }

watch(() => props.document?.id, () => {
  eventType.value = ''
  idempotencyKey.value = crypto.randomUUID()
  Object.assign(form, { justification: '', correction: '', legal_scope_attested: false, replacement_document_id: undefined, closed_on: '', closing_uf: '', closing_city_code: '', correction_note: '' })
  error.value = ''
})
const supported = computed(() => (props.document?.supported_events ?? []).map(type => ({ label: eventLabels[type] ?? type, value: type })))

function submit() {
  error.value = ''
  if (!props.document || !eventType.value || !props.document.supported_events?.includes(eventType.value)) {
    error.value = 'Selecione um evento permitido para este documento.'
    return
  }
  if (['cancel', 'substitution_cancel'].includes(eventType.value) && (form.justification.trim().length < 15 || form.justification.trim().length > 255)) {
    error.value = 'A justificativa deve ter entre 15 e 255 caracteres.'
    return
  }
  if (eventType.value === 'correction' && props.document.model === '55' && (form.correction.trim().length < 15 || form.correction.trim().length > 1000 || form.legal_scope_attested !== true)) {
    error.value = 'Informe uma carta de correção de 15 a 1000 caracteres e confirme o limite legal do evento.'
    return
  }
  if (eventType.value === 'correction' && props.document.model === '57' && (!form.correction_note.trim() || form.correction_note.trim().length > 500 || form.legal_scope_attested !== true)) {
    error.value = 'Informe a observação complementar e confirme o limite legal da correção do CT-e.'
    return
  }
  if (eventType.value === 'substitution_cancel' && !form.replacement_document_id) {
    error.value = 'Selecione a NFC-e substituta autorizada.'
    return
  }
  if (eventType.value === 'close' && (!/^\d{4}-\d{2}-\d{2}$/.test(form.closed_on) || !/^[A-Z]{2}$/.test(form.closing_uf) || !/^\d{7}$/.test(form.closing_city_code))) {
    error.value = 'Informe a data, a UF e o código IBGE válidos para o encerramento.'
    return
  }
  const fields: Record<string, unknown> = {}
  if (['cancel', 'substitution_cancel'].includes(eventType.value)) fields.justification = form.justification
  if (eventType.value === 'correction' && props.document.model === '55') {
    fields.correction = form.correction
    fields.legal_scope_attested = form.legal_scope_attested
  } else if (eventType.value === 'correction' && props.document.model === '57') {
    fields.legal_scope_attested = form.legal_scope_attested
    fields.corrections = [{ group: 'compl', field: 'xObs', value: form.correction_note }]
  }
  if (eventType.value === 'substitution_cancel') fields.replacement_document_id = Number(form.replacement_document_id)
  if (eventType.value === 'close') Object.assign(fields, { closed_on: form.closed_on, closing_uf: form.closing_uf, closing_city_code: form.closing_city_code })
  emit('submit', eventType.value, fields, idempotencyKey.value)
}
</script>

<template>
  <UForm :state="form" class="space-y-4" @submit="submit">
    <p v-if="document" class="text-sm text-muted">
      Os eventos permitidos vêm do estado atual do documento. Uma rejeição do evento não altera a autorização fiscal do documento.
    </p>
    <UFormField label="Evento" name="event_type" :error="fieldErrors?.event_type">
      <USelect
        v-model="eventType"
        :items="supported"
        value-key="value"
        label-key="label"
        placeholder="Selecione um evento compatível"
        :disabled="!document || busy"
      />
    </UFormField>
    <template v-if="['cancel', 'substitution_cancel'].includes(eventType)">
      <UFormField label="Justificativa (15 a 255 caracteres)" name="justification" :error="fieldErrors?.justification">
        <UTextarea v-model="form.justification" :rows="3" :maxlength="255" />
      </UFormField>
    </template>
    <template v-if="eventType === 'correction' && document?.model === '55'">
      <UFormField label="Texto da carta de correção (15 a 1000 caracteres)" name="correction" :error="fieldErrors?.correction">
        <UTextarea v-model="form.correction" :rows="4" :maxlength="1000" />
      </UFormField>
      <UCheckbox v-model="form.legal_scope_attested" label="Confirmo que o texto não altera variáveis determinantes do imposto, base de cálculo, alíquota, dados cadastrais que impliquem mudança do remetente ou destinatário, nem a data de emissão/saída." />
    </template>
    <template v-if="eventType === 'correction' && document?.model === '57'">
      <UFormField label="Observação complementar do CT-e" name="corrections.0.value" :error="fieldErrors?.corrections">
        <UTextarea v-model="form.correction_note" :rows="3" maxlength="500" />
      </UFormField>
      <UCheckbox v-model="form.legal_scope_attested" label="Confirmo que a correção altera somente observação complementar, sem mudança tributária ou de participantes." />
    </template>
    <template v-if="eventType === 'substitution_cancel'">
      <UFormField label="NFC-e de substituição autorizada" name="replacement_document_id" :error="fieldErrors?.replacement_document_id">
        <USelect
          v-model="form.replacement_document_id"
          :items="(replacementDocuments ?? []).filter(row => row.model === '65' && row.status === 'authorized' && row.id !== document?.id).map(row => ({ label: `${row.access_key ?? `Documento #${row.id}`}`, value: row.id }))"
          value-key="value"
          label-key="label"
          placeholder="Selecione o documento substituto"
        />
      </UFormField>
    </template>
    <template v-if="eventType === 'close'">
      <div class="grid gap-3 sm:grid-cols-3">
        <UFormField label="Data de encerramento" name="closed_on" :error="fieldErrors?.closed_on">
          <UInput v-model="form.closed_on" type="date" />
        </UFormField>
        <UFormField label="UF de encerramento" name="closing_uf" :error="fieldErrors?.closing_uf">
          <UInput v-model="form.closing_uf" maxlength="2" />
        </UFormField>
        <UFormField label="Código IBGE do município" name="closing_city_code" :error="fieldErrors?.closing_city_code">
          <UInput v-model="form.closing_city_code" inputmode="numeric" maxlength="7" />
        </UFormField>
      </div>
    </template>
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :description="error"
    />
    <UAlert
      v-if="fieldErrors?.idempotency_key"
      color="error"
      variant="subtle"
      :description="fieldErrors.idempotency_key"
    />
    <div class="flex justify-end">
      <UButton type="submit" :disabled="!canSubmit || !document || !eventType || busy" :loading="busy">
        Registrar evento fiscal
      </UButton>
    </div>
  </UForm>
</template>
