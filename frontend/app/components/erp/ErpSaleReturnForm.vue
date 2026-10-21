<script setup lang="ts">
import type { ErpCommercialDocument } from '#shared/types/erpBusiness'
import { erpReturnLineError } from '#shared/utils/erpSales'

const props = defineProps<{ sale: ErpCommercialDocument, saving: boolean, errors: Record<string, string>, disabled?: boolean }>()
const emit = defineEmits<{ submit: [reason: string, lines: Array<{ source_line_id: number, quantity: string }>], cancel: [] }>()
const reason = ref('')
const quantities = reactive<Record<number, string>>({})
const submittedSourceLineIds = ref<number[]>([])
const editedSourceLineIds = ref(new Set<number>())
const returnableLines = computed(() => props.sale.returnable_lines ?? [])
const ready = computed(() => reason.value.trim().length > 0 && returnableLines.value.some(line => Boolean(quantities[line.source_line_id]?.trim())))
function lineError(lineId: number) {
  return editedSourceLineIds.value.has(lineId) ? '' : erpReturnLineError(lineId, submittedSourceLineIds.value, props.errors)
}
function changeQuantity(lineId: number, value: unknown) {
  quantities[lineId] = typeof value === 'string' ? value : ''
  editedSourceLineIds.value = new Set(editedSourceLineIds.value).add(lineId)
}

function submit() {
  const lines = returnableLines.value.flatMap((line) => {
    const quantity = quantities[line.source_line_id]?.trim()
    return quantity ? [{ source_line_id: line.source_line_id, quantity }] : []
  })
  if (ready.value) {
    submittedSourceLineIds.value = lines.map(line => line.source_line_id)
    editedSourceLineIds.value = new Set()
    emit('submit', reason.value, lines)
  }
}
defineExpose({ reason, quantities, submit })
</script>

<template>
  <UForm :state="{ reason, quantities }" class="min-w-0 space-y-5" @submit="submit">
    <UAlert
      color="warning"
      variant="subtle"
      title="Devolução comercial"
      description="O servidor calcula o crédito oposto a partir das quantidades restantes. O pagamento original não será estornado nem reembolsado automaticamente."
    />
    <UFormField
      name="reason"
      label="Motivo"
      required
      :error="errors.reason"
    >
      <UTextarea v-model="reason" class="w-full" :disabled="disabled || saving" />
    </UFormField>
    <div v-if="sale.returnability?.needs_reconciliation || sale.finance?.needs_reconciliation" role="status">
      <UAlert color="error" title="Devolução indisponível para conferência" description="A situação financeira ou o histórico de origem exige conciliação." />
    </div>
    <section class="min-w-0 space-y-3" aria-label="Quantidades a devolver">
      <article v-for="line in returnableLines" :key="line.source_line_id" class="grid min-w-0 gap-3 rounded-lg border border-muted p-3 sm:grid-cols-[minmax(0,1fr)_10rem] sm:items-center">
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-highlighted">
            Linha de venda #{{ line.source_line_id }}
          </p>
          <p class="text-xs text-muted">
            Restante: {{ line.remaining_quantity ?? 'Indisponível' }} · Total original: {{ line.line_total }}
          </p>
        </div>
        <UFormField :name="`lines.${line.source_line_id}.quantity`" label="Quantidade" :error="lineError(line.source_line_id)">
          <UInput
            :model-value="quantities[line.source_line_id]"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            class="w-full"
            :disabled="disabled || saving || line.remaining_quantity === null || line.remaining_quantity === '0.0000'"
            @update:model-value="changeQuantity(line.source_line_id, $event)"
          />
        </UFormField>
      </article>
    </section>
    <div class="flex flex-wrap justify-end gap-2 border-t border-muted pt-4">
      <UButton
        type="button"
        color="neutral"
        variant="ghost"
        label="Cancelar"
        :disabled="saving"
        @click="emit('cancel')"
      />
      <UButton
        type="submit"
        color="primary"
        label="Salvar rascunho de devolução"
        :loading="saving"
        :disabled="disabled || saving || !ready || sale.returnability?.needs_reconciliation || sale.finance?.needs_reconciliation"
      />
    </div>
  </UForm>
</template>
