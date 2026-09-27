<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/Filter.vue'
import { sheetBodyClass, sheetTableUi, sheetToolbarUi } from '~/components/data-table/sheet'
import { apiStatus } from '~/composables/useApiError'
import type { ObligationListParams } from '~/composables/useSerpro'
import type { MonitoringClient, MonitoringObligationSummary, MonitoringSituacao } from '~/types/serpro'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringDueOn,
  monitoringAttentionReasonPresentation,
  monitoringCategoryPresentation,
  monitoringSituacaoPresentation,
  monitoringStalePresentation
} from '~/utils/monitoringPresentation'
import AssociateClientsModal from '~/components/monitoring/AssociateClientsModal.vue'
import ObligationCounters from '~/components/monitoring/ObligationCounters.vue'

const props = defineProps<{
  obligation: MonitoringObligation
  situacao: MonitoringSituacao | null
}>()

const emit = defineEmits<{ refreshed: [] }>()

const toast = useToast()
const { canManageClients } = useAuth()
const { listObligation } = useSerpro()
const { listTags } = useClients()

/** A 404 must never reach the error alert: it is the inert state, not a failure. */
const failed = ref(false)
const associateOpen = ref(false)

const search = ref('')
/** The house debounce is 350 ms — never a request per keystroke. */
const debouncedSearch = refDebounced(search, 350)
const tagFilter = ref<number[]>([])
const page = ref(1)

const UNSERVED_CATEGORIES = ['unavailable', 'extinct'] as const

function emptySummary(obligation: MonitoringObligation): MonitoringObligationSummary {
  return {
    obligation: obligation.slug,
    category: obligation.category,
    total: 0,
    em_dia: 0,
    processando: 0,
    pendencias: 0,
    atencao: 0,
    encerrado: 0,
    current_page: 1,
    attention_reasons: []
  }
}

/** Typed at the boundary so `situacao` keeps the backend's own vocabulary. */
const params = computed<ObligationListParams>(() => ({
  situacao: props.situacao ?? '',
  q: debouncedSearch.value.trim(),
  tag_id: tagFilter.value.length ? tagFilter.value : undefined,
  page: page.value
}))

const listKey = computed(() => `serpro-monitoring-${props.obligation.slug}-${props.situacao ?? 'todas'}`)

const { data, status, error, refresh } = await useAsyncData(listKey, async () => {
  if (UNSERVED_CATEGORIES.includes(props.obligation.category as typeof UNSERVED_CATEGORIES[number])) {
    return { data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }
  }
  try {
    return await listObligation(props.obligation.slug, params.value)
  } catch (error) {
    // The obligation slug comes from the registry and is never typed, so a 404
    // means "no data", never "wrong URL". Answering with an empty envelope is
    // honest in both worlds — the endpoint not existing yet, or nothing to show.
    if (apiStatus(error) === 404) return { data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }
    failed.value = true
    throw error
  }
}, { watch: [params], default: () => ({ data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }) })

const summary = computed(() => data.value?.data ?? emptySummary(props.obligation))
const isLoading = computed(() => status.value === 'pending')
const isUnserved = computed(() => UNSERVED_CATEGORIES.includes(props.obligation.category as typeof UNSERVED_CATEGORIES[number]))
const category = computed(() => monitoringCategoryPresentation[props.obligation.category])

const rows = ref<MonitoringClient[]>([])
const total = ref(0)
const loadingMore = ref(false)
let generation = 0

watch(data, (value) => {
  generation += 1
  rows.value = value?.data_rows ?? []
  total.value = value?.data.total ?? 0
  page.value = value?.data.current_page ?? 1
}, { immediate: true })

async function loadMore() {
  if (loadingMore.value || status.value === 'pending') return
  if (rows.value.length >= total.value) return

  const seen = generation
  const nextPage = page.value + 1
  loadingMore.value = true
  try {
    const response = await listObligation(props.obligation.slug, { ...params.value, page: nextPage })
    // Two fast filter changes must not interleave and append a stale page.
    if (seen !== generation) return
    const known = new Set(rows.value.map(row => row.client_id))
    const fresh = (response.data_rows ?? []).filter(row => !known.has(row.client_id))
    if (!fresh.length) {
      total.value = rows.value.length
      return
    }
    rows.value = [...rows.value, ...fresh]
    page.value = nextPage
  } catch {
    toast.add({ title: 'Não foi possível carregar mais clientes', color: 'error' })
  } finally {
    loadingMore.value = false
  }
}

