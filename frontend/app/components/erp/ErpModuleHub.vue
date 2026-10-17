<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ErpModulePageContract, ErpModuleTab, ErpPaginatedList } from '~/utils/erpModulesNav'
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
const canWrite = computed(() => erpCompanyHasPermission(grants.value, props.contract.writePermission))

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
const apiUnavailable = ref(false)

const { showError, retry, isLoading } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: `Não foi possível carregar ${props.contract.title.toLowerCase()}`,
  ignoreStatus: 404,
  sticky: true
})

async function load() {
  if (!canRead.value) {
    rows.value = []
    listTotal.value = null
    apiUnavailable.value = false
    return
  }
  const tab = activeTab.value
  if (!tab)
    return
  loading.value = true
  loadError.value = null
  apiUnavailable.value = false
  rows.value = []
  listTotal.value = null
  try {
    const payload = await api<ErpPaginatedList>(tab.apiPath)
    rows.value = Array.isArray(payload.data) ? payload.data : []
    listTotal.value = erpListTotal(payload)
  } catch (error) {
    if (apiStatus(error) === 404) {
      apiUnavailable.value = true
      return
    }
    loadError.value = error
  } finally {
    loading.value = false
  }
}

watch([selectedTabId, canRead], () => {
  void load()
}, { immediate: true })

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
  <UDashboardPanel :id="`erp-${contract.id}`">
    <template #header>
      <UDashboardNavbar :title="contract.title">
        <template #leading>
          <UDashboardSidebarToggle />
        </template>
        <template #trailing>
          <UBadge
            v-if="canWrite"
            color="primary"
            variant="subtle"
            label="Escrita"
          />
          <UBadge
            v-else-if="canRead"
            color="neutral"
            variant="subtle"
            label="Leitura"
          />
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <UNavigationMenu :items="tabLinks" highlight class="-mx-1 flex-1 overflow-x-auto" />
      </UDashboardToolbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 sm:p-6">
        <p class="text-muted text-sm">
          {{ contract.description }}
        </p>
        <p class="text-dimmed text-xs">
          {{ contract.referenceNote }}
        </p>

        <UAlert
          v-if="!canRead"
          color="warning"
          variant="subtle"
          icon="i-lucide-shield-alert"
          title="Sem permissão para este módulo"
          description="Solicite ao responsável da empresa a permissão necessária ou troque de empresa."
        />

        <template v-else>
          <ErrorRetryAlert
            v-if="showError"
            :title="apiErrorMessage(loadError) ?? `Não foi possível carregar ${contract.title.toLowerCase()}`"
            :loading="isLoading"
            @retry="retry"
          />

          <template v-else>
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

            <UEmpty
              v-if="!isLoading && !apiUnavailable && rows.length === 0"
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
          </template>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
