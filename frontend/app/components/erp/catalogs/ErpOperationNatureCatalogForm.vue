<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import type { ErpCatalogEditorMode, ErpOperationNatureRecord } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'
import ErpOperationNatureCfopRules from '~/components/erp/catalogs/ErpOperationNatureCfopRules.vue'

const props = defineProps<{
  formId?: string
  record: ErpOperationNatureRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ 'save': [payload: Record<string, unknown>], 'dirty-change': [dirty: boolean] }>()
const dirty = ref(false)
const form = reactive({ erp_establishment_id: null as number | null, cfop: '', description: '', movement: 'out' as 'in' | 'out', adoptionRevision: null as number | null })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const legacyCfopUnverified = computed(() => {
  const referenceVersion = props.record && 'cfop_reference_version' in props.record
    ? props.record.cfop_reference_version
    : null
  return props.record !== null && referenceVersion == null
})
const { markClean } = useErpCatalogFormDirty(form, () => props.mode !== 'detail', (value) => {
  dirty.value = value
  emit('dirty-change', value)
})

watch(() => [props.record, props.mode] as const, ([record]) => {
  form.erp_establishment_id = record?.erp_establishment_id ?? null
  form.cfop = record?.cfop ?? ''
  form.description = record?.description ?? ''
  form.movement = record?.movement ?? 'out'
  form.adoptionRevision = null
  void nextTick(markClean)
}, { immediate: true })

function stageDefault(value: { code: string, expected_revision: number }) {
  if (disabled.value || dirty.value)
    return
  form.cfop = value.code
  form.adoptionRevision = value.expected_revision
}

function submit() {
  if (disabled.value)
    return
  if (form.adoptionRevision !== null) {
    emit('save', { cfop: form.cfop, expected_revision: form.adoptionRevision })
    return
  }
  emit('save', { erp_establishment_id: form.erp_establishment_id, cfop: form.cfop, description: form.description, movement: form.movement })
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
        Natureza da operação
      </legend>
      <div class="min-w-0">
        <ErpCatalogReferenceSelect
          v-model="form.erp_establishment_id"
          path="/erp/establishments"
          label="Estabelecimento"
          label-key="name"
          name="erp_establishment_id"
          optional
          :disabled="disabled"
          :error="fieldErrors?.erp_establishment_id?.[0]"
        />
      </div>
      <ErpCatalogField
        label="CFOP"
        required
        name="cfop"
        :error="fieldErrors?.cfop?.[0]"
      >
        <UInput
          v-model="form.cfop"
          name="cfop"
          required
          maxlength="8"
          class="w-full"
        />
      </ErpCatalogField>
      <p v-if="legacyCfopUnverified" class="text-warning text-xs sm:col-span-2" role="status">
        Este CFOP histórico ainda não foi verificado na referência oficial.
      </p>
      <ErpCatalogField
        label="Descrição"
        required
        name="description"
        :error="fieldErrors?.description?.[0]"
      >
        <UInput
          v-model="form.description"
          name="description"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
      <ErpCatalogField
        label="Movimento"
        required
        name="movement"
        :error="fieldErrors?.movement?.[0]"
      >
        <USelect
          v-model="form.movement"
          name="movement"
          data-erp-field-name="movement"
          :items="[{ label: 'Entrada', value: 'in' }, { label: 'Saída', value: 'out' }]"
          required
          class="w-full"
        />
      </ErpCatalogField>
      <p class="text-muted text-xs sm:col-span-2">
        As demais informações fiscais permanecem preservadas ao editar estes campos.
      </p>
    </fieldset>
    <UAlert
      v-if="form.adoptionRevision !== null"
      color="warning"
      title="Revise a adoção do CFOP"
      description="Salvar confirmará este CFOP verificado usando a revisão exibida. Outros campos ficam preservados."
    />
    <ErpOperationNatureCfopRules
      v-if="props.record && props.mode !== 'create'"
      :nature-id="props.record.id"
      :movement="form.movement"
      :default-cfop="form.cfop"
      :can-read="true"
      :can-write="props.mode === 'edit' && props.canSave && !props.busy"
      :root-dirty="dirty"
      :default-cfop-unverified="legacyCfopUnverified"
      @stage-default="stageDefault"
    />
  </UForm>
</template>
