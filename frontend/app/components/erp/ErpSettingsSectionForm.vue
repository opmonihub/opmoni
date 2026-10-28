<script setup lang="ts">
import { apiFailure } from '#shared/utils/access'
import type { ErpSettingsField } from '~/utils/erpCompanySettingsFields'
import { createSettingsFieldDraft } from '~/utils/settingsFieldDraft'
import { formErrorSummaryClass, settingsFieldRowClass } from '~/utils/formPresentation'
import { pageFormMeasureClass, pageHeadingActionsClass, pageHeadingClass } from '~/utils/pageShell'

const props = defineProps<{
  title: string
  description?: string
  fields: ErpSettingsField[]
}>()

const { state, meta, pending, canRead, canWrite, authorityKey, refresh, save } = useErpCompanySettings()
const toast = useToast()
const form = reactive<Record<string, unknown>>({})
const draft = createSettingsFieldDraft(form)
const conflictingFields = ref<string[]>([])
const loadError = ref('')
const saveError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const fieldGroups = computed(() => {
  const groups: Array<{ label?: string, fields: ErpSettingsField[] }> = []
  for (const field of props.fields) {
    let group = groups.find(candidate => candidate.label === field.group)
    if (!group) {
      group = { ...(field.group ? { label: field.group } : {}), fields: [] }
      groups.push(group)
    }
    group.fields.push(field)
  }
  return groups
})

onMounted(async () => {
  if (!state.value && canRead.value) await refreshSettings()
  syncForm()
})

watch(state, syncForm, { flush: 'sync' })
watch([authorityKey, canRead, canWrite], () => {
  loadError.value = ''
  clearSaveFeedback()
  draft.reset()
  conflictingFields.value = []
  syncForm()
}, { flush: 'sync' })

function clearSaveFeedback() {
  saveError.value = ''
  fieldErrors.value = {}
}

function confirmedFields() {
  const values: Record<string, unknown> = {}
  if (!state.value) return values
  for (const field of props.fields) {
    const key = field.key as string
    const value = state.value[key]
    values[key] = ['code', 'decimal'].includes(field.type ?? '') && typeof value === 'number'
      ? String(value)
      : value ?? (field.type === 'checkbox' ? 0 : '')
  }
  return values
}

function syncForm() {
  if (!state.value) {
    draft.reset()
    conflictingFields.value = []
    return
  }
  conflictingFields.value = draft.reconcile(confirmedFields())
}

function discardDraft() {
  draft.reset(confirmedFields())
  conflictingFields.value = []
  clearSaveFeedback()
}

async function refreshSettings() {
  if (!canRead.value || pending.value) return
  const authority = authorityKey.value
  loadError.value = ''
  try {
    await refresh()
  } catch {
    if (canRead.value && authority === authorityKey.value) loadError.value = 'Não foi possível carregar as configurações.'
  }
}

async function onSubmit() {
  if (!canWrite.value || pending.value || !state.value || conflictingFields.value.length) return
  const authority = authorityKey.value
  clearSaveFeedback()
  const patch: Record<string, unknown> = {}
  for (const field of props.fields) {
    const key = field.key as string
    const current = state.value[key]
    if (['code', 'decimal'].includes(field.type ?? '') && typeof current === 'number' && form[key] === String(current)) continue
    patch[key] = form[key]
  }
  try {
    const response = await save(patch)
    if (!response || !canWrite.value || authority !== authorityKey.value) return
    conflictingFields.value = draft.confirm(confirmedFields(), patch)
    toast.add({ title: 'Configurações salvas', color: 'success', icon: 'i-lucide-check' })
  } catch (error) {
    if (!canWrite.value || authority !== authorityKey.value) return
    const failure = apiFailure(error)
    fieldErrors.value = failure.errors ?? {}
    saveError.value = failure.message
  }
}

function selectModelValue(key: string): string | number | undefined {
  const value = form[key]
  if (typeof value === 'string' || typeof value === 'number')
    return value
  return undefined
}

function fieldHint(field: ErpSettingsField): string | undefined {
  if (field.hint) return field.hint
  const value = state.value?.[field.key as string]
  if (field.type === 'code' && typeof value === 'number') {
    return 'Valor legado numérico, pendente de revisão; revise o código antes de usar esta classificação.'
  }
  return undefined
}

