<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpVehicleRecord } from '#shared/types/erpCatalogs'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  record: ErpVehicleRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({
  erp_carrier_id: null as number | null, vehicle_type: '', plate: '', uf: '', color: '', model: '', brand: '', wheel_type: '', body_type: '', owner_type: ''
})
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.erp_carrier_id = record?.erp_carrier_id ?? null
  form.vehicle_type = record?.vehicle_type ?? ''
  form.plate = record?.plate ?? ''
  form.uf = record?.uf ?? ''
  form.color = record?.color ?? ''
  form.model = record?.model ?? ''
  form.brand = record?.brand ?? ''
  form.wheel_type = record?.wheel_type ?? ''
  form.body_type = record?.body_type ?? ''
  form.owner_type = record?.owner_type ?? ''
}, { immediate: true })

function submit() {
  if (disabled.value)
    return
  emit('save', {
    erp_carrier_id: form.erp_carrier_id,
    vehicle_type: form.vehicle_type || null,
    plate: form.plate,
    uf: form.uf || null,
    color: form.color || null,
    model: form.model || null,
    brand: form.brand || null,
    wheel_type: form.wheel_type || null,
    body_type: form.body_type || null,
    owner_type: form.owner_type || null
  })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
      <div class="space-y-1">
        <ErpCatalogReferenceSelect
          v-model="form.erp_carrier_id"
          :path="erpCatalogRoutes.carriers"
          label="Transportadora"
          label-key="legal_name"
          optional
          :disabled="disabled"
        />
        <p v-if="fieldErrors?.erp_carrier_id?.[0]" role="alert" class="text-error text-xs">
          {{ fieldErrors.erp_carrier_id[0] }}
        </p>
      </div>
      <ErpCatalogField label="Placa" required :error="fieldErrors?.plate?.[0]">
        <UInput
          v-model="form.plate"
          name="plate"
          required
          maxlength="16"
          autocomplete="off"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Tipo de veículo" :error="fieldErrors?.vehicle_type?.[0]">
        <UInput
          v-model="form.vehicle_type"
          name="vehicle_type"
          maxlength="32"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="UF" :error="fieldErrors?.uf?.[0]">
        <UInput
          v-model="form.uf"
          name="uf"
          maxlength="2"
          autocomplete="address-level1"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Modelo" :error="fieldErrors?.model?.[0]">
        <UInput
          v-model="form.model"
          name="model"
          maxlength="64"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Marca" :error="fieldErrors?.brand?.[0]">
        <UInput
          v-model="form.brand"
          name="brand"
          maxlength="64"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Cor" :error="fieldErrors?.color?.[0]">
        <UInput
          v-model="form.color"
          name="color"
          maxlength="32"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Tipo de roda" :error="fieldErrors?.wheel_type?.[0]">
        <UInput
          v-model="form.wheel_type"
          name="wheel_type"
          maxlength="32"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Tipo de carroceria" :error="fieldErrors?.body_type?.[0]">
        <UInput
          v-model="form.body_type"
          name="body_type"
          maxlength="32"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Tipo de proprietário" :error="fieldErrors?.owner_type?.[0]">
        <UInput
          v-model="form.owner_type"
          name="owner_type"
          maxlength="32"
          class="w-full"
        />
      </ErpCatalogField>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar veículo' : 'Salvar veículo'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
