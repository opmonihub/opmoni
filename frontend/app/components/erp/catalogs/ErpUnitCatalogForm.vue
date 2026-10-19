<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpUnitRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  record: ErpUnitRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ short_name: '', actual_name: '', allow_decimal: true, base_unit_id: null as number | null, conversion_factor: '' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.short_name = record?.short_name ?? ''
  form.actual_name = record?.actual_name ?? ''
  form.allow_decimal = record?.allow_decimal ?? true
  form.base_unit_id = record?.base_unit_id ?? null
  form.conversion_factor = record?.conversion_factor ?? ''
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
  <form class="space-y-4" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
      <ErpCatalogField label="Sigla" required :error="fieldErrors?.short_name?.[0]">
        <UInput
          v-model="form.short_name"
          name="short_name"
          required
          maxlength="16"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Nome da unidade" required :error="fieldErrors?.actual_name?.[0]">
        <UInput
          v-model="form.actual_name"
          name="actual_name"
          required
          maxlength="64"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogReferenceSelect
        v-model="form.base_unit_id"
        :path="erpCatalogRoutes.units"
        label="Unidade base"
        label-key="short_name"
        optional
        :disabled="disabled"
      />
      <ErpCatalogField label="Fator de conversão" :error="fieldErrors?.conversion_factor?.[0]">
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
      <label class="flex items-center gap-2 text-sm sm:col-span-2">
        <input v-model="form.allow_decimal" name="allow_decimal" type="checkbox">
        <span>Permite quantidades fracionárias nesta unidade</span>
      </label>
      <p v-if="fieldErrors?.base_unit_id?.[0]" role="alert" class="text-error text-xs sm:col-span-2">
        {{ fieldErrors.base_unit_id[0] }}
      </p>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar unidade' : 'Salvar unidade'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
