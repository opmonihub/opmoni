<script setup lang="ts">
import type { ErpPurchaseEstablishment, ErpPurchaseSupplier, ErpPurchaseUnit, ErpPurchaseVariant } from '#shared/types/erpBusiness'
import type { ErpPurchasePreviewState } from '~/composables/useErpPurchaseWorkspace'
import { formatPurchaseDecimal, purchaseUnitFactor } from '#shared/utils/erpPurchase'

const props = defineProps<{
  establishments: ErpPurchaseEstablishment[]
  suppliers: ErpPurchaseSupplier[]
  variants: ErpPurchaseVariant[]
  units: ErpPurchaseUnit[]
  loading?: boolean
  refreshing?: boolean
  error?: string
}>()
const state = defineModel<ErpPurchasePreviewState | null>({ required: true })
const establishmentId = defineModel<number | null>('establishmentId', { required: true })
const emit = defineEmits<{
  upload: [file: File, establishmentId: number]
  refresh: []
  confirm: []
  cancel: []
}>()
const fileInput = ref<HTMLInputElement | null>(null)
const localError = ref('')
const establishmentOptions = computed(() => props.establishments.map(row => ({ id: row.id, label: row.name })))
const supplierOptions = computed(() => props.suppliers.map(row => ({ id: row.id, label: row.trade_name || row.name, description: row.tax_id || '' })))
const variantOptions = computed(() => props.variants.map(row => ({ id: row.id, label: row.name, description: row.sku || row.product.name })))
const confirmable = computed(() => Boolean(state.value && state.value.preview.state === 'pending' && !state.value.preview.duplicate && state.value.preview.errors.length === 0))

function upload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  localError.value = ''
  if (!establishmentId.value) {
    localError.value = 'Selecione o estabelecimento antes de conferir o XML.'
    if (fileInput.value) fileInput.value.value = ''
    return
  }
  emit('upload', file, establishmentId.value)
  if (fileInput.value) fileInput.value.value = ''
}
function setMapping(index: number, key: 'erp_product_variant_id' | 'erp_unit_id' | 'unit_conversion_factor', value: number | string | null | undefined) {
  if (!state.value) return
  const mappings = state.value.mappings.map((mapping, currentIndex) => {
    if (currentIndex !== index) return { ...mapping }
    if (key === 'erp_product_variant_id') {
      const variantId = typeof value === 'number' ? value : 0
      const variant = props.variants.find(row => row.id === variantId)
      const next = { ...mapping, erp_product_variant_id: variantId }
      if (variant) {
        next.erp_unit_id = variant.product.erp_unit_id
        next.unit_conversion_factor = '1.00000000'
      }
      return next
    }
    if (key === 'erp_unit_id') {
      const unitId = typeof value === 'number' ? value : undefined
      const variant = props.variants.find(row => row.id === mapping.erp_product_variant_id)
      const factor = unitId && variant ? purchaseUnitFactor(unitId, variant.product.erp_unit_id, props.units) : null
      return { ...mapping, erp_unit_id: unitId, unit_conversion_factor: factor ?? '' }
    }
    return { ...mapping, unit_conversion_factor: String(value ?? '') }
  })
  state.value = { ...state.value, mappings }
}
function compatibleUnits(variantId: number) {
  const variant = props.variants.find(row => row.id === variantId)
  return props.units.filter(unit => variant && purchaseUnitFactor(unit.id, variant.product.erp_unit_id, props.units) !== null)
}
function amount(value: string | null | undefined, currency: string) {
  if (value === null || value === undefined) return '—'
  try {
    return `${currency} ${formatPurchaseDecimal(value)}`
  } catch {
    return `${currency} ${value}`
  }
}
</script>

