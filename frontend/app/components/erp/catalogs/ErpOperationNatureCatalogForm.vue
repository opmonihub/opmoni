<script setup lang="ts">
import { nextTick } from 'vue'
import { useErpCatalogFormDirty } from '~/composables/useErpCatalogFormDirty'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
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
const { api } = useAccessApi()
const administrationContext = useErpAdministrationContext(() => props.record?.id ?? null)
const dirty = ref(false)
const reviewingAdoption = ref(false)
const adoptionReviewBlocked = ref(false)
const adoptionReviewMessage = ref('')
const reviewedRootSnapshot = ref<{ revision: number, cfop: string, description: string, movement: 'in' | 'out' } | null>(null)
const form = reactive({ erp_establishment_id: null as number | null, cfop: '', description: '', movement: 'out' as 'in' | 'out', adoptionRevision: null as number | null })
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)
const rootFieldsDisabled = computed(() => disabled.value || form.adoptionRevision !== null)
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
  adoptionReviewBlocked.value = false
  adoptionReviewMessage.value = ''
  reviewedRootSnapshot.value = null
  void nextTick(markClean)
}, { immediate: true })

watch(administrationContext.key, () => {
  if (form.adoptionRevision !== null) {
    reviewingAdoption.value = false
    adoptionReviewBlocked.value = true
    adoptionReviewMessage.value = 'O contexto mudou. Revise novamente antes de confirmar o CFOP selecionado.'
    reviewedRootSnapshot.value = null
  }
})

function stageDefault(value: { code: string, expected_revision: number }) {
  if (disabled.value || dirty.value)
    return
  form.cfop = value.code
  form.adoptionRevision = value.expected_revision
  adoptionReviewBlocked.value = false
  adoptionReviewMessage.value = ''
  reviewedRootSnapshot.value = null
}

function submit() {
  if (disabled.value || adoptionReviewBlocked.value)
    return
  if (form.adoptionRevision !== null) {
    emit('save', { cfop: form.cfop, expected_revision: form.adoptionRevision })
    return
  }
  emit('save', { erp_establishment_id: form.erp_establishment_id, cfop: form.cfop, description: form.description, movement: form.movement })
}

async function reviewStagedDefault() {
  if (disabled.value || form.adoptionRevision === null || !props.record || reviewingAdoption.value)
    return
  const recordId = props.record.id
  const token = administrationContext.capture()
  reviewingAdoption.value = true
  adoptionReviewBlocked.value = true
  adoptionReviewMessage.value = ''
  reviewedRootSnapshot.value = null
  try {
    const response = await api<{ data: { configuration_revision: number, cfop: string, description: string, movement: 'in' | 'out' } }>(`/erp/tax-settings/operation-natures/${recordId}`)
    if (!administrationContext.isCurrent(token) || props.record?.id !== recordId)
      return
    const revision = response.data?.configuration_revision
    if (!Number.isSafeInteger(revision) || revision < 1) {
      adoptionReviewBlocked.value = true
      adoptionReviewMessage.value = 'Não foi possível confirmar a revisão atual. O CFOP selecionado permanece em rascunho.'
      return
    }
    form.adoptionRevision = revision
    adoptionReviewBlocked.value = false
    reviewedRootSnapshot.value = {
      revision,
      cfop: response.data.cfop,
      description: response.data.description,
      movement: response.data.movement
    }
    adoptionReviewMessage.value = `Configuração revisada na versão ${revision}. Salve novamente para confirmar o CFOP selecionado.`
  } catch {
    if (administrationContext.isCurrent(token) && props.record?.id === recordId) {
      adoptionReviewBlocked.value = true
      adoptionReviewMessage.value = 'Não foi possível revisar a configuração atual. O CFOP selecionado permanece em rascunho.'
    }
  } finally {
    if (administrationContext.isCurrent(token))
      reviewingAdoption.value = false
  }
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
    <fieldset :disabled="rootFieldsDisabled" class="grid min-w-0 gap-4 sm:grid-cols-2">
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
          :disabled="rootFieldsDisabled"
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
      description="Salvar confirmará o CFOP selecionado usando a revisão exibida. Os demais campos ficam bloqueados e preservados. Se o salvamento indicar que a configuração mudou, revise novamente antes de tentar salvar."
    >
      <template #actions>
        <UButton
          label="Revisar configuração atual"
          size="xs"
          color="neutral"
          variant="outline"
          :disabled="disabled || reviewingAdoption"
          :loading="reviewingAdoption"
          @click="reviewStagedDefault()"
        />
      </template>
    </UAlert>
    <p v-if="adoptionReviewMessage" class="text-xs text-muted" role="status">
      {{ adoptionReviewMessage }}
    </p>
    <p v-if="reviewedRootSnapshot" class="text-xs text-muted" role="status">
      Registro atual na versão {{ reviewedRootSnapshot.revision }}: CFOP {{ reviewedRootSnapshot.cfop }}, {{ reviewedRootSnapshot.description }}, movimento {{ reviewedRootSnapshot.movement === 'in' ? 'entrada' : 'saída' }}.
    </p>
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