function selectOptions(field: (typeof props.fields)[number]) {
  if (field.options)
    return field.options
  const key = field.key as string
  const m = meta.value
  if (!m)
    return []
  if (key === 'date_format')
    return Object.entries(m.date_formats).map(([value, label]) => ({ value, label }))
  if (key === 'accounting_method')
    return Object.entries(m.accounting_methods).map(([value, label]) => ({ value, label }))
  if (key === 'fy_start_month')
    return Object.entries(m.months).map(([value, label]) => ({ value: Number(value), label }))
  if (key === 'time_format')
    return Object.entries(m.time_formats).map(([value, label]) => ({ value: Number(value), label }))
  if (key === 'currency_symbol_placement')
    return Object.entries(m.currency_symbol_placements).map(([value, label]) => ({ value, label }))
  if (key === 'regime')
    return Object.entries(m.regimes).map(([value, label]) => ({ value, label }))
  if (key === 'ambiente')
    return Object.entries(m.ambientes).map(([value, label]) => ({ value: Number(value), label }))
  if (key === 'casas_decimais_valor')
    return Object.entries(m.decimal_places).map(([value, label]) => ({ value: Number(value), label }))
  return []
}
</script>

<template>
  <UForm :state="form" :disabled="!canWrite || pending || !state" @submit="onSubmit">
    <div :class="pageFormMeasureClass">
      <UPageCard
        :title="title"
        :description="description"
        variant="naked"
        orientation="horizontal"
        :class="pageHeadingClass"
      >
        <UButton
          v-if="canWrite"
          type="submit"
          :disabled="pending || !state || conflictingFields.length > 0"
          label="Salvar"
          color="primary"
          :loading="pending"
          :class="pageHeadingActionsClass"
        />
      </UPageCard>
      <p v-if="!canWrite" class="mb-4 text-sm text-muted">
        {{ canRead ? 'Seu acesso permite apenas consultar estas configurações.' : 'Seu acesso não permite consultar ou alterar estas configurações.' }}
      </p>
      <div v-if="!state && canRead" class="mb-4 space-y-2">
        <p class="text-sm text-muted" role="status">
          {{ loadError || (pending ? 'Carregando configurações…' : 'As configurações ainda não foram confirmadas.') }}
        </p>
        <UButton
          v-if="!pending"
          type="button"
          color="neutral"
          variant="outline"
          label="Atualizar configurações"
          @click="refreshSettings"
        />
      </div>
      <p v-if="saveError" role="alert" :class="[formErrorSummaryClass, 'text-sm text-error']">
        {{ saveError }}
      </p>
      <UAlert
        v-if="conflictingFields.length"
        color="warning"
        title="Configurações alteradas em outra atualização"
        description="Seu rascunho foi mantido. Confira os valores atuais antes de salvar."
        class="mb-4"
        :actions="[{ label: 'Descartar rascunho e usar valores atuais', color: 'warning', variant: 'outline', onClick: discardDraft }]"
      />
      <UPageCard
        v-for="group in fieldGroups"
        :key="group.label ?? title"
        :as="group.label ? 'section' : undefined"
        variant="subtle"
        class="space-y-0"
      >
        <template v-if="group.label" #title>
          <h3>{{ group.label }}</h3>
        </template>
        <template v-for="(field, index) in group.fields" :key="field.key">
          <USeparator v-if="index > 0" />
          <UFormField
            :name="String(field.key)"
            :label="field.label"
            :required="field.required"
            :description="field.description"
            :hint="fieldHint(field)"
            :error="fieldErrors[String(field.key)]?.[0]"
            :class="settingsFieldRowClass"
          >
            <UCheckbox
              v-if="field.type === 'checkbox'"
              :disabled="!canWrite || pending || !state"
              :model-value="Boolean(Number(form[field.key as string] ?? 0))"
              @update:model-value="form[field.key as string] = $event ? 1 : 0"
            />
            <USelect
              v-else-if="field.type === 'select'"
              :disabled="!canWrite || pending || !state"
              :model-value="selectModelValue(field.key as string)"
              :items="selectOptions(field)"
              value-key="value"
              label-key="label"
              class="w-full sm:max-w-xs"
              @update:model-value="form[field.key as string] = $event"
            />
            <UInput
              v-else
              :disabled="!canWrite || pending || !state"
              :model-value="String(form[field.key as string] ?? '')"
              :type="field.type === 'number' ? 'number' : 'text'"
              :inputmode="field.type === 'code' ? 'numeric' : undefined"
              class="w-full sm:max-w-md"
              @update:model-value="form[field.key as string] = field.type === 'number' ? Number($event) : $event"
            />
          </UFormField>
        </template>
      </UPageCard>
    </div>
  </UForm>
</template>
