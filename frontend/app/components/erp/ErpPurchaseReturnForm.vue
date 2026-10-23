<script setup lang="ts">
import type { ErpPurchase, ErpPurchaseReturn, ErpPurchaseReturnableLine } from '#shared/types/erpBusiness'
import { formatPurchaseDecimal } from '#shared/utils/erpPurchase'

const props = defineProps<{
  purchase: ErpPurchase
  returnDraft?: ErpPurchaseReturn | null
  fieldErrors?: Record<string, string>
  operationError?: string
  loading?: boolean
  returnAllowed: boolean
  postAllowed: boolean
}>()
const entry = defineModel<{ quantities: Record<string, string>, reason: string }>({ required: true })
const emit = defineEmits<{
  create: [quantities: Record<string, string>, reason: string]
  post: []
  cancel: []
}>()
const quantities = computed({
  get: () => entry.value.quantities,
  set: (value: Record<string, string>) => { entry.value = { ...entry.value, quantities: value } }
})
const reason = computed({
  get: () => entry.value.reason,
  set: (value: string) => { entry.value = { ...entry.value, reason: value } }
})
const localError = ref('')
const lines = computed<ErpPurchaseReturnableLine[]>(() => props.purchase.returnable_lines ?? [])
const locked = computed(() => Boolean(props.purchase.returnability?.needs_reconciliation || props.purchase.needs_reconciliation || props.purchase.finance?.needs_reconciliation))
const hasRemaining = computed(() => lines.value.some(line => line.remaining_quantity && line.remaining_quantity !== '0.0000'))
function money(value: string | null | undefined) {
  if (value === null || value === undefined) return 'Indisponível'
  try {
    return formatPurchaseDecimal(value)
  } catch {
    return value
  }
}
function submit() {
  localError.value = ''
  const selected: Record<string, string> = {}
  for (const line of lines.value) {
    const value = quantities.value[String(line.source_line_id)]?.trim()
    if (value) selected[String(line.source_line_id)] = value
  }
  if (Object.keys(selected).length === 0) {
    localError.value = 'Informe uma quantidade para ao menos uma linha.'
    return
  }
  if (!reason.value.trim()) {
    localError.value = 'Descreva o motivo da devolução.'
    return
  }
  emit('create', selected, reason.value)
}
</script>

