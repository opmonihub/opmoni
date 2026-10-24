<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpBrandRecord, ErpCatalogEditorMode } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  formId?: string
  record: ErpBrandRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ 'save': [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const form = reactive({ name: '' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', dirty => emit('dirty-change', dirty))

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  void nextTick(markClean)
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name })
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
    <fieldset :disabled="disabled" class="space-y-4">
      <legend class="text-highlighted font-medium">
        Identificação
      </legend>
      <ErpCatalogField
        label="Marca"
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
    </fieldset>
  </UForm>
</template>
