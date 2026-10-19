<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpDriverRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  record: ErpDriverRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ name: '', tax_id: '', license_number: '' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.tax_id = record?.tax_id ?? ''
  form.license_number = record?.license_number ?? ''
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, tax_id: form.tax_id || null, license_number: form.license_number || null })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
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
      <ErpCatalogField label="CPF" :error="fieldErrors?.tax_id?.[0]">
        <UInput
          v-model="form.tax_id"
          name="tax_id"
          maxlength="20"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="CNH" :error="fieldErrors?.license_number?.[0]">
        <UInput
          v-model="form.license_number"
          name="license_number"
          maxlength="32"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar motorista' : 'Salvar motorista'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
