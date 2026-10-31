<script setup lang="ts">
import { toRef } from 'vue'
import { focusFormError } from '~/utils/formPresentation'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'

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

const restoreModalFocus = useModalFocusReturn(toRef(props, 'open'))
const reason = ref('')
const localError = ref('')
watch([() => props.errors, localError], () => {
  void focusFormError(undefined, 'office-erp-reversal')
})
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
    :ui="{ footer: 'shrink-0 flex-wrap justify-end' }"
    @update:open="onOpenUpdate"
    @after:leave="restoreModalFocus"
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
        id="office-erp-reversal"
        :state="{ reason }"
        class="space-y-4"
        @submit="submit"
        @error="event => focusFormError(event, 'office-erp-reversal')"
      >
        <UFormField
          label="Motivo da reversão"
          name="reason"
          required
          :error="localError || props.errors.reason?.[0]"
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
      </UForm>
    </template>
    <template #footer>
      <UButton
        type="button"
        color="neutral"
        variant="outline"
        label="Cancelar"
        :disabled="busy"
        @click="cancel"
      />
      <UButton
        v-if="!unknown"
        type="submit"
        form="office-erp-reversal"
        color="error"
        :loading="busy"
        :disabled="locked"
        label="Confirmar reversão"
      />
    </template>
  </UModal>
</template>
