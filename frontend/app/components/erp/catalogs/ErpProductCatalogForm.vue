<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpProductRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

type FormVariant = {
  id?: number
  name: string
  sku: string
  sale_price: string
  barcode: string
  status?: 'archived'
  archiveRequested: boolean
}

const props = defineProps<{
  record: ErpProductRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  canArchive?: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const newVariant = (): FormVariant => ({ name: 'Padrão', sku: '', sale_price: '', barcode: '', archiveRequested: false })
const form = reactive({
  name: '', sku: '', kind: 'goods' as 'goods' | 'service', track_stock: true,
  erp_unit_id: null as number | null, erp_brand_id: null as number | null, erp_category_id: null as number | null,
  sale_price: '0.0000', warranty_months: '', warranty_description: '', barcode: '', variants: [] as FormVariant[]
})
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const pendingVariantArchive = ref<number | null>(null)
const variantArchiveOpen = computed({
  get: () => pendingVariantArchive.value !== null,
  set: (open: boolean) => {
    if (!open && !props.busy)
      pendingVariantArchive.value = null
  }
})
const variantArchiveDescription = computed(() => {
  const variant = pendingVariantArchive.value === null ? null : form.variants[pendingVariantArchive.value]
  return variant
    ? `Arquivar a variante ${variant.name}? O histórico existente será mantido.`
    : 'Confirme o arquivamento da variante. O histórico existente será mantido.'
})

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.sku = record?.sku ?? ''
  form.kind = record?.kind ?? 'goods'
  form.track_stock = record?.track_stock ?? true
  form.erp_unit_id = record?.erp_unit_id ?? null
  form.erp_brand_id = record?.erp_brand_id ?? null
  form.erp_category_id = record?.erp_category_id ?? null
  form.sale_price = record?.sale_price ?? '0.0000'
  form.warranty_months = record?.warranty_months === null || record?.warranty_months === undefined ? '' : String(record.warranty_months)
  form.warranty_description = record?.warranty_description ?? ''
  form.barcode = record?.barcode ?? ''
  form.variants = record?.variants.length
    ? record.variants.map(variant => ({
        id: variant.id,
        name: variant.name,
        sku: variant.sku ?? '',
        sale_price: variant.sale_price ?? '',
        barcode: variant.barcode ?? '',
        ...(variant.status === 'archived' ? { status: 'archived' as const } : {}),
        archiveRequested: variant.status === 'archived'
      }))
    : [newVariant()]
  pendingVariantArchive.value = null
}, { immediate: true })

watch(() => form.kind, (kind) => {
  if (kind === 'service')
    form.track_stock = false
})

function addVariant() {
  if (!disabled.value)
    form.variants.push(newVariant())
}

function openVariantArchive(index: number) {
  const variant = form.variants[index]
  if (disabled.value || !props.canArchive || !variant?.id || variant.status === 'archived')
    return
  pendingVariantArchive.value = index
}

function confirmVariantArchive() {
  const index = pendingVariantArchive.value
  if (disabled.value || index === null || !props.canArchive || !form.variants[index])
    return
  form.variants[index]!.archiveRequested = true
  form.variants[index]!.status = 'archived'
  pendingVariantArchive.value = null
}

function variantIdError(index: number): string | undefined {
  return props.fieldErrors?.[`variants.${index}.id`]?.[0]
}

function submit() {
  if (disabled.value)
    return
  const variants = form.variants.map((variant) => {
    const row: Record<string, unknown> = {
      ...(variant.id ? { id: variant.id } : {}),
      name: variant.name,
      sku: variant.sku || null,
      sale_price: variant.sale_price || null,
      barcode: variant.barcode || null
    }
    if (variant.archiveRequested && props.canArchive)
      row.status = 'archived'
    return row
  })
  emit('save', {
    name: form.name,
    sku: form.sku || null,
    kind: form.kind,
    track_stock: form.kind === 'service' ? false : form.track_stock,
    erp_unit_id: form.erp_unit_id,
    erp_brand_id: form.erp_brand_id,
    erp_category_id: form.erp_category_id,
    sale_price: form.sale_price,
    warranty_months: form.warranty_months === '' ? null : Number(form.warranty_months),
    warranty_description: form.warranty_description || null,
    barcode: form.barcode || null,
    variants
  })
}
</script>