<template>
  <section class="space-y-5">
    <UAlert v-if="operationError" color="error" :title="operationError" />
    <UAlert v-if="locked" color="error" title="Conferência necessária">
      {{ purchase.returnability?.reconciliation_reason || purchase.finance?.reconciliation_reason || 'O histórico de origem não permite calcular um saldo seguro para novas devoluções.' }}
    </UAlert>
    <UAlert v-if="purchase.status !== 'posted'" color="warning" title="Compra ainda não lançada">
      As devoluções só podem usar linhas de uma compra recebida e imutável.
    </UAlert>
    <div class="grid gap-3 rounded-md bg-elevated/50 p-3 text-sm sm:grid-cols-3">
      <div>
        <p class="text-muted">
          Obrigação original em aberto
        </p><p class="font-medium tabular-nums">
          {{ purchase.finance?.currency ?? purchase.currency }} {{ money(purchase.finance?.gross_original_due) }}
        </p>
      </div>
      <div>
        <p class="text-muted">
          Crédito de devolução aberto
        </p><p class="font-medium tabular-nums">
          {{ purchase.finance?.currency ?? purchase.currency }} {{ money(purchase.finance?.open_return_credit) }}
        </p>
      </div>
      <div>
        <p class="text-muted">
          Exposição líquida assinada
        </p><p class="font-medium tabular-nums">
          {{ purchase.finance?.currency ?? purchase.currency }} {{ money(purchase.finance?.net_exposure) }}
        </p>
      </div>
      <p class="text-xs text-muted sm:col-span-3">
        O crédito do fornecedor é separado da obrigação original. Não há compensação, estorno de pagamento ou reembolso automático.
      </p>
    </div>

    <div v-if="returnDraft" class="space-y-4">
      <UAlert :color="returnDraft.status === 'posted' ? 'success' : 'info'" :title="returnDraft.status === 'posted' ? 'Devolução lançada' : `Devolução #${returnDraft.id} preparada`">
        O documento original permanece imutável. {{ returnDraft.status === 'posted' ? `Crédito ${returnDraft.credit_id ? `#${returnDraft.credit_id}` : 'sem valor financeiro'} · ${returnDraft.financial_disposition ?? '—'}.` : 'Confira os itens antes de confirmar.' }}
      </UAlert>
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-muted">
            Motivo
          </dt><dd>{{ returnDraft.reason }}</dd>
        </div>
        <div>
          <dt class="text-muted">
            Total do servidor
          </dt><dd class="tabular-nums">
            {{ returnDraft.currency }} {{ money(returnDraft.total_amount) }}
          </dd>
        </div>
      </dl>
      <div v-if="returnDraft.status === 'draft'" class="flex justify-end gap-2">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          label="Fechar"
          @click="emit('cancel')"
        />
        <UButton
          v-if="postAllowed"
          type="button"
          icon="i-lucide-package-check"
          label="Lançar devolução e crédito"
          :loading="loading"
          :disabled="loading"
          @click="emit('post')"
        />
      </div>
    </div>

    <template v-else>
      <UAlert v-if="localError" color="error" :title="localError" />
      <UAlert v-if="!hasRemaining && !locked" color="info" title="Nenhuma quantidade disponível para devolução" />
      <div class="space-y-3">
        <article
          v-for="line in lines"
          :key="line.source_line_id"
          class="grid gap-3 rounded-md border border-default p-3 sm:grid-cols-[minmax(0,1fr)_12rem]"
          :data-source-line="line.source_line_id"
        >
          <div class="space-y-1 text-sm">
            <h4 class="font-medium text-highlighted">
              Linha de origem #{{ line.source_line_id }}
            </h4>
            <p class="text-muted">
              Recebido {{ line.quantity }} · já devolvido {{ line.returned_quantity ?? 'não conciliado' }} · disponível {{ line.remaining_quantity ?? 'não conciliado' }}
            </p>
            <p class="text-muted">
              Conversão {{ line.unit_conversion_factor }} · valor original {{ purchase.currency }} {{ money(line.line_total) }}
            </p>
            <p class="text-xs text-muted">
              Elegibilidade física: {{ line.physical_eligibility }}
            </p>
          </div>
          <UFormField label="Quantidade a devolver" :name="`lines.${line.source_line_id}.quantity`" :error="fieldErrors?.[`lines.${line.source_line_id}.quantity`]">
            <UInput
              v-model="quantities[String(line.source_line_id)]"
              type="text"
              :aria-label="`Quantidade a devolver da linha ${line.source_line_id}`"
              inputmode="decimal"
              autocomplete="off"
              placeholder="0.0000"
              :disabled="loading || locked || purchase.status !== 'posted' || !line.remaining_quantity || line.remaining_quantity === '0.0000'"
              class="w-full tabular-nums"
            />
          </UFormField>
        </article>
      </div>
      <UFormField label="Motivo da devolução" name="reason" :error="fieldErrors?.reason">
        <UTextarea
          v-model="reason"
          aria-label="Motivo da devolução"
          maxlength="2000"
          :disabled="loading || locked || purchase.status !== 'posted'"
          class="w-full"
        />
      </UFormField>
      <div class="flex justify-end gap-2">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          label="Fechar"
          @click="emit('cancel')"
        />
        <UButton
          v-if="returnAllowed"
          type="button"
          icon="i-lucide-rotate-ccw"
          label="Preparar devolução"
          :loading="loading"
          :disabled="loading || locked || purchase.status !== 'posted' || !hasRemaining"
          @click="submit"
        />
      </div>
    </template>
  </section>
</template>
