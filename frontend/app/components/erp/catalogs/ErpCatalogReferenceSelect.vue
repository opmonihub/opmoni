<script setup lang="ts">
import type { InputProps } from '@nuxt/ui/components/Input.vue'
import { apiFailure } from '#shared/utils/access'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { useErpCatalogList } from '~/composables/useErpCatalogList'

type ReferenceRecord = { id: number, status?: string, name?: string, sku?: string | null, product?: { name?: string, sku?: string | null, erp_unit_id?: number, [key: string]: unknown } } & Record<string, unknown>
type SelectionRecordUpdate = { id: number | null, record: ReferenceRecord | null, source: 'selection' | 'hydration' }
type ReferenceSearchInputProps = Omit<InputProps, 'modelValue' | 'defaultValue'> & { 'aria-label': string }
const reservedQueryKeys = new Set(['page', 'per_page', 'search', 'company', 'company_id', 'x-company-id', 'account', 'account_id', 'session', 'session_id'])

const props = defineProps<{
  path: string
  label: string
  labelKey: string
  name: string
  query?: Record<string, string>
  labelFormatter?: (record: ReferenceRecord) => string
  error?: string
  disabled?: boolean
  optional?: boolean
}>()
const emit = defineEmits<{
  'selection-validity': [state: { id: number | null, valid: boolean }]
  'selection-record': [state: SelectionRecordUpdate]
}>()
const model = defineModel<number | null>({ default: null })
const normalizedQuery = computed(() => Object.fromEntries(
  Object.entries(props.query ?? {})
    .filter(([key, value]) => !reservedQueryKeys.has(key.toLowerCase()) && typeof value === 'string')
    .sort(([left], [right]) => left.localeCompare(right))
))
const fixedQueryKey = computed(() => JSON.stringify(normalizedQuery.value))
const list = useErpCatalogList<ReferenceRecord>(props.path, () => normalizedQuery.value)
const { api } = useAccessApi()
const authorityContext = useErpAdministrationContext()
const context = useErpAdministrationContext(() => fixedQueryKey.value)
const selectedRecord = ref<ReferenceRecord | null>(null)
const selectedRecordContextKey = ref('')
const selectedError = ref('')
const detailPending = ref(false)
let detailSequence = 0
let detailController: AbortController | null = null
let disposed = false
let lastContextKey = context.key.value
let lastAuthorityKey = authorityContext.key.value
let detailOwnerId: number | null = null
let detailOwnerContextKey = ''
let blockedSelectionId: number | null = null

const currentPage = computed(() => list.state.value?.meta.current_page ?? list.page.value)
const lastPage = computed(() => list.state.value?.meta.last_page ?? 1)
const activeRows = computed(() => (list.state.value?.data ?? []).filter(row => row.status === undefined || row.status === 'active'))
const selectedFromPage = computed(() => activeRows.value.find(row => row.id === model.value) ?? null)
const displayedRecord = computed(() => selectedFromPage.value ?? (selectedRecordContextKey.value === context.key.value ? selectedRecord.value : null))
const selectedLabel = computed(() => {
  return displayedRecord.value ? optionLabel(displayedRecord.value) : model.value ? `Referência atual #${model.value}` : ''
})
const options = computed(() => {
  const rows: Array<ReferenceRecord & { label: string, disabled?: boolean }> = activeRows.value.map(row => ({ ...row, label: optionLabel(row) }))
  const selected = displayedRecord.value
  if (selected && !rows.some(row => row.id === selected.id))
    rows.unshift({ ...selected, label: optionLabel(selected), disabled: true })
  return rows
})
const selectionValid = computed(() => model.value !== null
  && displayedRecord.value?.id === model.value
  && displayedRecord.value.status === 'active'
  && list.canRead.value && !list.authorityBlocked.value
  && !list.pending.value && !list.error.value && !selectedError.value && !detailPending.value)
watch([model, selectionValid], () => {
  emit('selection-validity', { id: model.value, valid: selectionValid.value })
}, { immediate: true, flush: 'sync' })
watch([model, displayedRecord, context.key, list.pending, list.error, list.authorityBlocked, list.canRead], () => {
  const record = displayedRecord.value?.id === model.value ? displayedRecord.value : null
  if (model.value !== null && record && list.canRead.value && !list.authorityBlocked.value && !list.pending.value && !list.error.value)
    emit('selection-record', { id: model.value, record, source: 'hydration' })
}, { immediate: true, flush: 'sync' })
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
  const label = props.labelFormatter?.(row) ?? String(row[props.labelKey] ?? `Registro #${row.id}`)
  if (row.status === 'archived')
    return `${label} · Arquivado`
  return row.status && row.status !== 'active' ? `${label} · Inativo` : label
}

function updateModel(value: unknown) {
  const id = value === null || value === undefined || value === '' ? null : typeof value === 'number' ? value : Number(value)
  const validId = id !== null && Number.isSafeInteger(id) && id > 0 ? id : null
  const record = validId === null ? null : activeRows.value.find(row => row.id === validId) ?? null
  if (validId !== null && (!record || !list.canRead.value || list.authorityBlocked.value || list.pending.value || list.error.value))
    return
  emit('selection-record', { id: validId, record, source: 'selection' })
  model.value = validId
}

function abortDetail() {
  detailSequence++
  detailController?.abort()
  detailController = null
  detailOwnerId = null
  detailOwnerContextKey = ''
  detailPending.value = false
}

async function hydrateSelected(id: number) {
  const requestId = ++detailSequence
  const token = context.capture()
  const controller = new AbortController()
  detailController = controller
  detailOwnerId = id
  detailOwnerContextKey = token.key
  detailPending.value = true
  try {
    const response = await api<{ data: ReferenceRecord }>(`${props.path}/${id}`, { signal: controller.signal })
    if (!disposed && detailSequence === requestId && context.isCurrent(token) && model.value === id && !list.authorityBlocked.value && list.canRead.value) {
      selectedRecord.value = response.data
      selectedRecordContextKey.value = token.key
    }
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
    if (detailSequence === requestId && detailController === controller) {
      detailController = null
      detailOwnerId = null
      detailOwnerContextKey = ''
      detailPending.value = false
    }
  }
}

watch([model, context.key, () => list.state.value, list.authorityBlocked, list.canRead], () => {
  const nextContextKey = context.key.value
  if (nextContextKey !== lastContextKey) {
    const authorityChanged = authorityContext.key.value !== lastAuthorityKey
    lastContextKey = nextContextKey
    lastAuthorityKey = authorityContext.key.value
    if (authorityChanged)
      blockedSelectionId = model.value
    abortDetail()
    selectedError.value = ''
    selectedRecord.value = null
    selectedRecordContextKey.value = ''
    const id = model.value
    if (!authorityChanged && id && id !== blockedSelectionId && !list.authorityBlocked.value && list.canRead.value && !selectedFromPage.value)
      void hydrateSelected(id)
    return
  }

  const id = model.value
  if (id !== blockedSelectionId)
    blockedSelectionId = null
  if (id && detailOwnerId === id && detailOwnerContextKey === context.key.value && detailPending.value)
    return
  abortDetail()
  selectedError.value = ''
  selectedRecord.value = null
  selectedRecordContextKey.value = ''
  if (!id || selectedFromPage.value || id === blockedSelectionId || list.authorityBlocked.value || !list.canRead.value)
    return
  void hydrateSelected(id)
}, { immediate: true, flush: 'sync' })

onScopeDispose(() => {
  disposed = true
  abortDetail()
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
      :data-erp-field-name="name"
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
