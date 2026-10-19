<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpTaxRateRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  record: ErpTaxRateRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ erp_establishment_id: null as number | null, name: '', rate: '0.0000', tax_kind: 'icms' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.erp_establishment_id = record?.erp_establishment_id ?? null
  form.name = record?.name ?? ''
  form.rate = record?.rate ?? '0.0000'
  form.tax_kind = record?.tax_kind ?? 'icms'
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { erp_establishment_id: form.erp_establishment_id, name: form.name, rate: form.rate, tax_kind: form.tax_kind })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
      <div class="space-y-1">
        <ErpCatalogReferenceSelect
          v-model="form.erp_establishment_id"
          path="/erp/establishments"
          label="Estabelecimento"
          label-key="name"
          optional
          :disabled="disabled"
        />
        <p v-if="fieldErrors?.erp_establishment_id?.[0]" role="alert" class="text-error text-xs">
          {{ fieldErrors.erp_establishment_id[0] }}
        </p>
      </div>
      <ErpCatalogField label="Nome da alíquota" required :error="fieldErrors?.name?.[0]">
        <UInput
          v-model="form.name"
          name="name"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Alíquota" required :error="fieldErrors?.rate?.[0]">
        <UInput
          v-model="form.rate"
          name="rate"
          required
          type="text"
          inputmode="decimal"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Tipo de tributo" :error="fieldErrors?.tax_kind?.[0]">
        <UInput
          v-model="form.tax_kind"
          name="tax_kind"
          maxlength="32"
          class="w-full"
        />
      </ErpCatalogField>
      <p class="text-muted text-xs sm:col-span-2">
        As demais informações fiscais da alíquota permanecem preservadas ao editar estes campos.
      </p>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar alíquota' : 'Salvar alíquota'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
