<script setup lang="ts">
import type { ErpStockMovementDetail } from '#shared/types/erpBusiness'
import { buildErpStockReversalPayload, stockCostDescription } from '#shared/utils/erpStock'

const props = withDefaults(defineProps<{
  detail: ErpStockMovementDetail
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
const quantity = ref(props.detail.reversal.remaining_quantity)
const reason = ref('')
const confirmed = ref(false)
const localError = ref('')
const remainingQuantity = computed(() => props.detail.reversal.remaining_quantity)
const canReverseHere = computed(() => props.canReverse && props.detail.reversal.workflow === 'standalone' && props.detail.reversal.permission === 'erp.stock.movements.reverse' && remainingQuantity.value !== '0.0000')

watch(() => [props.detail.id, props.detail.reversal.remaining_quantity] as const, () => {
  quantity.value = props.detail.reversal.remaining_quantity
  reason.value = ''
  confirmed.value = false
  localError.value = ''
})

function submitReversal() {
  localError.value = ''
  if (!confirmed.value) {
    localError.value = 'Confirme que esta correção corresponde ao movimento selecionado.'
    return
  }
  try {
    const payload = buildErpStockReversalPayload({ quantity: quantity.value, remaining_quantity: remainingQuantity.value, reason: reason.value })
    emit('reverse', { ...payload, remaining_quantity: remainingQuantity.value })
  } catch (error) {
    localError.value = error instanceof Error ? error.message : 'Revise a quantidade e o motivo da correção.'
  }
}

function describeCost(row: { cost_status: string | null, total_cost: string | null, known_cost: string | null, unvalued_quantity: string | null, currency: string | null, cost_breakdown: ErpStockMovementDetail['cost_breakdown'] }) {
  return stockCostDescription(row)
}

defineExpose({ submitReversal, quantity, reason, confirmed })
</script>

<template>
  <div class="space-y-5" data-stock-movement-detail>
    <section class="grid gap-3 sm:grid-cols-2">
      <div>
        <p class="text-xs text-muted">
          Movimento
        </p><p class="font-medium">
          #{{ detail.id }} · {{ detail.direction === 'in' ? 'Entrada' : 'Saída' }}
        </p>
      </div>
      <div>
        <p class="text-xs text-muted">
          Quantidade
        </p><p class="font-medium tabular-nums">
          {{ detail.quantity }}
        </p>
      </div>
      <div>
        <p class="text-xs text-muted">
          Origem
        </p><p class="break-words">
          {{ detail.source_type }} · #{{ detail.source_id }}
        </p>
      </div>
      <div>
        <p class="text-xs text-muted">
          Método gravado neste movimento
        </p><p>{{ detail.valuation_method?.toUpperCase() ?? 'Sem método informado' }}</p>
      </div>
      <div class="sm:col-span-2">
        <p class="text-xs text-muted">
          Valoração
        </p><p>{{ describeCost(detail) }}</p>
      </div>
      <p v-if="detail.reason" class="sm:col-span-2 text-sm text-muted">
        Motivo: {{ detail.reason }}
      </p>
    </section>

    <section class="space-y-2">
      <h3 class="font-semibold">
        Alocações de origem
      </h3>
      <p v-if="detail.allocations.length === 0" class="text-sm text-muted">
        Este movimento não tem alocações detalhadas.
      </p>
      <ul v-else class="divide-y divide-default rounded-md border border-default">
        <li v-for="allocation in detail.allocations" :key="allocation.id" class="grid gap-1 p-3 text-sm sm:grid-cols-3">
          <span>Linha {{ allocation.ordinal }} · {{ allocation.kind }}</span>
          <span>Quantidade {{ allocation.quantity }}</span>
          <span>{{ allocation.currency ?? 'Moeda não identificada' }} · {{ allocation.unit_cost ?? 'Custo desconhecido' }} · {{ allocation.cost_status }}</span>
          <span class="sm:col-span-3 text-muted">Lote {{ allocation.erp_stock_lot_id ?? 'não vinculado' }} · Alocação de origem {{ allocation.erp_stock_allocation_id ?? 'não informada' }}</span>
        </li>
      </ul>
    </section>

    <section v-if="detail.settlements.length" class="space-y-2">
      <h3 class="font-semibold">
        Conciliações de custo
      </h3>
      <p class="text-sm text-muted">
        As conciliações preservam o movimento original e não representam outra movimentação física.
      </p>
      <ul class="divide-y divide-default rounded-md border border-default">
        <li v-for="(settlement, index) in detail.settlements" :key="index" class="p-3 text-sm">
          Conciliação {{ index + 1 }} · consulte os custos e as quantidades das alocações acima.
        </li>
      </ul>
    </section>

    <section v-if="detail.reversals.length" class="space-y-2">
      <h3 class="font-semibold">
        Correções vinculadas
      </h3>
      <ul class="divide-y divide-default rounded-md border border-default">
        <li v-for="reversal in detail.reversals" :key="reversal.id" class="flex flex-wrap justify-between gap-2 p-3 text-sm">
          <span>Movimento #{{ reversal.id }} · {{ reversal.direction === 'in' ? 'entrada' : 'saída' }}</span>
          <span>Quantidade {{ reversal.quantity }} · {{ reversal.posted_at ?? 'Data não informada' }}</span>
        </li>
      </ul>
    </section>

    <section v-if="canReverseHere" class="space-y-3 rounded-lg border border-warning/40 bg-warning/5 p-4">
      <div>
        <h3 class="font-semibold">
          Corrigir este movimento
        </h3>
        <p class="text-sm text-muted">
          Saldo ainda corrigível: {{ remainingQuantity }}. A correção reutiliza as alocações originais e pode ser parcial.
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
        <UCheckbox v-model="confirmed" class="sm:col-span-2" label="Confirmo a correção deste movimento e a quantidade informada." />
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
      v-else-if="detail.reversal.workflow === 'reconciliation'"
      color="warning"
      variant="subtle"
      title="Este movimento precisa de conciliação de origem antes de permitir uma correção direta."
    />
  </div>
</template>
