<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ErpModulePageContract, ErpModuleTab, ErpPaginatedList, ErpReportPayload } from '~/utils/erpModulesNav'
import { erpCompanyHasPermission, erpListTotal } from '~/utils/erpModulesNav'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import { panelTableUi } from '~/components/data-table/panel'

const props = defineProps<{
  contract: ErpModulePageContract
}>()

const route = useRoute()
const { currentCompany } = useAuth()
const { api } = useAccessApi()

const grants = computed(() => currentCompany.value?.permissions ?? [])
const canRead = computed(() => erpCompanyHasPermission(grants.value, props.contract.readPermission))

const selectedTabId = ref(props.contract.tabs[0]?.id ?? '')

function tabFromHash(): string {
  const hash = route.hash.replace(/^#/, '')
  if (hash && props.contract.tabs.some(tab => tab.id === hash))
    return hash
  return props.contract.tabs[0]?.id ?? ''
}

onMounted(() => {
  selectedTabId.value = tabFromHash()
})

watch(() => route.hash, () => {
  selectedTabId.value = tabFromHash()
})

const activeTab = computed((): ErpModuleTab | undefined =>
  props.contract.tabs.find(tab => tab.id === selectedTabId.value) ?? props.contract.tabs[0]
)

const tabLinks = computed(() => [[
  ...props.contract.tabs.map(tab => ({
    label: tab.label,
    to: `${props.contract.to}#${tab.id}`,
    active: tab.id === selectedTabId.value
  }))
]])

const loadError = ref<unknown>(null)
const loading = ref(false)
const rows = ref<Record<string, unknown>[]>([])
const listTotal = ref<number | null>(null)
const report = ref<ErpReportPayload | null>(null)
const page = ref(1)
const perPage = ref(15)
const dateFrom = ref('')
const dateTo = ref('')
const apiUnavailable = ref(false)
let generation = 0

onScopeDispose(() => {
  generation++
})

const { showError, retry, isLoading } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: `Não foi possível carregar ${props.contract.title.toLowerCase()}`,
  ignoreStatus: 404,
  sticky: false
})

async function load() {
  const version = ++generation
  const tab = activeTab.value
  if (!canRead.value || !tab || tab.mode === 'xml-preview') {
    rows.value = []
    report.value = null
    listTotal.value = null
    apiUnavailable.value = false
    loadError.value = null
    loading.value = false
    return
  }
  loading.value = true
  loadError.value = null
  apiUnavailable.value = false
  rows.value = []
  report.value = null
  listTotal.value = null
  try {
    const params = new URLSearchParams()
    if (tab.mode === 'report') {
      if (dateFrom.value) params.set('date_from', dateFrom.value)
      if (dateTo.value) params.set('date_to', dateTo.value)
    } else {
      params.set('page', String(page.value))
    }
    const path = `${tab.apiPath}${params.size ? `${tab.apiPath.includes('?') ? '&' : '?'}${params}` : ''}`
    if (tab.mode === 'report') {
      const payload = await api<{ data: ErpReportPayload }>(path)
      if (version !== generation) return
      if (!payload.data || (!payload.data.totals && !payload.data.sections))
        throw new Error('Resposta do relatório inválida')
      report.value = payload.data
    } else {
      const payload = await api<ErpPaginatedList>(path)
      if (version !== generation) return
      if (!Array.isArray(payload.data))
        throw new Error('Resposta da listagem inválida')
      rows.value = payload.data
      listTotal.value = erpListTotal(payload)
      perPage.value = payload.meta?.per_page ?? 15
    }
  } catch (error) {
    if (version !== generation) return
    if (apiStatus(error) === 404) {
      apiUnavailable.value = true
      return
    }
    loadError.value = error
  } finally {
    if (version === generation)
      loading.value = false
  }
}

watch(selectedTabId, () => {
  page.value = 1
}, { flush: 'sync' })
watch([selectedTabId, canRead, page, dateFrom, dateTo, () => currentCompany.value?.id], () => {
  void load()
}, { immediate: true, flush: 'sync' })

const previewColumns = computed((): TableColumn<Record<string, unknown>>[] => {
  const sample = rows.value[0]
  if (!sample)
    return []
  return Object.keys(sample).slice(0, 6).map(key => ({
    accessorKey: key,
    header: key
  }))
})
</script>

<template>
  <ErpCatalogPageShell :title="contract.title" :description="contract.description">
    <template #toolbar>
      <UNavigationMenu :items="tabLinks" highlight class="-mx-1 flex-1 overflow-x-auto" />
    </template>
    <template #actions>
      <UBadge
        v-if="canRead"
        color="neutral"
        variant="subtle"
        label="Consulta"
      />
    </template>
    <div class="flex flex-col gap-4">
      <UAlert
        v-if="!canRead"
        color="warning"
        variant="subtle"
        icon="i-lucide-shield-alert"
        title="Sem permissão para este módulo"
        description="Solicite ao responsável da empresa a permissão necessária ou troque de empresa."
      />

      <template v-else>
        <section v-if="activeTab?.mode === 'xml-preview'" class="rounded-lg border border-default p-4">
          <p class="text-sm text-muted">
            A prévia XML de compras e o mapeamento explícito de itens estão disponíveis na área nativa de compras.
          </p>
          <UButton
            class="mt-3"
            color="neutral"
            variant="outline"
            label="Abrir prévia XML de compras"
            to="/erp/compras#xml-preview"
          />
        </section>

        <form v-else-if="activeTab?.mode === 'report'" class="flex flex-wrap items-end gap-3" @submit.prevent="load">
          <UFormField label="De" name="date_from">
            <UInput v-model="dateFrom" type="date" />
          </UFormField>
          <UFormField label="Até" name="date_to">
            <UInput v-model="dateTo" type="date" />
          </UFormField>
          <UButton type="submit" label="Atualizar" :loading="loading" />
        </form>

        <ErrorRetryAlert
          v-if="showError"
          :title="apiErrorMessage(loadError) ?? `Não foi possível carregar ${contract.title.toLowerCase()}`"
          :loading="isLoading"
          @retry="retry"
        />

        <template v-else-if="activeTab?.mode !== 'xml-preview'">
          <UAlert
            v-if="apiUnavailable"
            color="neutral"
            variant="subtle"
            icon="i-lucide-construction"
            title="API ainda não publicada"
            description="A listagem ficará disponível quando o backend deste domínio for liberado. Nenhum total foi inventado."
          />

          <UPageGrid v-else-if="listTotal !== null && !isLoading" class="gap-3 lg:grid-cols-3">
            <MetricCard
              icon="i-lucide-list"
              title="Registros nesta aba"
              :loading="false"
              :value="String(listTotal)"
            />
          </UPageGrid>

          <ErpReportMetrics v-if="report" :report="report" />

          <UEmpty
            v-else-if="!isLoading && !apiUnavailable && rows.length === 0"
            :icon="contract.icon"
            :title="activeTab?.emptyTitle ?? 'Nenhum registro'"
            :description="activeTab?.emptyDescription"
          />

          <UCard v-else-if="!apiUnavailable && rows.length > 0" variant="subtle">
            <UTable
              :data="rows"
              :columns="previewColumns"
              :loading="isLoading"
              :ui="panelTableUi"
            />
          </UCard>
          <div v-if="listTotal !== null && listTotal > perPage" class="flex justify-end">
            <UPagination
              v-model:page="page"
              :total="listTotal"
              :items-per-page="perPage"
              :disabled="loading"
            />
          </div>
        </template>
      </template>
    </div>
  </ErpCatalogPageShell>
</template>
