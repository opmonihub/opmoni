<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpContactRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  record: ErpContactRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const addressKeys = ['street_type', 'street', 'number', 'complement', 'district', 'postal_code', 'city', 'state'] as const
const form = reactive({
  name: '', trade_name: '', tax_id: '', email: '', phone: '', notes: '', is_customer: true, is_supplier: false,
  erp_customer_group_id: null as number | null,
  address: {} as Record<string, string>,
  street_type: '', street: '', number: '', complement: '', district: '', postal_code: '', city: '', state: ''
})
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  const address = record?.address ?? {}
  form.name = record?.name ?? ''
  form.trade_name = record?.trade_name ?? ''
  form.tax_id = record?.tax_id ?? ''
  form.email = record?.email ?? ''
  form.phone = record?.phone ?? ''
  form.notes = record?.notes ?? ''
  form.is_customer = record?.is_customer ?? true
  form.is_supplier = record?.is_supplier ?? false
  form.erp_customer_group_id = record?.erp_customer_group_id ?? null
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
  emit('save', {
    name: form.name,
    trade_name: form.trade_name || null,
    tax_id: form.tax_id || null,
    email: form.email || null,
    phone: form.phone || null,
    notes: form.notes || null,
    is_customer: form.is_customer,
    is_supplier: form.is_supplier,
    erp_customer_group_id: form.erp_customer_group_id,
    address: Object.keys(address).length ? address : null
  })
}
</script>

<template>
  <UForm
    :state="form"
    :disabled="disabled"
    class="space-y-5"
    @submit="submit"
  >
    <fieldset :disabled="disabled" class="min-w-0 space-y-4">
      <legend class="text-highlighted font-medium">
        Identificação
      </legend>
      <div class="grid gap-4 sm:grid-cols-2">
        <ErpCatalogField
          label="Nome"
          name="name"
          required
          :error="fieldErrors?.name?.[0]"
        >
          <UInput
            v-model="form.name"
            name="name"
            required
            autocomplete="organization-title"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Nome fantasia" name="trade_name" :error="fieldErrors?.trade_name?.[0]">
          <UInput v-model="form.trade_name" name="trade_name" class="w-full" />
        </ErpCatalogField>
        <ErpCatalogField label="CPF/CNPJ" name="tax_id" :error="fieldErrors?.tax_id?.[0]">
          <UInput
            v-model="form.tax_id"
            name="tax_id"
            autocomplete="off"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="E-mail" name="email" :error="fieldErrors?.email?.[0]">
          <UInput
            v-model="form.email"
            name="email"
            type="email"
            autocomplete="email"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Telefone" name="phone" :error="fieldErrors?.phone?.[0]">
          <UInput
            v-model="form.phone"
            name="phone"
            type="tel"
            autocomplete="tel"
            class="w-full"
          />
        </ErpCatalogField>
      </div>
    </fieldset>
    <fieldset :disabled="disabled" class="min-w-0 space-y-3">
      <legend class="text-highlighted font-medium">
        Relação com a empresa
      </legend>
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField name="is_customer" :error="fieldErrors?.is_customer?.[0]">
          <UCheckbox v-model="form.is_customer" name="is_customer" label="Cliente" />
        </UFormField>
        <UFormField name="is_supplier" :error="fieldErrors?.is_supplier?.[0]">
          <UCheckbox v-model="form.is_supplier" name="is_supplier" label="Fornecedor" />
        </UFormField>
        <ErpCatalogReferenceSelect
          v-model="form.erp_customer_group_id"
          :path="erpCatalogRoutes.customerGroups"
          label="Grupo de clientes"
          label-key="name"
          name="erp_customer_group_id"
          optional
          :disabled="disabled"
          :error="fieldErrors?.erp_customer_group_id?.[0]"
        />
      </div>
    </fieldset>
    <fieldset :disabled="disabled" class="min-w-0 space-y-4">
      <legend class="text-highlighted font-medium">
        Endereço
      </legend>
      <div class="grid gap-4 sm:grid-cols-2">
        <ErpCatalogField label="Tipo de logradouro" name="street_type" :error="fieldErrors?.address?.[0]">
          <UInput v-model="form.street_type" name="street_type" class="w-full" />
        </ErpCatalogField>
        <ErpCatalogField label="Logradouro" name="street">
          <UInput
            v-model="form.street"
            name="street"
            autocomplete="address-line1"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Número" name="number">
          <UInput v-model="form.number" name="number" class="w-full" />
        </ErpCatalogField>
        <ErpCatalogField label="Complemento" name="complement">
          <UInput
            v-model="form.complement"
            name="complement"
            autocomplete="address-line2"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Bairro" name="district">
          <UInput v-model="form.district" name="district" class="w-full" />
        </ErpCatalogField>
        <ErpCatalogField label="CEP" name="postal_code">
          <UInput
            v-model="form.postal_code"
            name="postal_code"
            inputmode="numeric"
            autocomplete="postal-code"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="Cidade" name="city">
          <UInput
            v-model="form.city"
            name="city"
            autocomplete="address-level2"
            class="w-full"
          />
        </ErpCatalogField>
        <ErpCatalogField label="UF" name="state">
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
    <fieldset :disabled="disabled" class="min-w-0 space-y-4">
      <legend class="text-highlighted font-medium">
        Observações
      </legend>
      <ErpCatalogField label="Observações do cadastro" name="notes" :error="fieldErrors?.notes?.[0]">
        <UTextarea
          v-model="form.notes"
          name="notes"
          :rows="3"
          class="w-full"
        />
      </ErpCatalogField>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar contato' : 'Salvar contato'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </UForm>
</template>