const canLoadMore = computed(() => rows.value.length > 0 && rows.value.length < total.value)

const { data: tagCatalog } = await useAsyncData('serpro-monitoring-tags', () => listTags())

const filterColumns = computed<DataTableFilterColumn[]>(() => [
  { id: 'q', label: 'Busca', icon: 'i-lucide-search', type: 'text' },
  {
    id: 'tag_id',
    label: 'Tags',
    icon: 'i-lucide-tags',
    type: 'multiOption',
    options: (tagCatalog.value?.data ?? []).map(tag => ({ label: tag.name, value: String(tag.id) }))
  }
])

const filterModels = computed<DataTableFilterModel[]>(() => {
  const models: DataTableFilterModel[] = []
  if (debouncedSearch.value.trim()) models.push({ columnId: 'q', type: 'text', operator: 'contains', values: [debouncedSearch.value.trim()] })
  if (tagFilter.value.length) models.push({ columnId: 'tag_id', type: 'multiOption', operator: 'include', values: tagFilter.value.map(String) })
  return models
})

function onFilters(models: DataTableFilterModel[]) {
  search.value = String(models.find(model => model.columnId === 'q')?.values[0] ?? '')
  tagFilter.value = (models.find(model => model.columnId === 'tag_id')?.values ?? []).map(Number).filter(Number.isFinite)
}

const hasActiveFilters = computed(() => !!debouncedSearch.value.trim() || tagFilter.value.length > 0 || !!props.situacao)

function clearFilters() {
  search.value = ''
  tagFilter.value = []
}

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar a lista', color: 'error' })
  }
}

watch(error, (value) => {
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar a lista', color: 'error' })
  }
})

const columns = computed<TableColumn<MonitoringClient>[]>(() =>
  props.obligation.columns.map(column => ({
    accessorKey: column.id,
    header: column.header,
    meta: { class: column.numeric ? { th: 'text-right', td: 'text-right tabular-nums' } : undefined }
  }))
)

const detailFields = computed(() => props.obligation.columns.filter(column => column.id !== 'name' && column.id !== 'situacao'))

function fieldValue(row: MonitoringClient, id: string) {
  if (id === 'name') return row.name
  if (id === 'situacao') return monitoringSituacaoPresentation[row.situacao].label
  if (id === 'due_on') return formatMonitoringDueOn(row.due_on)
  const value = row.fields[id]
  return value == null || value === '' ? '—' : String(value)
}

/** The row's situation, refined by its named cause when it is `atencao`. */
function situacaoLabel(row: MonitoringClient) {
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao].label
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label).label
}

function situacaoIcon(row: MonitoringClient) {
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao].icon
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label).icon
}

