<script setup lang="ts">
import { formatOfficeErpMoney, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { toRef } from 'vue'
import { focusFormError } from '~/utils/formPresentation'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'

interface InvoiceChoice {
  id: number
  label: string
  outstanding: string
}

interface ReceiptAllocation {
  invoice_id: number
  amount: string
}

interface ReceiptPayload {
  amount: string
  received_on: string
  reference?: string
  proof_id?: string
  allocations: ReceiptAllocation[]
}

const props = withDefaults(defineProps<{
  open: boolean
  invoices: InvoiceChoice[]
  proofId?: string | null
  proofName?: string
  errors?: Record<string, string[]>
  error?: string
  proofError?: string
  busy?: boolean
  pending?: boolean
  unknown?: boolean
  recoveryKey?: string | null
}>(), {
  proofId: null,
  proofName: '',
  errors: () => ({}),
  error: '',
  proofError: '',
  busy: false,
  pending: false,
  unknown: false,
  recoveryKey: null
})

const emit = defineEmits<{
  'update:open': [open: boolean]
  'submit': [payload: ReceiptPayload]
  'proofSelection': [file: File]
  'uploadProof': [file: File]
  'recover': [key: string]
  'cancel': []
}>()

const restoreModalFocus = useModalFocusReturn(toRef(props, 'open'))
const selectedInvoiceIds = ref<string[]>([])
const allocationAmounts = reactive<Record<string, string>>({})
const localToday = new Date()
const receivedOn = ref(`${localToday.getFullYear()}-${String(localToday.getMonth() + 1).padStart(2, '0')}-${String(localToday.getDate()).padStart(2, '0')}`)
const reference = ref('')
const localError = ref('')
const fileError = ref('')
const localErrors = ref<Record<string, string>>({})
watch([() => props.errors, () => props.proofError, fileError, localErrors], () => {
  void focusFormError(undefined, 'office-erp-receipt')
})
function invalid(name: string, message: string) {
  localError.value = message
  localErrors.value = { [name]: message }
}
const locked = computed(() => props.busy || props.pending || props.unknown)
const selectedInvoices = computed(() => props.invoices.filter(invoice => selectedInvoiceIds.value.includes(String(invoice.id))))

function allocationError(invoiceId: number): string | undefined {
  const payloadIndex = selectedInvoices.value.findIndex(invoice => invoice.id === invoiceId)
  return localErrors.value[`allocations.${invoiceId}.amount`] ?? (payloadIndex < 0 ? undefined : props.errors[`allocations.${payloadIndex}.amount`]?.[0])
}

function cents(value: string): bigint {
  const [whole, fraction = '00'] = parseOfficeErpMoney(value).split('.')
  return BigInt(`${whole}${fraction}`)
}

function moneyFromCents(value: bigint): string {
  const digits = value.toString().padStart(3, '0')
  return `${digits.slice(0, -2)}.${digits.slice(-2)}`
}

function cancel() {
  emit('update:open', false)
  emit('cancel')
}

function onOpenUpdate(open: boolean) {
  if (!open) cancel()
  else emit('update:open', true)
}

function selectProof(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  fileError.value = ''
  if (!file) return
  emit('proofSelection', file)
  const supportedMimeTypes = ['application/pdf', 'image/png', 'image/jpeg']
  if (!supportedMimeTypes.includes(file.type)) {
    fileError.value = 'Envie um arquivo PDF, PNG ou JPEG.'
    input.value = ''
    return
  }
  if (file.size > 5 * 1024 * 1024) {
    fileError.value = 'A evidência deve ter no máximo 5 MiB.'
    input.value = ''
    return
  }
  emit('uploadProof', file)
}

function submit() {
  localError.value = ''
  localErrors.value = {}
  if (locked.value) return
  if (!/^\d{4}-\d{2}-\d{2}$/.test(receivedOn.value)) {
    invalid('received_on', 'Informe a data do recebimento.')
    return
  }
  if (selectedInvoices.value.length === 0) {
    invalid('allocations', 'Selecione ao menos uma fatura para alocar o recebimento.')
    return
  }

  const allocations: ReceiptAllocation[] = []
  try {
    for (const invoice of selectedInvoices.value) {
      const rawAmount = allocationAmounts[String(invoice.id)]?.trim() ?? ''
      if (!rawAmount) {
        invalid(`allocations.${invoice.id}.amount`, `Informe o valor alocado para ${invoice.label}.`)
        return
      }
      let amountCents: bigint
      try {
        amountCents = cents(rawAmount)
      } catch {
        invalid(`allocations.${invoice.id}.amount`, 'Revise os valores alocados; use até duas casas decimais.')
        return
      }
      if (amountCents <= 0n) {
        invalid(`allocations.${invoice.id}.amount`, 'Cada alocação deve ser maior que zero.')
        return
      }
      if (amountCents > cents(invoice.outstanding)) {
        invalid(`allocations.${invoice.id}.amount`, `A alocação para ${invoice.label} excede o saldo em aberto.`)
        return
      }
      allocations.push({ invoice_id: invoice.id, amount: moneyFromCents(amountCents) })
    }
    const amountCents = allocations.reduce((total, allocation) => total + cents(allocation.amount), 0n)
    const amount = parseOfficeErpMoney(moneyFromCents(amountCents))
    const payload: ReceiptPayload = {
      amount,
      received_on: receivedOn.value,
      allocations
    }
    if (reference.value.trim()) payload.reference = reference.value.trim()
    if (props.proofId) payload.proof_id = props.proofId
    emit('submit', payload)
  } catch {
    localError.value = 'Revise os valores alocados; use até duas casas decimais.'
  }
}
</script>

<template>
  <UModal
    :open="open"
    title="Registrar recebimento"
    description="Registre um recebimento manual e distribua o valor entre as faturas."
    :ui="{ content: 'max-w-2xl', footer: 'shrink-0 flex-wrap justify-end' }"
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
        id="office-erp-receipt"
        :state="{ received_on: receivedOn, reference, allocations: allocationAmounts }"
        class="space-y-4"
        @submit="submit"
        @error="event => focusFormError(event, 'office-erp-receipt')"
      >
        <UFormField
          label="Faturas"
          name="allocations"
          required
          :error="localErrors.allocations || props.errors.allocations?.[0]"
        >
          <UCheckboxGroup v-model="selectedInvoiceIds" :items="invoices.map(invoice => ({ label: `${invoice.label} · em aberto ${formatOfficeErpMoney(invoice.outstanding)}`, value: String(invoice.id) }))" :disabled="locked" />
        </UFormField>
        <div v-for="invoice in selectedInvoices" :key="invoice.id">
          <UFormField
            :label="`Alocação para fatura ${invoice.id}`"
            :name="`allocations.${invoice.id}.amount`"
            required
            :error="allocationError(invoice.id)"
          >
            <UInput
              v-model="allocationAmounts[String(invoice.id)]"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              :aria-label="`Alocação para fatura ${invoice.id}`"
              :disabled="locked"
            />
          </UFormField>
        </div>
        <UFormField
          label="Data do recebimento"
          name="received_on"
          required
          :error="localErrors.received_on || props.errors.received_on?.[0]"
        >
          <UInput
            v-model="receivedOn"
            type="date"
            aria-label="Data do recebimento"
            :disabled="locked"
          />
        </UFormField>
        <UFormField label="Referência" name="reference" :error="props.errors.reference?.[0]">
          <UInput
            v-model="reference"
            type="text"
            aria-label="Referência"
            maxlength="191"
            :disabled="locked"
          />
        </UFormField>
        <UFormField label="Comprovante privado" name="proof_id" :error="props.errors.proof_id?.[0] || proofError || fileError">
          <UInput
            type="file"
            accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
            aria-label="Selecionar comprovante"
            :disabled="locked"
            @change="selectProof"
          />
          <p class="mt-1 text-sm text-muted">
            PDF, PNG ou JPEG, até 5 MiB. O arquivo é enviado pelo fluxo privado do escritório.
            <span v-if="proofName"> Arquivo associado: {{ proofName }}.</span>
          </p>
        </UFormField>
        <p class="text-sm text-muted">
          O registro é manual. A situação das faturas muda somente após a confirmação do servidor.
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
        form="office-erp-receipt"
        :loading="busy"
        :disabled="locked"
        label="Registrar recebimento"
      />
    </template>
  </UModal>
</template>
