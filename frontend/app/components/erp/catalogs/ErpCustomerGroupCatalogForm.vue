<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpCustomerGroupRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  record: ErpCustomerGroupRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ name: '', percentage_adjustment: '0.0000' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.percentage_adjustment = record?.percentage_adjustment ?? '0.0000'
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, percentage_adjustment: form.percentage_adjustment })
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
        Grupo e ajuste
      </legend>
      <ErpCatalogField
        label="Nome do grupo"
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
      <ErpCatalogField
        label="Ajuste percentual"
        name="percentage_adjustment"
        description="Pode ser positivo ou negativo."
        :error="fieldErrors?.percentage_adjustment?.[0]"
      >
        <UInput
          v-model="form.percentage_adjustment"
          name="percentage_adjustment"
          type="text"
          inputmode="decimal"
          maxlength="12"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar grupo' : 'Salvar grupo'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </UForm>
</template>