async function afterAssociate() {
  associateOpen.value = false
  await onRefresh()
  emit('refreshed')
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <UDashboardToolbar class="hidden min-w-0 md:flex" :ui="sheetToolbarUi">
      <template #left>
        <div class="min-w-0 flex-1">
          <ObligationCounters
            v-if="!isUnserved"
            :obligation="obligation"
            :summary="summary"
            :situacao="situacao"
            @associate="associateOpen = true"
          />
        </div>
      </template>
    </UDashboardToolbar>

    <div :class="sheetBodyClass">
      <template v-if="isUnserved">
        <UAlert
          :color="category.color"
          variant="subtle"
          :icon="category.icon"
          :title="category.label"
          :description="category.description"
        />
      </template>

      <template v-else>
        <div class="md:hidden">
          <ObligationCounters
            :obligation="obligation"
            :summary="summary"
            :situacao="situacao"
            @associate="associateOpen = true"
          />
        </div>

        <UAlert
          v-if="error && failed"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Não foi possível carregar esta obrigação"
          description="Verifique sua conexão e tente novamente."
          :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
        />

        <USkeleton v-else-if="isLoading && rows.length === 0" class="h-64 w-full" />

        <template v-else>
          <DataTableFilter
            :columns="filterColumns"
            :model-value="filterModels"
            :disabled="isLoading"
            class="min-w-0"
            @update:model-value="onFilters"
          >
            <UInput
              v-model="search"
              icon="i-lucide-search"
              placeholder="Buscar por nome ou CNPJ"
              class="min-w-0 flex-1"
              :disabled="isLoading"
            />
          </DataTableFilter>

          <UEmpty
            v-if="rows.length === 0 && !hasActiveFilters"
            icon="i-lucide-inbox"
            title="Nenhum cliente nesta obrigação"
            description="Nenhum cliente da carteira tem registro sincronizado para esta obrigação."
            variant="naked"
            :actions="canManageClients
              ? [{ label: 'Adicionar clientes', icon: 'i-lucide-user-plus', onClick: () => { associateOpen = true } }]
              : []"
          />

          <UEmpty
            v-else-if="rows.length === 0"
            icon="i-lucide-search-x"
            title="Nenhum resultado com estes filtros"
            description="Ajuste a busca ou limpe os filtros aplicados."
            variant="naked"
            :actions="[{ label: 'Limpar filtros', color: 'neutral', variant: 'outline', onClick: clearFilters }]"
          />

          <template v-else>
            <div class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto md:hidden">
              <UCard v-for="row in rows" :key="row.client_id" :ui="{ body: 'p-3 sm:p-4' }">
                <div class="flex items-start justify-between gap-3">
                  <DataTableIdentity :title="row.name" :meta="row.tax_id ?? ''" />
                  <div class="flex shrink-0 flex-col items-end gap-1">
                    <UBadge
                      :color="monitoringSituacaoPresentation[row.situacao].color"
                      :icon="situacaoIcon(row)"
                      variant="subtle"
                      :label="situacaoLabel(row)"
                    />
                    <UBadge
                      v-if="row.stale"
                      size="sm"
                      variant="subtle"
                      :color="monitoringStalePresentation.color"
                      :icon="monitoringStalePresentation.icon"
                      :label="monitoringStalePresentation.label"
                    />
                  </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
                  <div v-for="field in detailFields" :key="field.id" class="min-w-0">
                    <dt class="text-xs text-muted">
                      {{ field.header }}
                    </dt>
                    <dd class="truncate text-sm text-default tabular-nums">
                      {{ fieldValue(row, field.id) }}
                    </dd>
                  </div>
                </dl>
              </UCard>
            </div>

            <div class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex">
              <UTable
                sticky
                :data="rows"
                :columns="columns"
                class="h-full min-h-0 w-full flex-1"
                :ui="sheetTableUi"
              >
                <template #name-cell="{ row }">
                  <DataTableIdentity :title="row.original.name" :meta="row.original.tax_id ?? ''" />
                </template>

                <template #situacao-cell="{ row }">
                  <div class="flex flex-wrap items-center gap-1.5">
                    <UBadge
                      class="max-w-full"
                      :color="monitoringSituacaoPresentation[row.original.situacao].color"
                      :icon="situacaoIcon(row.original)"
                      variant="subtle"
                      :label="situacaoLabel(row.original)"
                      :ui="{ base: 'max-w-full', label: 'truncate' }"
                    />
                    <UBadge
                      v-if="row.original.stale"
                      size="sm"
                      variant="subtle"
                      :color="monitoringStalePresentation.color"
                      :icon="monitoringStalePresentation.icon"
                      :label="monitoringStalePresentation.label"
                    />
                  </div>
                </template>
              </UTable>
            </div>

            <div v-if="canLoadMore" class="flex justify-center">
              <UButton
                label="Carregar mais"
                color="neutral"
                variant="outline"
                icon="i-lucide-chevrons-down"
                :loading="loadingMore"
                @click="loadMore"
              />
            </div>
          </template>
        </template>
      </template>
    </div>

    <AssociateClientsModal
      v-if="canManageClients"
      v-model:open="associateOpen"
      :obligation="obligation"
      :associated-ids="rows.map(row => row.client_id)"
      @associated="afterAssociate"
    />
  </div>
</template>
