<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpCatalogEditorMode, ErpDiscountRuleRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  formId?: string
  record: ErpDiscountRuleRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ 'save': [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const form = reactive({ name: '', kind: 'percentage' as 'percentage' | 'fixed', value: '0.0000', coupon_code: '' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', dirty => emit('dirty-change', dirty))

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.name = record?.name ?? ''
  form.kind = record?.kind ?? 'percentage'
  form.value = record?.value ?? '0.0000'
  form.coupon_code = record?.coupon_code ?? ''
  void nextTick(markClean)
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: form.name, kind: form.kind, value: form.value, coupon_code: form.coupon_code || null })
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
        Regra comercial
      </legend>
      <ErpCatalogField
        label="Nome da regra"
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
        label="Tipo de desconto"
        required
        name="kind"
        :error="fieldErrors?.kind?.[0]"
      >
        <USelect
          v-model="form.kind"
          name="kind"
          :items="[{ label: 'Percentual', value: 'percentage' }, { label: 'Valor fixo', value: 'fixed' }]"
          required
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField
        label="Valor"
        required
        name="value"
        :error="fieldErrors?.value?.[0]"
      >
        <UInput
          v-model="form.value"
          name="value"
          required
          type="text"
          inputmode="decimal"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Cupom" name="coupon_code" :error="fieldErrors?.coupon_code?.[0]">
        <UInput
          v-model="form.coupon_code"
          name="coupon_code"
          maxlength="64"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <p class="text-muted text-xs sm:col-span-2">
        As condições existentes continuam aplicadas quando você altera os demais campos.
      </p>
    </fieldset>
  </UForm>
</template>
