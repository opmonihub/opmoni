<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpCatalogEditorMode, ErpCategoryRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  formId?: string
  record: ErpCategoryRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const form = reactive({ name: '', parent_id: null as number | null })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', dirty => emit('dirty-change', dirty))

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.parent_id = record?.parent_id ?? null
  void nextTick(markClean)
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, parent_id: form.parent_id })
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
        Identificação e hierarquia
      </legend>
      <ErpCatalogField
        label="Categoria"
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
      <ErpCatalogReferenceSelect
        v-model="form.parent_id"
        :path="erpCatalogRoutes.categories"
        label="Categoria superior"
        label-key="name"
        name="parent_id"
        optional
        :disabled="disabled"
        :error="fieldErrors?.parent_id?.[0]"
      />
    </fieldset>
  </UForm>
</template>
