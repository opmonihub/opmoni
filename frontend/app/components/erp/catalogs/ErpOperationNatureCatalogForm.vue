<script setup lang="ts">
import type { ErpCatalogEditorMode, ErpOperationNatureRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

const props = defineProps<{
  record: ErpOperationNatureRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const form = reactive({ erp_establishment_id: null as number | null, cfop: '', description: '', movement: 'out' as 'in' | 'out' })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.erp_establishment_id = record?.erp_establishment_id ?? null
  form.cfop = record?.cfop ?? ''
  form.description = record?.description ?? ''
  form.movement = record?.movement ?? 'out'
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { erp_establishment_id: form.erp_establishment_id, cfop: form.cfop, description: form.description, movement: form.movement })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="grid gap-4 sm:grid-cols-2">
      <ErpCatalogReferenceSelect
        v-model="form.erp_establishment_id"
        path="/erp/establishments"
        label="Estabelecimento"
        label-key="name"
        optional
        :disabled="disabled"
      />
      <ErpCatalogField label="CFOP" required :error="fieldErrors?.cfop?.[0]">
        <UInput
          v-model="form.cfop"
          name="cfop"
          required
          maxlength="8"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Descrição" required :error="fieldErrors?.description?.[0]">
        <UInput
          v-model="form.description"
          name="description"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField label="Movimento" required :error="fieldErrors?.movement?.[0]">
        <select
          v-model="form.movement"
          name="movement"
          required
          class="border-default bg-default w-full rounded-md border px-3 py-2 text-sm"
        >
          <option value="in">
            Entrada
          </option>
          <option value="out">
            Saída
          </option>
        </select>
      </ErpCatalogField>
      <p class="text-muted text-xs sm:col-span-2">
        As demais informações fiscais permanecem preservadas ao editar estes campos.
      </p>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar natureza' : 'Salvar natureza'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
