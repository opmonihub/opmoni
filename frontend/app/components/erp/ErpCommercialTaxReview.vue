<script setup lang="ts">
import type { ErpCommercialDocument, ErpCommercialTaxContext, ErpCommercialTaxReviewOptions, ErpCommercialTaxReviewPreview } from '#shared/types/erpBusiness'
import { erpSavedTaxContext, erpTaxRecord, erpTaxSourceLabel } from '#shared/utils/erpCommercialTaxReview'

const props = defineProps<{
  document: ErpCommercialDocument
  preview: ErpCommercialTaxReviewPreview | null
  loading: boolean
  busy: boolean
  error: string
  errors: Record<string, string>
  disabled: boolean
}>()
const emit = defineEmits<{ preview: [options: ErpCommercialTaxReviewOptions], apply: [options: ErpCommercialTaxReviewOptions], invalidate: [], cancel: [] }>()
const context = ref<ErpCommercialTaxContext>({ ...erpSavedTaxContext(props.document) })
const contextChanged = ref(false)
const cfops = ref<Record<number, { mode: string, value: string }>>({})
const contextFields = [
  { name: 'origin_uf', label: 'UF de origem' }, { name: 'destination_uf', label: 'UF do destinatário' },
  { name: 'model', label: 'Modelo do contexto' }, { name: 'purpose', label: 'Finalidade' }, { name: 'presence', label: 'Presença' }
] as const
const cfopModes = [{ label: 'Preservar escolha salva', value: 'retain' }, { label: 'Limpar escolha explícita', value: 'clear' }, { label: 'Informar escolha explícita', value: 'explicit' }]
function invalidate() {
  emit('invalidate')
}
function updateContext(field: keyof ErpCommercialTaxContext, value: string | boolean | null) {
  Object.assign(context.value, { [field]: value })
  contextChanged.value = true
  invalidate()
}
function setCfop(id: number, mode: string, value = cfops.value[id]?.value ?? '') {
  cfops.value[id] = { mode, value }
  invalidate()
}
function options(): ErpCommercialTaxReviewOptions {
  const lines = props.document.lines.flatMap((line) => {
    const selection = line.id ? cfops.value[line.id] : null
    if (!line.id || !selection || selection.mode === 'retain') return []
    return [{ id: line.id, cfop: selection.mode === 'clear' ? null : selection.value }]
  })
  return { ...(contextChanged.value ? { tax_context: { ...context.value } } : {}), ...(lines.length ? { lines } : {}) }
}
function product(snapshot: Record<string, unknown>) {
  return erpTaxRecord(snapshot.product)
}
function cfop(snapshot: Record<string, unknown>) {
  return erpTaxRecord(snapshot.cfop_resolution)
}
function taxSources(snapshot: Record<string, unknown>) {
  return erpTaxRecord(erpTaxRecord(snapshot.product_tax_resolution).sources)
}
function value(input: unknown) {
  return typeof input === 'string' || typeof input === 'number' ? String(input) : 'Não informado'
}
</script>

