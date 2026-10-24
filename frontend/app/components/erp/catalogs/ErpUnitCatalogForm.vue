<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpCatalogEditorMode, ErpUnitRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  formId?: string
  record: ErpUnitRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const form = reactive({ short_name: '', actual_name: '', allow_decimal: true, base_unit_id: null as number | null, conversion_factor: '' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', dirty => emit('dirty-change', dirty))

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.short_name = record?.short_name ?? ''
  form.actual_name = record?.actual_name ?? ''
  form.allow_decimal = record?.allow_decimal ?? true
  form.base_unit_id = record?.base_unit_id ?? null
  form.conversion_factor = record?.conversion_factor ?? ''
  void nextTick(markClean)
}, { immediate: true })

function submit() {
  if (disabled.value)
    return
  emit('save', {
    short_name: form.short_name,
    actual_name: form.actual_name,
    allow_decimal: form.allow_decimal,
    base_unit_id: form.base_unit_id,
    conversion_factor: form.conversion_factor === '' ? null : form.conversion_factor
  })
}
</script>

<template>
  <UForm
    :id="formId"
    :state="form"
    :disabled="disabled"
    class="space-y-4"
    @submit="submit"
  >
    <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2">
      <legend class="text-highlighted font-medium sm:col-span-2">
        Identificação
      </legend>
      <ErpCatalogField
        label="Sigla"
        name="short_name"
        required
        :error="fieldErrors?.short_name?.[0]"
      >
        <UInput
          v-model="form.short_name"
          name="short_name"
          required
          maxlength="16"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField
        label="Nome da unidade"
        name="actual_name"
        required
        :error="fieldErrors?.actual_name?.[0]"
      >
        <UInput
          v-model="form.actual_name"
          name="actual_name"
          required
          maxlength="64"
          class="w-full"
        />
      </ErpCatalogField>
    </fieldset>
    <fieldset :disabled="disabled" class="grid min-w-0 gap-4 sm:grid-cols-2">
      <legend class="text-highlighted font-medium sm:col-span-2">
        Conversão e quantidades
      </legend>
      <ErpCatalogReferenceSelect
        v-model="form.base_unit_id"
        :path="erpCatalogRoutes.units"
        label="Unidade base"
        label-key="short_name"
        name="base_unit_id"
        optional
        :disabled="disabled"
        :error="fieldErrors?.base_unit_id?.[0]"
      />
      <ErpCatalogField label="Fator de conversão" name="conversion_factor" :error="fieldErrors?.conversion_factor?.[0]">
        <UInput
          v-model="form.conversion_factor"
          name="conversion_factor"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          placeholder="Ex.: 1000.00000000"
          class="w-full"
        />
      </ErpCatalogField>
      <UFormField name="allow_decimal" :error="fieldErrors?.allow_decimal?.[0]" class="sm:col-span-2">
        <UCheckbox v-model="form.allow_decimal" name="allow_decimal" label="Permite quantidades fracionárias nesta unidade" />
      </UFormField>
    </fieldset>
  </UForm>
</template>
