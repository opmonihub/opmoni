<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpPriceGroupRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  record: ErpPriceGroupRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ name: '', percentage_adjustment: '0.0000', is_default: false })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.percentage_adjustment = record?.percentage_adjustment ?? '0.0000'
  form.is_default = record?.is_default ?? false
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, percentage_adjustment: form.percentage_adjustment, is_default: form.is_default })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
      <ErpCatalogField label="Grupo de preço" required :error="fieldErrors?.name?.[0]">
        <UInput
          v-model="form.name"
          name="name"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Ajuste percentual" :error="fieldErrors?.percentage_adjustment?.[0]">
        <UInput
          v-model="form.percentage_adjustment"
          name="percentage_adjustment"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <label class="flex items-center gap-2 text-sm sm:col-span-2">
        <input v-model="form.is_default" name="is_default" type="checkbox">
        <span>Grupo padrão da empresa</span>
      </label>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar grupo' : 'Salvar grupo'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
