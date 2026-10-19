<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpContactRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

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
    address: Object.keys(address).length ? address : null
  })
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
          autocomplete="organization-title"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Nome fantasia" :error="fieldErrors?.trade_name?.[0]">
        <UInput v-model="form.trade_name" name="trade_name" class="w-full" />
      </ErpCatalogField>
      <ErpCatalogField label="CPF/CNPJ" :error="fieldErrors?.tax_id?.[0]">
        <UInput
          v-model="form.tax_id"
          name="tax_id"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="E-mail" :error="fieldErrors?.email?.[0]">
        <UInput
          v-model="form.email"
          name="email"
          type="email"
          autocomplete="email"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Telefone" :error="fieldErrors?.phone?.[0]">
        <UInput
          v-model="form.phone"
          name="phone"
          type="tel"
          autocomplete="tel"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Observações do cadastro" :error="fieldErrors?.notes?.[0]">
        <textarea
          v-model="form.notes"
          name="notes"
          rows="3"
          class="border-default bg-default w-full rounded-md border px-3 py-2 text-sm"
        />
      </ErpCatalogField>
      <div class="flex flex-wrap gap-5 sm:col-span-2">
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_customer" name="is_customer" type="checkbox"><span>Cliente</span></label>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_supplier" name="is_supplier" type="checkbox"><span>Fornecedor</span></label>
      </div>
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
        :label="mode === 'create' ? 'Criar contato' : 'Salvar contato'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
