<script setup lang="ts">
import type { InputProps } from '@nuxt/ui/components/Input.vue'
import { apiFailure } from '#shared/utils/access'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { useErpCatalogList } from '~/composables/useErpCatalogList'

type ReferenceRecord = { id: number, status?: string } & Record<string, unknown>
type ReferenceSearchInputProps = Omit<InputProps, 'modelValue' | 'defaultValue'> & { 'aria-label': string }

const props = defineProps<{
  path: string
  label: string
  labelKey: string
  name: string
  error?: string
  disabled?: boolean
  optional?: boolean
}>()
const model = defineModel<number | null>({ default: null })
const list = useErpCatalogList<ReferenceRecord>(props.path)
const { api } = useAccessApi()
const context = useErpAdministrationContext()
const selectedRecord = ref<ReferenceRecord | null>(null)
const selectedError = ref('')
let detailSequence = 0
let detailController: AbortController | null = null
let disposed = false
let lastContextKey = context.key.value
let blockedSelectionId: number | null = null

const currentPage = computed(() => list.state.value?.meta.current_page ?? list.page.value)
const lastPage = computed(() => list.state.value?.meta.last_page ?? 1)
const activeRows = computed(() => (list.state.value?.data ?? []).filter(row => row.status === undefined || row.status === 'active'))
const selectedFromPage = computed(() => activeRows.value.find(row => row.id === model.value) ?? null)
const displayedRecord = computed(() => selectedFromPage.value ?? selectedRecord.value)
const selectedLabel = computed(() => {
  return displayedRecord.value ? optionLabel(displayedRecord.value) : model.value ? `Referência atual #${model.value}` : ''
})
const options = computed(() => {
  const rows: Array<ReferenceRecord & { label: string, disabled?: boolean }> = activeRows.value.map(row => ({ ...row, label: String(row[props.labelKey] ?? `Registro #${row.id}`) }))
  const selected = displayedRecord.value
  if (selected && !rows.some(row => row.id === selected.id))
    rows.unshift({ ...selected, label: String(selected[props.labelKey] ?? `Registro #${selected.id}`), disabled: true })
  return rows
})
const selectedStatusLabel = computed(() => displayedRecord.value?.status === 'archived'
  ? 'Arquivado'
  : displayedRecord.value?.status && displayedRecord.value.status !== 'active'
    ? 'Inativo'
    : '')
const searchInput = computed<ReferenceSearchInputProps>(() => ({
  'placeholder': `Buscar ${props.label.toLocaleLowerCase()}`,
  'icon': 'i-lucide-search',
  'aria-label': `${props.label} · buscar opções`
}))

function optionLabel(row: ReferenceRecord): string {
  const label = String(row[props.labelKey] ?? `Registro #${row.id}`)
  if (row.status === 'archived')
    return `${label} · Arquivado`
  return row.status && row.status !== 'active' ? `${label} · Inativo` : label
}

function updateModel(value: unknown) {
  if (value === null || value === undefined || value === '') {
    model.value = null
    return
  }
  const id = typeof value === 'number' ? value : Number(value)
  model.value = Number.isSafeInteger(id) && id > 0 ? id : null
}

