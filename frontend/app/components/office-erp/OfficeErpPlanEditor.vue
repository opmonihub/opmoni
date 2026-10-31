<script setup lang="ts">
import { computed, reactive, ref, toRef, watch } from 'vue'
import { focusFormError } from '~/utils/formPresentation'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import type { OfficeErpOffer, OfficeErpOfferTerms } from '#shared/types/officeErpCommercial'
import { officeErpModuleOptions, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'

const props = withDefaults(defineProps<{
  open: boolean
  offer?: OfficeErpOffer | null
  moduleKeys?: string[]
  canSellNew: boolean
  saving?: boolean
  fieldErrors?: Record<string, string[]>
  failure?: string | null
  recoveryKey?: string | null
}>(), {
  offer: null,
  moduleKeys: () => [],
  saving: false,
  fieldErrors: () => ({}),
  failure: null,
  recoveryKey: null
})

const emit = defineEmits<{
  'update:open': [open: boolean]
  'save': [terms: OfficeErpOfferTerms]
  'recover': [key: string]
}>()

const form = reactive({
  name: '',
  description: '',
  price: '',
  intervalMonths: '1',
  dueInDays: '0',
  moduleKeys: [] as string[],
  published: false
})
const initialDraft = ref('')
const discardOpen = ref(false)
const restoreEditorFocus = useModalFocusReturn(toRef(props, 'open'))
const localErrors = ref<Record<string, string[]>>({})
const moneyError = computed(() => localErrors.value.price?.[0] ?? props.fieldErrors.price?.[0] ?? '')
const visibleModules = computed(() => officeErpModuleOptions.filter(option => props.moduleKeys.includes(option.value)))
const intervalOptions = [
  { label: 'Mensal', value: '1' },
  { label: 'Trimestral', value: '3' },
  { label: 'Semestral', value: '6' },
  { label: 'Anual', value: '12' }
]

watch(() => props.open, (open) => {
  if (!open) {
    discardOpen.value = false
    return
  }
  const revision = props.offer?.current_revision
  form.name = revision?.name ?? props.offer?.name ?? ''
  form.description = revision?.description ?? props.offer?.description ?? ''
  form.price = revision?.price ?? ''
  form.intervalMonths = String(revision?.interval_months ?? 1)
  form.dueInDays = String(revision?.due_in_days ?? 0)
  form.moduleKeys = [...(revision?.module_keys ?? [])]
  form.published = revision?.published ?? false
  localErrors.value = {}
  initialDraft.value = JSON.stringify(form)
  discardOpen.value = false
}, { immediate: true })

function requestClose() {
  if (props.saving || props.recoveryKey) return
  if (JSON.stringify(form) !== initialDraft.value) discardOpen.value = true
  else emit('update:open', false)
}

function focusInvalidField() {
  void focusFormError(undefined, 'office-erp-plan-form')
}

watch(() => props.fieldErrors, (errors) => {
  if (props.open && errors && Object.keys(errors).length) focusInvalidField()
})

function submit() {
  if (!props.canSellNew || props.saving || props.recoveryKey) return
  localErrors.value = {}
  let price: string
  try {
    price = parseOfficeErpMoney(form.price)
  } catch {
    localErrors.value = { price: ['Informe um valor em reais com até duas casas decimais.'] }
    focusInvalidField()
    return
  }

  const name = form.name.trim()
  const interval = Number.parseInt(form.intervalMonths, 10)
  const dueInDays = Number.parseInt(form.dueInDays, 10)
  const errors: Record<string, string[]> = {}
  if (!name) errors.name = ['Informe o nome do plano.']
  if (![1, 3, 6, 12].includes(interval)) errors.interval_months = ['Selecione um ciclo válido.']
  if (!/^\d{1,5}$/.test(form.dueInDays) || dueInDays > 65535) errors.due_in_days = ['Informe de 0 a 65.535 dias.']
  if (form.moduleKeys.some(key => !props.moduleKeys.includes(key))) errors.module_keys = ['Remova módulos que não estão liberados para o escritório.']
  if (Object.keys(errors).length) {
    localErrors.value = errors
    focusInvalidField()
    return
  }

  emit('save', {
    name,
    description: form.description.trim(),
    price,
    currency: 'BRL',
    interval_months: interval as 1 | 3 | 6 | 12,
    due_in_days: dueInDays,
    module_keys: [...form.moduleKeys].sort(),
    published: form.published
  })
}

defineExpose({ form, submit })
</script>

<template>
  <UModal
    :open="open"
    :title="offer ? 'Editar plano' : 'Novo plano'"
    description="Cada alteração cria uma revisão para novas vendas. Contratos existentes preservam os termos aceitos."
    :dismissible="!saving && !recoveryKey"
    :close="!saving && !recoveryKey"
    :ui="{ content: 'max-w-2xl', body: 'min-h-0 overflow-y-auto', footer: 'shrink-0 flex-wrap justify-end' }"
    @update:open="value => { if (!value) requestClose() }"
    @after:leave="restoreEditorFocus"
  >
    <template #body>
      <div class="flex h-full min-h-0 flex-col">
        <UAlert
          v-if="failure"
          color="error"
          title="Não foi possível salvar o plano"
          :description="failure"
          class="mb-4"
        />
        <UAlert
          v-if="recoveryKey"
          color="warning"
          title="O resultado desta operação ainda não foi confirmado"
          class="mb-4"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>Verifique a operação pelo identificador original antes de tentar novamente.</span>
              <UButton
                size="sm"
                color="neutral"
                variant="outline"
                :loading="saving"
                :disabled="saving"
                @click="emit('recover', recoveryKey!)"
              >
                Verificar resultado
              </UButton>
            </div>
          </template>
        </UAlert>

        <UForm
          id="office-erp-plan-form"
          :state="form"
          :disabled="!canSellNew || saving || !!recoveryKey"
          class="flex min-h-0 flex-1 flex-col"
          @submit="submit"
          @error="event => focusFormError(event, 'office-erp-plan-form')"
        >
          <div class="flex-1 space-y-4 overflow-y-auto pe-1">
            <UFormField
              name="name"
              label="Nome do plano"
              required
              :error="localErrors.name?.[0] ?? fieldErrors.name?.[0]"
            >
              <UInput
                v-model="form.name"
                maxlength="255"
                autocomplete="off"
                class="w-full"
              />
            </UFormField>
            <UFormField name="description" label="Descrição" :error="fieldErrors.description?.[0]">
              <UTextarea
                v-model="form.description"
                :rows="3"
                autoresize
                :maxrows="6"
                class="w-full"
              />
            </UFormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <UFormField
                name="price"
                label="Preço (R$)"
                required
                :error="moneyError"
              >
                <UInput
                  v-model="form.price"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  placeholder="0,00"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                name="interval_months"
                label="Ciclo de cobrança"
                required
                :error="localErrors.interval_months?.[0] ?? fieldErrors.interval_months?.[0]"
              >
                <USelect v-model="form.intervalMonths" :items="intervalOptions" class="w-full" />
              </UFormField>
            </div>

            <UFormField
              name="due_in_days"
              label="Prazo para vencimento (dias)"
              required
              :error="localErrors.due_in_days?.[0] ?? fieldErrors.due_in_days?.[0]"
            >
              <UInput
                v-model="form.dueInDays"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                class="w-full"
              />
            </UFormField>

            <UFormField name="module_keys" label="Módulos incluídos" :error="localErrors.module_keys?.[0] ?? fieldErrors.module_keys?.[0]">
              <UCheckboxGroup
                v-model="form.moduleKeys"
                :items="visibleModules"
                :disabled="!canSellNew || saving"
                :ui="{ fieldset: 'grid gap-3 sm:grid-cols-2' }"
              />
              <p v-if="!visibleModules.length" class="mt-2 text-sm text-muted">
                Nenhum módulo está liberado para novos planos.
              </p>
            </UFormField>

            <UFormField name="published" :error="fieldErrors.published?.[0]">
              <UCheckbox v-model="form.published" label="Publicar esta revisão para novas vendas" :disabled="!canSellNew || saving" />
            </UFormField>
            <p class="text-sm text-muted">
              A publicação não altera o valor nem os módulos de contratos já aceitos.
            </p>
          </div>
        </UForm>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full flex-col-reverse justify-end gap-2 sm:flex-row">
        <UButton
          color="neutral"
          variant="outline"
          :disabled="saving"
          @click="requestClose"
        >
          Cancelar
        </UButton>
        <UButton
          form="office-erp-plan-form"
          type="submit"
          :loading="saving"
          :disabled="!canSellNew || saving || !!recoveryKey"
        >
          Salvar plano
        </UButton>
      </div>
    </template>
  </UModal>
  <UModal
    v-model:open="discardOpen"
    title="Descartar alterações?"
    description="As alterações não salvas serão perdidas."
    @after:leave="restoreEditorFocus"
  >
    <template #footer>
      <UButton
        label="Continuar editando"
        color="neutral"
        variant="outline"
        @click="discardOpen = false"
      />
      <UButton label="Descartar alterações" color="error" @click="discardOpen = false; emit('update:open', false)" />
    </template>
  </UModal>
</template>
