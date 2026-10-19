<script setup lang="ts">
import type { ErpCarrierRecord, ErpCatalogEditorMode } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  record: ErpCarrierRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const addressKeys = ['street_type', 'street', 'number', 'complement', 'district', 'postal_code', 'city', 'state'] as const
const form = reactive({
  legal_name: '', tax_id: '', ie: '', address: {} as Record<string, string>,
  street_type: '', street: '', number: '', complement: '', district: '', postal_code: '', city: '', state: ''
})
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  const address = record?.address ?? {}
  form.legal_name = record?.legal_name ?? ''
  form.tax_id = record?.tax_id ?? ''
  form.ie = record?.ie ?? ''
  form.address = { ...address }
  for (const key of addressKeys)
    form[key] = address[key] ?? ''
}, { immediate: true })

function submit() {
  if (disabled.value)
    return
  const editableKeys = new Set<string>(addressKeys)
  const address = Object.fromEntries(Object.entries(form.address).filter(([key]) => !editableKeys.has(key))) as Record<string, string>
  for (const key of addressKeys) {
    if (form[key] !== '')
      address[key] = form[key]
  }
  emit('save', { legal_name: form.legal_name, tax_id: form.tax_id || null, ie: form.ie || null, address: Object.keys(address).length ? address : null })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
      <ErpCatalogField label="Razão social" required :error="fieldErrors?.legal_name?.[0]">
        <UInput
          v-model="form.legal_name"
          name="legal_name"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="CPF/CNPJ" :error="fieldErrors?.tax_id?.[0]">
        <UInput
          v-model="form.tax_id"
          name="tax_id"
          maxlength="20"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Inscrição estadual" :error="fieldErrors?.ie?.[0]">
        <UInput
          v-model="form.ie"
          name="ie"
          maxlength="20"
          class="w-full"
        />
      </ErpCatalogField>
      <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2">
        <ErpCatalogField label="Tipo de logradouro" :error="fieldErrors?.address?.[0]">
          <UInput v-model="form.street_type" name="street_type" class="w-full" />
        </ErpCatalogField>
        <ErpCatalogField label="Logradouro">
          <UInput
            v-model="form.street"
            name="street"
            autocomplete="address-line1"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Número">
          <UInput v-model="form.number" name="number" class="w-full" />
        </ErpCatalogField>
        <ErpCatalogField label="Complemento">
          <UInput
            v-model="form.complement"
            name="complement"
            autocomplete="address-line2"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Bairro">
          <UInput v-model="form.district" name="district" class="w-full" />
        </ErpCatalogField>
        <ErpCatalogField label="CEP">
          <UInput
            v-model="form.postal_code"
            name="postal_code"
            inputmode="numeric"
            autocomplete="postal-code"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Cidade">
          <UInput
            v-model="form.city"
            name="city"
            autocomplete="address-level2"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="UF">
          <UInput
            v-model="form.state"
            name="state"
            maxlength="2"
            autocomplete="address-level1"
            class="w-full"
          />
        </ErpCatalogField>
      </div>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar transportadora' : 'Salvar transportadora'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
