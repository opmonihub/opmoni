<script setup lang="ts">
import { apiFailure } from '#shared/utils/access'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { useErpCatalogList } from '~/composables/useErpCatalogList'

type ReferenceRecord = { id: number, status?: string } & Record<string, unknown>

const props = defineProps<{
  path: string
  label: string
  labelKey: string
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
const selectedLabel = computed(() => {
  const selected = selectedFromPage.value ?? selectedRecord.value
  return selected ? optionLabel(selected) : model.value ? `Referência atual #${model.value}` : ''
})

function optionLabel(row: ReferenceRecord): string {
  const label = String(row[props.labelKey] ?? `Registro #${row.id}`)
  if (row.status === 'archived')
    return `${label} · arquivado`
  return row.status && row.status !== 'active' ? `${label} · inativo` : label
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
  <div class="space-y-2">
    <span class="text-sm font-medium">{{ label }}</span>
    <UInput
      v-model="list.search.value"
      :aria-label="`${label} · buscar opções`"
      placeholder="Buscar opções"
      :disabled="disabled"
      @keydown.enter.prevent
    />
    <select
      :aria-label="label"
      :value="model ?? ''"
      :disabled="disabled"
      class="border-default bg-default text-highlighted focus-visible:ring-primary-500 w-full rounded-md border px-3 py-2 text-sm focus:outline-none focus-visible:ring-2"
      @change="model = Number(($event.target as HTMLSelectElement).value) || null"
    >
      <option value="">
        {{ optional ? 'Sem vínculo' : 'Selecione uma opção' }}
      </option>
      <option v-if="model && !selectedFromPage" :value="model">
        {{ selectedLabel }}
      </option>
      <option v-for="row in activeRows" :key="row.id" :value="row.id">
        {{ optionLabel(row) }}
      </option>
    </select>
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
    <p v-if="list.error.value" role="status" class="text-warning text-xs">
      {{ list.error.value }}. A seleção atual foi preservada.
    </p>
    <UButton
      v-if="list.authorityBlocked.value"
      label="Revalidar opções"
      color="neutral"
      variant="soft"
      :loading="list.pending.value"
      :disabled="list.pending.value"
      @click="list.retry"
    />
    <p v-if="selectedError && model" role="status" class="text-warning text-xs">
      Não foi possível carregar o rótulo da referência atual. O vínculo {{ model }} foi preservado.
    </p>
  </div>
</template>
