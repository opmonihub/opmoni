<script setup lang="ts">
const props = withDefaults(defineProps<{
  open: boolean
  busy?: boolean
  unknown?: boolean
  recoveryKey?: string | null
  error?: string
  errors?: Record<string, string[]>
}>(), {
  busy: false,
  unknown: false,
  recoveryKey: null,
  error: '',
  errors: () => ({})
})

const emit = defineEmits<{
  'update:open': [open: boolean]
  'submit': [payload: { reason: string }]
  'recover': [key: string]
  'cancel': []
}>()

const reason = ref('')
const localError = ref('')
const locked = computed(() => props.busy || props.unknown)

function cancel() {
  emit('update:open', false)
  emit('cancel')
}

function onOpenUpdate(open: boolean) {
  if (open) emit('update:open', true)
  else cancel()
}

function submit() {
  localError.value = ''
  if (locked.value) return
  if (!reason.value.trim()) {
    localError.value = 'Informe o motivo da reversão.'
    return
  }
  emit('submit', { reason: reason.value.trim() })
}
</script>

<template>
  <UModal
    :open="open"
    title="Reverter recebimento"
    description="A reversão registra um estorno interno e exige um motivo."
    @update:open="onOpenUpdate"
  >
    <template #body>
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
      <UAlert
        v-if="unknown"
        color="warning"
        variant="subtle"
        title="Confirmação pendente"
      >
        Consulte a operação para atualizar o estado antes de tentar novamente.
      </UAlert>
      <div v-if="unknown" class="mt-4 flex justify-end">
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
        :state="{ reason }"
        class="space-y-4"
        @submit.prevent="submit"
      >
        <UFormField
          label="Motivo da reversão"
          name="reason"
          required
          :error="props.errors.reason?.[0]"
        >
          <UTextarea
            v-model="reason"
            aria-label="Motivo da reversão"
            :rows="3"
            maxlength="2000"
            :disabled="locked"
          />
        </UFormField>
        <p class="text-sm text-muted">
          Esta ação corrige o registro interno. Ela não movimenta dinheiro nem confirma reembolso bancário.
        </p>
        <div class="flex justify-end gap-2">
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            label="Cancelar"
            :disabled="busy"
            @click="cancel"
          />
          <UButton
            type="submit"
            color="error"
            :loading="busy"
            :disabled="locked"
            label="Confirmar reversão"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