<template>
  <section class="space-y-5" aria-label="Prévia de XML de compra">
    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField label="Estabelecimento de entrada" name="xml.erp_establishment_id">
        <UInputMenu
          :model-value="establishmentId ?? undefined"
          :items="establishmentOptions"
          value-key="id"
          placeholder="Buscar estabelecimento"
          class="w-full"
          aria-label="Estabelecimento do XML"
          @update:model-value="establishmentId = $event ?? null"
        />
      </UFormField>
      <UFormField label="Arquivo XML" name="xml.file" hint="Até 2 MB. O arquivo é usado apenas para conferir e mapear a entrada.">
        <input
          ref="fileInput"
          type="file"
          accept=".xml,application/xml,text/xml"
          :disabled="loading || !establishmentId"
          class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-elevated file:px-3 file:py-2"
          @change="upload"
        >
      </UFormField>
    </div>
    <UAlert v-if="localError" color="error" :title="localError" />
    <UAlert v-if="error" color="error" :title="error" />
    <UAlert
      v-if="loading"
      color="info"
      title="Conferindo XML…"
      role="status"
    />

    <div v-if="state" class="space-y-4 rounded-lg border border-default p-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 class="font-semibold text-highlighted">
            Documento conferido
          </h3>
          <p class="mt-1 break-all text-sm text-muted">
            Chave {{ state.preview.chave }}
          </p>
          <p class="text-xs text-muted">
            Prévia {{ state.preview.state }} · expira {{ state.preview.expires_at }}
          </p>
        </div>
        <div class="flex gap-2">
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            :loading="refreshing"
            label="Atualizar estado"
            @click="emit('refresh')"
          />
          <UButton
            type="button"
            color="neutral"
            variant="ghost"
            label="Cancelar prévia"
            @click="emit('cancel')"
          />
        </div>
      </div>
      <UAlert v-if="state.preview.duplicate" color="error" title="Documento duplicado">
        <template #description>
          Esta chave já pertence à compra #{{ state.preview.duplicate_purchase_id ?? 'indisponível' }}. A confirmação fica bloqueada.
        </template>
      </UAlert>
      <UAlert
        v-for="(issue, index) in state.preview.errors"
        :key="`${issue.code ?? 'erro'}-${index}`"
        color="error"
        :title="issue.message ?? issue.code ?? 'XML bloqueado'"
      />
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-muted">
            Emitente
          </dt><dd class="text-highlighted">
            {{ state.preview.emit.name }} · {{ state.preview.emit.cpf_cnpj }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Destinatário
          </dt><dd class="text-highlighted">
            {{ state.preview.dest.name }} · {{ state.preview.dest.cpf_cnpj }}
          </dd>
        </div>
        <div v-for="(value, key) in state.preview.total" :key="key">
          <dt class="text-muted">
            {{ key }}
          </dt><dd class="tabular-nums">
            {{ value ?? '—' }}
          </dd>
        </div>
      </dl>
      <UFormField label="Fornecedor verificado" name="xml.erp_contact_id">
        <UInputMenu
          :model-value="state.erp_contact_id ?? undefined"
          :items="supplierOptions"
          value-key="id"
          placeholder="Associar fornecedor do emitente"
          class="w-full sm:max-w-xl"
          aria-label="Fornecedor correspondente ao emitente"
          @update:model-value="state = { ...state, erp_contact_id: $event ?? null }"
        />
      </UFormField>
      <div class="space-y-3">
        <h4 class="font-medium text-highlighted">
          Mapeie cada item explicitamente
        </h4>
        <article
          v-for="(line, index) in state.preview.det"
          :key="line.line"
          class="space-y-3 rounded-md bg-elevated/50 p-3"
          :data-xml-line="line.line"
        >
          <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 text-sm">
            <strong>Item {{ line.line }} · {{ line.xProd }}</strong>
            <span class="text-muted">{{ line.qCom }} {{ line.uCom }} × {{ line.vUnCom }}</span>
            <span v-if="line.amounts" class="tabular-nums">Total {{ amount(line.amounts.line_total, state.preview.total.currency ?? 'BRL') }}</span>
          </div>
          <div class="grid gap-3 sm:grid-cols-3">
            <UFormField label="Produto/variante">
              <UInputMenu
                :model-value="state.mappings[index]?.erp_product_variant_id"
                :items="variantOptions"
                value-key="id"
                placeholder="Buscar item"
                class="w-full"
                :aria-label="`Produto do XML item ${line.line}`"
                @update:model-value="setMapping(index, 'erp_product_variant_id', $event)"
              />
            </UFormField>
            <UFormField label="Unidade do estoque">
              <UInputMenu
                :model-value="state.mappings[index]?.erp_unit_id ?? undefined"
                :items="compatibleUnits(state.mappings[index]?.erp_product_variant_id ?? 0).map(unit => ({ id: unit.id, label: unit.short_name, description: unit.actual_name }))"
                value-key="id"
                placeholder="Selecionar unidade"
                class="w-full"
                :disabled="!state.mappings[index]?.erp_product_variant_id"
                :aria-label="`Unidade do XML item ${line.line}`"
                @update:model-value="setMapping(index, 'erp_unit_id', $event)"
              />
            </UFormField>
            <UFormField label="Fator de conversão" :name="`xml.items.${index}.unit_conversion_factor`">
              <UInput
                :model-value="state.mappings[index]?.unit_conversion_factor"
                type="text"
                inputmode="decimal"
                class="w-full tabular-nums"
                :aria-label="`Fator de conversão do XML item ${line.line}`"
                @update:model-value="setMapping(index, 'unit_conversion_factor', $event)"
              />
            </UFormField>
          </div>
        </article>
      </div>
      <div class="flex justify-end">
        <UButton
          type="button"
          icon="i-lucide-file-check-2"
          label="Confirmar como rascunho"
          :disabled="!confirmable || loading"
          :loading="loading"
          @click="emit('confirm')"
        />
      </div>
      <p class="text-xs text-muted">
        A confirmação cria somente um rascunho. O recebimento no estoque e o lançamento financeiro exigem a ação separada de lançar entrada.
      </p>
    </div>
  </section>
</template>
