<script setup lang="ts">
import type { ErpReportSnapshot } from '#shared/types/erpReports'
import { erpCompanyHasPermission, erpRelatoriosContract } from '~/utils/erpModulesNav'
import { apiErrorMessage } from '~/composables/useApiError'

const route = useRoute()
const { api } = useAccessApi()
const { currentCompany } = useAuth()
const contract = erpRelatoriosContract
const activeTab = computed(() => contract.tabs.find(tab => `#${tab.id}` === route.hash) ?? contract.tabs[0]!)
const category = computed(() => activeTab.value.apiPath.split('/').at(-1)!)
const canRead = computed(() => erpCompanyHasPermission(currentCompany.value?.permissions, contract.readPermission)
  || currentCompany.value?.permissions.some(grant => ['erp.reports.show', 'erp.reports.*'].includes(grant)) === true)
const canExport = computed(() => currentCompany.value?.access_kind === 'direct'
  && currentCompany.value.role !== 'viewer'
  && (currentCompany.value.permissions.some(grant => ['*', 'erp.write', 'erp.reports.exports.store', 'erp.reports.exports.*', 'erp.reports.exports.write'].includes(grant))))
const contextKey = computed(() => `${currentCompany.value?.id}:${currentCompany.value?.access_kind}:${currentCompany.value?.role}:${currentCompany.value?.permissions.join(',')}`)
const filters = reactive({ date_from: '', date_to: '', erp_establishment_id: '', currency: 'BRL', status: '', direction: '' })
function selectFilter(field: 'erp_establishment_id' | 'status' | 'direction') {
  return computed({
    get: () => filters[field] || 'all',
    set: (value: string) => { filters[field] = value === 'all' ? '' : value }
  })
}
const establishmentFilter = selectFilter('erp_establishment_id')
const statusFilter = selectFilter('status')
const directionFilter = selectFilter('direction')
const report = ref<ErpReportSnapshot | null>(null)
const loading = ref(false)
const loadError = ref('')
const establishments = ref<{ id: number, name: string }[]>([])
const establishmentError = ref('')
const normalizedFilters = computed(() => report.value?.filters ?? {})
const hasCurrency = computed(() => !['stock', 'fiscal'].includes(category.value))
const statuses = computed(() => category.value === 'fiscal'
  ? [{ label: 'Autorizado', value: 'authorized' }, { label: 'Rascunho', value: 'draft' }, { label: 'Rejeitado', value: 'rejected' }, { label: 'Indeterminado', value: 'indeterminate' }, { label: 'Contingência local', value: 'contingency_local' }, { label: 'Cancelado', value: 'canceled' }, { label: 'Na fila', value: 'queued' }, { label: 'Em envio', value: 'submitting' }]
  : category.value === 'financial' ? [{ label: 'Em aberto', value: 'open' }, { label: 'Liquidado', value: 'settled' }, { label: 'Estornado', value: 'reversed' }] : [])
const tabs = computed(() => [contract.tabs.map(tab => ({ label: tab.label, to: `${contract.to}#${tab.id}`, active: tab.id === activeTab.value.id }))])
const isEmpty = computed(() => report.value && Object.values(report.value.sections ?? { totals: report.value.totals ?? {} }).every(totals => Object.values(totals).every(value => /^0(?:\.0+)?$/.test(String(value)))))
let generation = 0
let establishmentGeneration = 0

async function loadEstablishments() {
  const version = ++establishmentGeneration
  establishmentError.value = ''
  if (!canRead.value) return
  try {
    const collected: { id: number, name: string }[] = []
    let lastPage = 1
    for (let page = 1; page <= lastPage; page++) {
      const payload = await api<{ data: { id: number, name: string }[], meta: { last_page: number } }>(`/erp/establishments?per_page=100&page=${page}`)
      if (version !== establishmentGeneration) return
      if (!Array.isArray(payload.data) || !Number.isInteger(payload.meta?.last_page)) throw new Error('Resposta de estabelecimentos inválida')
      collected.push(...payload.data)
      lastPage = payload.meta.last_page
    }
    establishments.value = collected
  } catch (error) {
    if (version === establishmentGeneration) establishmentError.value = apiErrorMessage(error) ?? 'Não foi possível carregar os estabelecimentos'
  }
}

async function load() {
  const version = ++generation
  if (!canRead.value) return
  loading.value = true
  loadError.value = ''
  const requestedCategory = category.value
  const params = new URLSearchParams()
  if (filters.date_from) params.set('date_from', filters.date_from)
  if (filters.date_to) params.set('date_to', filters.date_to)
  if (filters.erp_establishment_id) params.set('erp_establishment_id', String(filters.erp_establishment_id))
  if (hasCurrency.value) params.set('currency', filters.currency)
  if (filters.status && statuses.value.length) params.set('status', filters.status)
  if (category.value === 'financial' && filters.direction) params.set('direction', filters.direction)
  try {
    const payload = await api<{ data: ErpReportSnapshot }>(`${activeTab.value.apiPath}${params.size ? `?${params}` : ''}`)
    if (version !== generation) return
    if (payload.data?.category !== requestedCategory || !payload.data.filters || (!payload.data.totals && !payload.data.sections)) throw new Error('Resposta do relatório inválida')
    report.value = payload.data
  } catch (error) {
    if (version === generation) loadError.value = apiErrorMessage(error) ?? 'Não foi possível carregar o relatório'
  } finally {
    if (version === generation) loading.value = false
  }
}

