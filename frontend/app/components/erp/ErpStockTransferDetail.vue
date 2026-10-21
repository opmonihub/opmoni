<script setup lang="ts">
import type { ErpStockTransferDetail } from '#shared/types/erpBusiness'
import { buildErpStockReversalPayload, remainingStockQuantity, stockCostDescription } from '#shared/utils/erpStock'

const props = withDefaults(defineProps<{
  detail: ErpStockTransferDetail
  canReverse: boolean
  busy?: boolean
  mutationError?: string
  fieldErrors?: Record<string, string>
  operationPending?: boolean
  canRetryExact?: boolean
}>(), { busy: false, mutationError: '', fieldErrors: () => ({}), operationPending: false, canRetryExact: false })
const emit = defineEmits<{
  reverse: [payload: { quantity: string, remaining_quantity: string, reason: string }]
  reconcile: []
  retry: []
}>()
const remainingQuantity = computed(() => remainingStockQuantity(props.detail.quantity, props.detail.returned_quantity))
const quantity = ref(remainingQuantity.value)
const reason = ref('')
const confirmed = ref(false)
const localError = ref('')
const canReverseHere = computed(() => props.canReverse && remainingQuantity.value !== '0.0000')

watch(() => [props.detail.id, props.detail.returned_quantity] as const, () => {
  quantity.value = remainingQuantity.value
  reason.value = ''
  confirmed.value = false
  localError.value = ''
})

function submitReversal() {
  localError.value = ''
  if (!confirmed.value) {
    localError.value = 'Confirme a correção conjunta das duas movimentações.'
    return
  }
  try {
    const payload = buildErpStockReversalPayload({ quantity: quantity.value, remaining_quantity: remainingQuantity.value, reason: reason.value })
    emit('reverse', { ...payload, remaining_quantity: remainingQuantity.value })
  } catch (error) {
    localError.value = error instanceof Error ? error.message : 'Revise a quantidade e o motivo da correção.'
  }
}

defineExpose({ submitReversal, quantity, reason, confirmed })
</script>

<template>
  <div class="space-y-5" data-stock-transfer-detail>
    <section class="grid gap-3 sm:grid-cols-2">
      <div>
        <p class="text-xs text-muted">
          Transferência
        </p><p class="font-medium">
          #{{ detail.id }} · {{ detail.status }}
        </p>
      </div>
      <div>
        <p class="text-xs text-muted">
          Quantidade original
        </p><p class="font-medium tabular-nums">
          {{ detail.quantity }}
        </p>
      </div>
      <div>
        <p class="text-xs text-muted">
          Já devolvida ao estabelecimento de origem
        </p><p class="tabular-nums">
          {{ detail.returned_quantity }}
        </p>
      </div>
      <div>
        <p class="text-xs text-muted">
          Saldo ainda corrigível
        </p><p class="tabular-nums">
          {{ remainingQuantity }}
        </p>
      </div>
      <p class="sm:col-span-2 text-sm text-muted">
        A correção devolve o estoque ao destino primeiro e cria as duas pernas em uma única operação.
      </p>
    </section>

    <section class="space-y-2">
      <h3 class="font-semibold">
        Movimentações vinculadas
      </h3>
      <ul class="divide-y divide-default rounded-md border border-default">
        <li v-for="movement in detail.movements" :key="movement.id" class="grid gap-1 p-3 text-sm sm:grid-cols-3">
          <span>Movimento #{{ movement.id }} · {{ movement.direction === 'in' ? 'entrada' : 'saída' }}</span>
          <span>Quantidade {{ movement.quantity }}</span>
          <span>{{ stockCostDescription(movement) }}</span>
          <span class="sm:col-span-3 text-muted">Origem {{ movement.source_type }} · #{{ movement.source_id }} · método gravado {{ movement.valuation_method?.toUpperCase() ?? 'não informado' }}</span>
        </li>
      </ul>
      <ul v-if="detail.reversals.length" class="divide-y divide-default rounded-md border border-default">
        <li v-for="movement in detail.reversals" :key="movement.id" class="p-3 text-sm">
          Correção #{{ movement.id }} · {{ movement.direction === 'in' ? 'entrada' : 'saída' }} · {{ movement.quantity }}
        </li>
      </ul>
    </section>

    <section v-if="canReverseHere" class="space-y-3 rounded-lg border border-warning/40 bg-warning/5 p-4">
      <div>
        <h3 class="font-semibold">
          Corrigir transferência
        </h3>
        <p class="text-sm text-muted">
          Informe a quantidade e o motivo. A correção cria as duas pernas juntas e não altera a transferência original.
        </p>
      </div>
      <UAlert
        v-if="mutationError"
        color="error"
        variant="subtle"
        :title="mutationError"
      />
      <UAlert
        v-if="operationPending"
        color="warning"
        variant="subtle"
        title="A operação continua sem resultado confirmado. Consulte ou repita o mesmo conteúdo."
      />
      <UForm :state="{ quantity, reason, confirmed }" class="grid gap-3 sm:grid-cols-2" @submit="submitReversal">
        <UFormField
          label="Quantidade a corrigir"
          name="quantity"
          required
          :error="fieldErrors.quantity"
        >
          <UInput
            v-model="quantity"
            type="text"
            inputmode="decimal"
            autocomplete="off"
          />
        </UFormField>
        <UFormField
          label="Motivo"
          name="reason"
          required
          :error="fieldErrors.reason"
        >
          <UTextarea v-model="reason" :rows="2" maxlength="255" />
        </UFormField>
        <UCheckbox v-model="confirmed" class="sm:col-span-2" label="Confirmo a correção conjunta das duas movimentações." />
        <UAlert
          v-if="localError"
          color="error"
          variant="subtle"
          :title="localError"
          class="sm:col-span-2"
        />
        <UAlert
          v-if="fieldErrors.general"
          color="error"
          variant="subtle"
          :title="fieldErrors.general"
          class="sm:col-span-2"
        />
        <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
          <UButton
            v-if="operationPending"
            type="button"
            color="neutral"
            variant="outline"
            :disabled="busy"
            label="Consultar operação"
            @click="emit('reconcile')"
          />
          <UButton
            v-if="operationPending && canRetryExact"
            type="button"
            color="warning"
            variant="outline"
            :disabled="busy"
            label="Repetir exatamente"
            @click="emit('retry')"
          />
          <UButton
            type="submit"
            color="warning"
            :loading="busy"
            :disabled="busy || operationPending"
            label="Confirmar correção"
          />
        </div>
      </UForm>
    </section>
    <UAlert
      v-else
      color="neutral"
      variant="subtle"
      title="Não há saldo restante para corrigir nesta transferência."
    />
  </div>
</template>