<template>
  <form class="space-y-5" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
      <ErpCatalogField label="Nome" required :error="fieldErrors?.name?.[0]">
        <UInput
          v-model="form.name"
          name="name"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="SKU" :error="fieldErrors?.sku?.[0]">
        <UInput
          v-model="form.sku"
          name="sku"
          maxlength="64"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Tipo" required :error="fieldErrors?.kind?.[0]">
        <select
          v-model="form.kind"
          name="kind"
          required
          class="border-default bg-default w-full rounded-md border px-3 py-2 text-sm"
        >
          <option value="goods">
            Mercadoria
          </option>
          <option value="service">
            Serviço
          </option>
        </select>
      </ErpCatalogField>
      <ErpCatalogField label="Preço de venda" :error="fieldErrors?.sale_price?.[0]">
        <UInput
          v-model="form.sale_price"
          name="sale_price"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogReferenceSelect
        v-model="form.erp_unit_id"
        :path="erpCatalogRoutes.units"
        label="Unidade"
        label-key="short_name"
        :disabled="disabled"
      />
      <ErpCatalogReferenceSelect
        v-model="form.erp_brand_id"
        :path="erpCatalogRoutes.brands"
        label="Marca"
        label-key="name"
        optional
        :disabled="disabled"
      />
      <ErpCatalogReferenceSelect
        v-model="form.erp_category_id"
        :path="erpCatalogRoutes.categories"
        label="Categoria"
        label-key="name"
        optional
        :disabled="disabled"
      />
      <label class="flex items-center gap-2 text-sm sm:col-span-2">
        <input
          v-model="form.track_stock"
          name="track_stock"
          type="checkbox"
          :disabled="form.kind === 'service'"
        >
        <span>Controla estoque</span>
      </label>
      <ErpCatalogField label="Garantia (meses)" :error="fieldErrors?.warranty_months?.[0]">
        <UInput
          v-model="form.warranty_months"
          name="warranty_months"
          type="number"
          min="0"
          max="600"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Descrição da garantia" :error="fieldErrors?.warranty_description?.[0]">
        <UInput
          v-model="form.warranty_description"
          name="warranty_description"
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Código de barras" :error="fieldErrors?.barcode?.[0]">
        <UInput
          v-model="form.barcode"
          name="barcode"
          maxlength="64"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <p v-if="fieldErrors?.erp_unit_id?.[0]" role="alert" class="text-error text-xs sm:col-span-2">
        {{ fieldErrors.erp_unit_id[0] }}
      </p>
      <p v-if="fieldErrors?.erp_brand_id?.[0]" role="alert" class="text-error text-xs sm:col-span-2">
        {{ fieldErrors.erp_brand_id[0] }}
      </p>
      <p v-if="fieldErrors?.erp_category_id?.[0]" role="alert" class="text-error text-xs sm:col-span-2">
        {{ fieldErrors.erp_category_id[0] }}
      </p>
    </fieldset>

    <section aria-labelledby="product-variants-title" class="space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 id="product-variants-title" class="text-highlighted font-medium">
            Variantes
          </h3>
          <p class="text-muted text-xs">
            Use variantes para opções do mesmo produto.
          </p>
        </div>
        <UButton
          v-if="mode !== 'detail'"
          label="Adicionar variante"
          color="neutral"
          variant="soft"
          :disabled="disabled"
          @click="addVariant"
        />
      </div>
      <div v-for="(variant, index) in form.variants" :key="variant.id ?? `new-${index}`" class="border-default grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
        <p class="text-muted text-xs sm:col-span-2">
          {{ variant.id ? `Variante #${variant.id}` : 'Nova variante' }}<span v-if="variant.status === 'archived'"> · arquivada</span>
        </p>
        <ErpCatalogField label="Nome da variante" required :error="fieldErrors?.[`variants.${index}.name`]?.[0]">
          <UInput
            v-model="variant.name"
            :name="`variants.${index}.name`"
            :disabled="disabled"
            required
            maxlength="255"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="SKU da variante" :error="fieldErrors?.[`variants.${index}.sku`]?.[0]">
          <UInput
            v-model="variant.sku"
            :name="`variants.${index}.sku`"
            :disabled="disabled"
            maxlength="64"
            autocomplete="off"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Preço da variante" :error="fieldErrors?.[`variants.${index}.sale_price`]?.[0]">
          <UInput
            v-model="variant.sale_price"
            :name="`variants.${index}.sale_price`"
            :disabled="disabled"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Código de barras da variante" :error="fieldErrors?.[`variants.${index}.barcode`]?.[0]">
          <UInput
            v-model="variant.barcode"
            :name="`variants.${index}.barcode`"
            :disabled="disabled"
            maxlength="64"
            autocomplete="off"
            class="w-full"
          />
        </ErpCatalogField>
        <div v-if="mode !== 'detail' && !variant.id" class="sm:col-span-2">
          <UButton
            label="Remover variante nova"
            color="neutral"
            variant="ghost"
            :disabled="disabled || form.variants.length === 1"
            @click="form.variants.splice(index, 1)"
          />
        </div>
        <div v-else-if="mode !== 'detail' && variant.id && variant.status !== 'archived' && canArchive" class="sm:col-span-2">
          <UButton
            label="Arquivar variante"
            color="error"
            variant="soft"
            :disabled="disabled || busy"
            @click="openVariantArchive(index)"
          />
        </div>
        <p v-if="variantIdError(index)" role="alert" class="text-error text-xs sm:col-span-2">
          {{ variantIdError(index) }}
        </p>
      </div>
      <p v-if="fieldErrors?.variants?.[0]" role="alert" class="text-error text-xs">
        {{ fieldErrors.variants[0] }}
      </p>
    </section>

    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar produto' : 'Salvar produto'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
  <UModal
    v-model:open="variantArchiveOpen"
    title="Arquivar variante"
    :description="variantArchiveDescription"
  >
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          label="Cancelar"
          color="neutral"
          variant="ghost"
          :disabled="busy"
          @click="variantArchiveOpen = false"
        />
        <UButton
          label="Confirmar arquivamento"
          color="error"
          :disabled="disabled || !canArchive"
          :loading="busy"
          @click="confirmVariantArchive"
        />
      </div>
    </template>
  </UModal>
</template>
