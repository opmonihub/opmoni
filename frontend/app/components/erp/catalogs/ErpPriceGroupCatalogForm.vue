<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpCatalogEditorMode, ErpPriceGroupRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  formId?: string
  record: ErpPriceGroupRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const form = reactive({ name: '', percentage_adjustment: '0.0000', is_default: false })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', dirty => emit('dirty-change', dirty))

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.percentage_adjustment = record?.percentage_adjustment ?? '0.0000'
  form.is_default = record?.is_default ?? false
  void nextTick(markClean)
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, percentage_adjustment: form.percentage_adjustment, is_default: form.is_default })
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
        Grupo e ajuste
      </legend>
      <ErpCatalogField
        label="Grupo de preço"
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
      <ErpCatalogField label="Ajuste percentual" name="percentage_adjustment" :error="fieldErrors?.percentage_adjustment?.[0]">
        <UInput
          v-model="form.percentage_adjustment"
          name="percentage_adjustment"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <UFormField name="is_default" :error="fieldErrors?.is_default?.[0]" class="sm:col-span-2">
        <UCheckbox v-model="form.is_default" name="is_default" label="Grupo padrão da empresa" />
      </UFormField>
    </fieldset>
  </UForm>
</template>