<template>
  <section class="min-w-0 space-y-4" aria-label="Revisão tributária do rascunho">
    <UAlert
      color="info"
      variant="subtle"
      title="Revisar antes de aplicar"
      description="A prévia compara a classificação salva com os dados atuais. Aplicar salva somente a revisão; lançar a venda continua sendo uma ação separada. Valores, quantidades, unidade e condições de estoque permanecem preservados."
    />
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      title="A prévia precisa de revisão"
      :description="error"
    />
    <div class="grid gap-3 sm:grid-cols-2">
      <UFormField
        v-for="field in contextFields"
        :key="field.name"
        :name="`tax_context.${field.name}`"
        :label="field.label"
        :error="errors[`tax_context.${field.name}`]"
      >
        <UInput
          :model-value="context[field.name] ?? ''"
          class="w-full"
          :disabled="busy || disabled || loading"
          @update:model-value="updateContext(field.name, String($event))"
        />
      </UFormField>
      <UFormField name="tax_context.final_consumer" label="Consumidor final" :error="errors['tax_context.final_consumer']">
        <USelect
          :model-value="context.final_consumer === true ? 'yes' : context.final_consumer === false ? 'no' : 'unknown'"
          :items="[{ label: 'Não informado', value: 'unknown' }, { label: 'Sim', value: 'yes' }, { label: 'Não', value: 'no' }]"
          class="w-full"
          :disabled="busy || disabled || loading"
          @update:model-value="updateContext('final_consumer', $event === 'yes' ? true : $event === 'no' ? false : null)"
        />
      </UFormField>
    </div>
    <div v-for="(line, index) in document.lines" :key="line.id ?? index" class="space-y-2 rounded-lg border border-muted p-3">
      <p class="text-sm font-medium text-highlighted">
        Linha #{{ line.id }} · {{ erpTaxRecord(line.product_snapshot).description }}
      </p>
      <UFormField :name="`lines.${index}.cfop`" label="Escolha de CFOP" :error="errors[`lines.${index}.cfop`]">
        <USelect
          :model-value="cfops[line.id!]?.mode ?? 'retain'"
          :items="cfopModes"
          class="w-full"
          :disabled="busy || disabled || loading"
          @update:model-value="setCfop(line.id!, String($event))"
        />
        <UInput
          v-if="cfops[line.id!]?.mode === 'explicit'"
          :model-value="cfops[line.id!]?.value ?? ''"
          placeholder="CFOP permitido na natureza"
          class="mt-2 w-full"
          :disabled="busy || disabled || loading"
          @update:model-value="setCfop(line.id!, 'explicit', String($event))"
        />
      </UFormField>
      <p
        v-for="(message, field) in errors"
        v-show="String(field).startsWith(`lines.${index}.`) && field !== `lines.${index}.cfop`"
        :key="field"
        class="text-sm text-error"
      >
        {{ message }}
      </p>
    </div>
    <UButton
      type="button"
      color="neutral"
      variant="outline"
      label="Gerar prévia"
      :loading="loading"
      :disabled="busy || disabled"
      @click="emit('preview', options())"
    />
    <section v-if="preview" class="space-y-3" aria-label="Fatos salvos e classificação proposta">
      <p v-if="preview.fiscal_source_review" class="text-sm text-muted">
        Modelo {{ preview.fiscal_source_review.model }} · configuração #{{ preview.fiscal_source_review.configuration_revision_id ?? 'não informada' }} · natureza #{{ preview.fiscal_source_review.operation_nature_id ?? 'não informada' }}
      </p>
      <div v-for="row in preview.lines" :key="row.id" class="space-y-2 rounded-lg border border-muted p-3">
        <h3 class="text-sm font-semibold">
          Linha #{{ row.id }}
        </h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="side in [{ label: 'Salvo', facts: row.previous_product_snapshot }, { label: 'Proposto para revisão', facts: row.product_snapshot }]" :key="side.label" class="min-w-0 space-y-1 text-sm">
            <h4 class="font-medium">
              {{ side.label }}
            </h4>
            <p>NCM {{ value(product(side.facts).ncm) }} · origem {{ value(product(side.facts).origin_code) }}</p>
            <p>CFOP efetivo {{ value(cfop(side.facts).effective_cfop ?? erpTaxRecord(side.facts.operation_nature).cfop) }} · padrão da natureza {{ value(cfop(side.facts).default_cfop) }}</p>
            <p>{{ erpTaxSourceLabel(cfop(side.facts)) }} · revisão {{ value(cfop(side.facts).configuration_revision) }}</p>
            <p v-for="(source, field) in taxSources(side.facts)" :key="field">
              {{ field }}: {{ value(product(side.facts)[field]) }} · {{ erpTaxSourceLabel(source) }}
            </p>
            <p v-if="Array.isArray(cfop(side.facts).unresolved_fields) && (cfop(side.facts).unresolved_fields as unknown[]).length" class="text-warning">
              Pendências: {{ (cfop(side.facts).unresolved_fields as unknown[]).join(', ') }}
            </p>
          </div>
        </div>
      </div>
    </section>
    <div class="flex flex-wrap justify-end gap-2">
      <UButton
        type="button"
        color="neutral"
        variant="outline"
        label="Voltar ao detalhe"
        :disabled="busy"
        @click="emit('cancel')"
      />
      <UButton
        type="button"
        label="Aplicar revisão"
        :disabled="!preview || loading || busy || disabled"
        :loading="busy"
        @click="emit('apply', options())"
      />
    </div>
  </section>
</template>
