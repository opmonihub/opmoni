<script setup lang="ts">
import type { ErpBrandRecord, ErpCatalogEditorMode } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  record: ErpBrandRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ name: '' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name })
}
</script>

<template>
  <UForm
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
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar marca' : 'Salvar marca'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </UForm>
</template>