watch([model, context.key, () => list.state.value, list.authorityBlocked, list.canRead], async () => {
  const nextContextKey = context.key.value
  if (nextContextKey !== lastContextKey) {
    lastContextKey = nextContextKey
    blockedSelectionId = model.value
    detailSequence++
    detailController?.abort()
    detailController = null
    selectedError.value = ''
    selectedRecord.value = null
    return
  }

  const id = model.value
  if (id !== blockedSelectionId)
    blockedSelectionId = null
  detailSequence++
  detailController?.abort()
  detailController = null
  selectedError.value = ''
  selectedRecord.value = null
  if (!id || selectedFromPage.value || id === blockedSelectionId || list.authorityBlocked.value || !list.canRead.value)
    return

  const requestId = detailSequence
  const token = context.capture()
  const controller = new AbortController()
  detailController = controller
  try {
    const response = await api<{ data: ReferenceRecord }>(`${props.path}/${id}`, { signal: controller.signal })
    if (!disposed && detailSequence === requestId && context.isCurrent(token) && model.value === id && !list.authorityBlocked.value && list.canRead.value)
      selectedRecord.value = response.data
  } catch (failure) {
    if (!disposed && detailSequence === requestId && context.isCurrent(token) && model.value === id) {
      const details = apiFailure(failure)
      if (details.status === 401 || details.status === 403 || (details.status === 409 && details.code === 'company_context_changed')) {
        blockedSelectionId = id
        selectedRecord.value = null
      }
      selectedError.value = details.message
    }
  } finally {
    if (detailSequence === requestId && detailController === controller)
      detailController = null
  }
}, { immediate: true, flush: 'sync' })

onScopeDispose(() => {
  disposed = true
  detailSequence++
  detailController?.abort()
  detailController = null
})
</script>

<template>
  <UFormField
    :name="name"
    :label="label"
    :error="error"
    :required="!optional"
    :description="selectedStatusLabel ? `O vínculo atual está ${selectedStatusLabel.toLocaleLowerCase()}.` : undefined"
    class="block"
  >
    <USelectMenu
      v-model:search-term="list.search.value"
      :model-value="model"
      :items="options"
      value-key="id"
      label-key="label"
      :search-input="searchInput"
      :loading="list.pending.value"
      :disabled="disabled || list.authorityBlocked.value"
      :ignore-filter="true"
      :clear="optional"
      :required="!optional"
      :name="name"
      :placeholder="optional ? 'Sem vínculo' : 'Selecione uma opção'"
      :aria-label="label"
      class="w-full"
      @update:model-value="updateModel"
    >
      <template #default>
        <span class="truncate">{{ selectedLabel || (optional ? 'Sem vínculo' : 'Selecione uma opção') }}</span>
      </template>
      <template #empty>
        <span class="text-muted px-2 py-1.5 text-sm">
          {{ list.pending.value ? 'Carregando opções…' : 'Nenhuma opção ativa encontrada.' }}
        </span>
      </template>
      <template #item-trailing="{ item }">
        <UBadge
          v-if="item.status === 'archived'"
          label="Arquivado"
          color="neutral"
          variant="subtle"
        />
        <UBadge
          v-else-if="item.status && item.status !== 'active'"
          label="Inativo"
          color="warning"
          variant="subtle"
        />
      </template>
      <template #content-bottom>
        <div class="border-default space-y-2 border-t p-2">
          <p v-if="list.pending.value" role="status" class="text-muted text-xs">
            Carregando opções…
          </p>
          <div v-if="lastPage > 1" class="flex items-center justify-between text-xs text-muted">
            <UButton
              label="Anterior"
              color="neutral"
              variant="ghost"
              :disabled="list.pending.value || currentPage <= 1"
              @click="list.page.value--"
            />
            <span>Página {{ currentPage }} de {{ lastPage }}</span>
            <UButton
              label="Próxima"
              color="neutral"
              variant="ghost"
              :disabled="list.pending.value || currentPage >= lastPage"
              @click="list.page.value++"
            />
          </div>
        </div>
      </template>
    </USelectMenu>
    <UAlert
      v-if="list.error.value"
      color="error"
      :title="list.error.value"
      class="mt-2"
    >
      <template #description>
        A seleção atual foi preservada.
      </template>
      <template #actions>
        <UButton
          :label="list.authorityBlocked.value ? 'Revalidar opções' : 'Tentar novamente'"
          color="neutral"
          variant="soft"
          :loading="list.pending.value"
          :disabled="list.pending.value"
          @click="list.retry"
        />
      </template>
    </UAlert>
    <p v-if="selectedError && model" role="status" class="text-warning mt-2 text-xs">
      Não foi possível carregar o rótulo da referência atual. O vínculo {{ model }} foi preservado.
    </p>
  </UFormField>
</template>
