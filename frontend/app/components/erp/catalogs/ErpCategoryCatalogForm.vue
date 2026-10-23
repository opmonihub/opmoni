<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpCategoryRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  record: ErpCategoryRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ name: '', parent_id: null as number | null })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.parent_id = record?.parent_id ?? null
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, parent_id: form.parent_id })
}
</script>

<template>
  <UForm
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
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar categoria' : 'Salvar categoria'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </UForm>
</template>
