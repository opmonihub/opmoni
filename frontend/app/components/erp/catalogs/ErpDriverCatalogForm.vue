<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpCatalogEditorMode, ErpDriverRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  formId?: string
  record: ErpDriverRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ 'save': [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const form = reactive({ name: '', tax_id: '', license_number: '' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', dirty => emit('dirty-change', dirty))

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.tax_id = record?.tax_id ?? ''
  form.license_number = record?.license_number ?? ''
  void nextTick(markClean)
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, tax_id: form.tax_id || null, license_number: form.license_number || null })
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
        label="Nome"
        required
        name="name"
        :error="fieldErrors?.name?.[0]"
      >
        <UInput
          v-model="form.name"
          name="name"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="CPF" name="tax_id" :error="fieldErrors?.tax_id?.[0]">
        <UInput
          v-model="form.tax_id"
          name="tax_id"
          maxlength="20"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="CNH" name="license_number" :error="fieldErrors?.license_number?.[0]">
        <UInput
          v-model="form.license_number"
          name="license_number"
          maxlength="32"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
    </fieldset>
  </UForm>
</template>