watch([contextKey, category], () => {
  generation++
  report.value = null
  loadError.value = ''
  loading.value = false
  filters.erp_establishment_id = ''
  filters.status = ''
  filters.direction = ''
  void load()
}, { immediate: true, flush: 'sync' })
watch(contextKey, () => {
  establishmentGeneration++
  establishments.value = []
  filters.erp_establishment_id = ''
  void loadEstablishments()
}, { immediate: true, flush: 'sync' })
onScopeDispose(() => {
  generation++
  establishmentGeneration++
})
</script>

<template>
  <ErpCatalogPageShell :title="contract.title" :description="contract.description">
    <template #toolbar>
      <UNavigationMenu :items="tabs" highlight class="-mx-1 flex-1 overflow-x-auto" />
    </template>
    <div class="flex flex-col gap-4">
      <UAlert v-if="!canRead" color="warning" title="Sem permissão para consultar relatórios" />
      <template v-else>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="load">
          <UFormField label="De" name="date_from">
            <UInput v-model="filters.date_from" type="date" />
          </UFormField>
          <UFormField label="Até" name="date_to">
            <UInput v-model="filters.date_to" type="date" />
          </UFormField>
          <UFormField label="Estabelecimento" name="erp_establishment_id">
            <USelect v-model="establishmentFilter" :items="[{ label: 'Todos', value: 'all' }, ...establishments.map(row => ({ label: row.name, value: String(row.id) }))]" class="w-52" />
          </UFormField>
          <UFormField v-if="hasCurrency" label="Moeda" name="currency">
            <UInput
              v-model="filters.currency"
              maxlength="3"
              pattern="[A-Z]{3}"
              class="w-24"
              required
            />
          </UFormField>
          <UFormField v-if="statuses.length" :label="category === 'financial' ? 'Status da obrigação' : 'Status fiscal'" name="status">
            <USelect v-model="statusFilter" :items="[{ label: 'Todos', value: 'all' }, ...statuses]" class="w-44" />
          </UFormField>
          <UFormField v-if="category === 'financial'" label="Direção da obrigação" name="direction">
            <USelect v-model="directionFilter" :items="[{ label: 'Todas', value: 'all' }, { label: 'A receber', value: 'receivable' }, { label: 'A pagar', value: 'payable' }]" class="w-40" />
          </UFormField>
          <UButton type="submit" label="Aplicar filtros" :loading="loading" />
        </form>
        <ErrorRetryAlert v-if="establishmentError" :title="establishmentError" @retry="loadEstablishments" />
        <ErrorRetryAlert
          v-if="loadError"
          :title="loadError"
          :loading="loading"
          @retry="load"
        />
        <p v-if="loadError && report" class="text-muted text-sm">
          Exibindo a última consulta confirmada. Os filtros aplicados aparecem abaixo.
        </p>
        <p v-if="loading" role="status" class="text-muted text-sm">
          Carregando relatório…
        </p>
        <template v-if="report">
          <dl class="text-muted flex flex-wrap gap-x-5 gap-y-2 text-sm">
            <div>
              <dt class="inline font-medium">
                Período:
              </dt><dd class="inline">
                {{ report.filters.date_from }} — {{ report.filters.date_to }}
              </dd>
            </div>
            <div>
              <dt class="inline font-medium">
                Estabelecimento:
              </dt><dd class="inline">
                {{ establishments.find(row => row.id === report?.filters.erp_establishment_id)?.name ?? report.filters.erp_establishment_id ?? 'Todos' }}
              </dd>
            </div>
            <div v-if="report.filters.currency">
              <dt class="inline font-medium">
                Moeda:
              </dt><dd class="inline">
                {{ report.filters.currency }} (vendas, compras e financeiro)
              </dd>
            </div>
            <div v-if="report.filters.status">
              <dt class="inline font-medium">
                Status:
              </dt><dd class="inline">
                {{ report.filters.status }}
              </dd>
            </div>
            <div v-if="report.filters.direction">
              <dt class="inline font-medium">
                Direção:
              </dt><dd class="inline">
                {{ report.filters.direction }}
              </dd>
            </div>
          </dl>
          <p v-if="category === 'financial' && (report.filters.status || report.filters.direction)" class="text-muted text-sm">
            Filtros de status e direção se aplicam às obrigações. O total de pagamentos fica indisponível neste recorte.
          </p>
          <UEmpty
            v-if="isEmpty"
            title="Sem lançamentos neste recorte"
            description="Os totais abaixo foram confirmados pelo servidor."
            icon="i-lucide-chart-column"
          />
          <p v-if="category === 'stock' || category === 'overview'" class="text-muted text-sm">
            Estoque mostra saldos atuais; o período não recompõe o histórico. A avaliação monetária aguarda atribuição de moeda ao estoque.
          </p>
          <ErpReportMetrics :report="report" />
        </template>
        <ErpReportExportPanel
          :category="category"
          :filters="normalizedFilters"
          :context-key="contextKey"
          :can-export="canExport && !!report"
        />
      </template>
    </div>
  </ErpCatalogPageShell>
</template>
