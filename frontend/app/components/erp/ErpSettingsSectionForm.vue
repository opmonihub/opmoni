<script setup lang="ts">
import type { ErpCompanySettingsPayload } from '#shared/types/erp-company-settings'

const props = defineProps<{
  title: string
  description?: string
  fields: Array<{
    key: keyof ErpCompanySettingsPayload | string
    label: string
    type?: 'text' | 'number' | 'select' | 'checkbox'
    options?: Array<{ label: string, value: string | number }>
    required?: boolean
  }>
}>()

const { state, meta, pending, refresh, save } = useErpCompanySettings()
const toast = useToast()
const form = reactive<Record<string, unknown>>({})

onMounted(async () => {
  if (!state.value)
    await refresh()
  syncForm()
})

watch(state, syncForm)

function syncForm() {
  if (!state.value)
    return
  for (const field of props.fields) {
    const key = field.key as string
    form[key] = state.value[key] ?? (field.type === 'checkbox' ? 0 : '')
  }
}

async function onSubmit() {
  const patch: Record<string, unknown> = {}
  for (const field of props.fields)
    patch[field.key as string] = form[field.key as string]
  try {
    await save(patch)
    toast.add({ title: 'Configurações salvas', color: 'success', icon: 'i-lucide-check' })
  } catch {
    toast.add({ title: 'Não foi possível salvar', color: 'error', icon: 'i-lucide-x' })
  }
}

function selectModelValue(key: string): string | number | undefined {
  const value = form[key]
  if (typeof value === 'string' || typeof value === 'number')
    return value
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
  <UForm @submit.prevent="onSubmit">
    <UPageCard
      :title="title"
      :description="description"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        type="submit"
        label="Salvar"
        color="primary"
        :loading="pending"
        class="w-fit lg:ms-auto"
      />
    </UPageCard>
    <UPageCard variant="subtle" class="space-y-0">
      <template v-for="(field, index) in fields" :key="field.key">
        <USeparator v-if="index > 0" />
        <UFormField
          :name="String(field.key)"
          :label="field.label"
          :required="field.required"
          class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <UCheckbox
            v-if="field.type === 'checkbox'"
            :model-value="Boolean(Number(form[field.key as string] ?? 0))"
            @update:model-value="form[field.key as string] = $event ? 1 : 0"
          />
          <USelect
            v-else-if="field.type === 'select'"
            :model-value="selectModelValue(field.key as string)"
            :items="selectOptions(field)"
            value-key="value"
            label-key="label"
            class="w-full sm:max-w-xs"
            @update:model-value="form[field.key as string] = $event"
          />
          <UInput
            v-else
            :model-value="String(form[field.key as string] ?? '')"
            :type="field.type === 'number' ? 'number' : 'text'"
            class="w-full sm:max-w-md"
            @update:model-value="form[field.key as string] = field.type === 'number' ? Number($event) : $event"
          />
        </UFormField>
      </template>
    </UPageCard>
  </UForm>
</template>
