<script setup lang="ts">
import type { ErpFiscalCertificate } from '#shared/types/erpFiscal'

const props = defineProps<{
  mode: 'upload' | 'rotate'
  establishments: Array<{ id: number, name: string }>
  establishmentId?: number | null
  certificate?: ErpFiscalCertificate | null
  canWrite: boolean
  busy?: boolean
  fieldErrors?: Record<string, string>
}>()

const emit = defineEmits<{
  submit: [value: { label: string, establishmentId: number, certificate: File, password: string, certificateId?: number }]
}>()

const label = ref('')
const chosenEstablishmentId = ref<number | undefined>(undefined)
const certificateFile = ref<File | null>(null)
const password = ref('')
const localError = ref('')

function submit() {
  localError.value = ''
  if (!certificateFile.value) {
    localError.value = 'Selecione um arquivo .p12 ou .pfx.'
    return
  }
  if (certificateFile.value.size > 2 * 1024 * 1024) {
    localError.value = 'O certificado deve ter até 2 MiB.'
    return
  }
  const establishmentId = props.mode === 'rotate' ? props.certificate?.erp_establishment_id : (props.establishmentId ?? chosenEstablishmentId.value)
  if (!establishmentId) {
    localError.value = 'Selecione o estabelecimento emissor.'
    return
  }
  emit('submit', {
    label: props.mode === 'rotate' ? (props.certificate?.label ?? '') : label.value.trim(),
    establishmentId,
    certificate: certificateFile.value,
    password: password.value,
    ...(props.mode === 'rotate' && props.certificate ? { certificateId: props.certificate.id } : {})
  })
  password.value = ''
  certificateFile.value = null
}
</script>

<template>
  <UForm :state="{ label, password, certificateFile }" class="space-y-4" @submit="submit">
    <p class="text-muted text-sm">
      {{ mode === 'rotate' ? 'Substitua o arquivo do certificado. A versão atual permanece ativa se a validação falhar.' : 'Envie o certificado A1 do estabelecimento emissor.' }}
    </p>

    <UFormField
      v-if="mode === 'upload'"
      label="Nome do certificado"
      name="label"
      :error="fieldErrors?.label"
    >
      <UInput
        v-model="label"
        name="label"
        maxlength="255"
        autocomplete="off"
        :disabled="!canWrite || busy"
      />
    </UFormField>

    <UFormField
      v-if="mode === 'upload' && !establishmentId"
      label="Estabelecimento emissor"
      name="erp_establishment_id"
      :error="fieldErrors?.erp_establishment_id"
    >
      <USelect
        v-model="chosenEstablishmentId"
        :items="establishments.map(item => ({ label: item.name, value: item.id }))"
        value-key="value"
        label-key="label"
        placeholder="Selecione o estabelecimento"
        :disabled="!canWrite || busy"
      />
    </UFormField>

    <UFormField label="Arquivo PKCS#12" name="certificate" :error="fieldErrors?.certificate">
      <UFileUpload
        v-model="certificateFile"
        accept=".p12,.pfx,application/x-pkcs12"
        :multiple="false"
        :disabled="!canWrite || busy"
        label="Escolher arquivo"
        description="Arquivo .p12 ou .pfx, até 2 MiB"
      />
    </UFormField>

    <UFormField label="Senha do certificado" name="password" :error="fieldErrors?.password">
      <UInput
        v-model="password"
        name="password"
        type="password"
        autocomplete="new-password"
        :disabled="!canWrite || busy"
      />
      <p class="text-muted mt-1 text-xs">
        Deixe em branco se o arquivo não tiver senha. A senha não será guardada após o envio.
      </p>
    </UFormField>

    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :description="localError"
    />
    <UAlert
      v-if="fieldErrors?.certificate"
      color="error"
      variant="subtle"
      :description="fieldErrors.certificate"
    />

    <div class="flex justify-end">
      <UButton type="submit" :disabled="!canWrite || busy" :loading="busy">
        {{ mode === 'rotate' ? 'Validar e substituir certificado' : 'Validar e enviar certificado' }}
      </UButton>
    </div>
  </UForm>
</template>
