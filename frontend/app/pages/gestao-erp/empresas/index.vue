<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { OfficeErpCompany, OfficeErpCompanyListResponse } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { officeErpSortParams } from '~/utils/officeErpListSort'
import { panelBodyClass } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const route = useRoute()
function explicitCriteria() {
  const q = route.query ?? {}
  if (!Object.hasOwn(q, 'q') && !Object.hasOwn(q, 'pagina') && !Object.hasOwn(q, 'porPagina') && !Object.hasOwn(q, 'sort') && !Object.hasOwn(q, 'direction') && !Object.hasOwn(q, 'state')) return null
  const page = typeof q.pagina === 'string' && /^[1-9]\d*$/.test(q.pagina) ? Number(q.pagina) : 1
  const perPage = Number(q.porPagina)
  return {
    q: typeof q.q === 'string' ? q.q : '', page,
    perPage: ([25, 50, 100].includes(perPage) ? perPage : 25) as 25 | 50 | 100,
    state: typeof q.state === 'string' && ['all', 'applied', 'unassigned'].includes(q.state) ? q.state : 'all',
    sort: typeof q.sort === 'string' && ['name', 'registered_at'].includes(q.sort) ? q.sort : '',
    direction: q.direction === 'desc' ? 'desc' : 'asc'
  }
}
const navigation = useOfficeErpListNavigation('companies', { q: '', page: 1, perPage: 25 as 25 | 50 | 100, state: 'all', sort: '', direction: 'asc' }, context, { state: ['all', 'applied', 'unassigned'] }, explicitCriteria)
const query = listNavigationField(navigation, 'q')
const page = listNavigationField(navigation, 'page')
const perPage = listNavigationField(navigation, 'perPage')
const state = listNavigationField(navigation, 'state')
const sort = listNavigationField(navigation, 'sort')
const direction = listNavigationField(navigation, 'direction')
const debouncedQuery = refDebounced(query, 300)
const manualSearch = ref(false)
const searchTerm = computed(() => !manualSearch.value && typeof route.query?.q === 'string' && route.query.q === query.value ? query.value : debouncedQuery.value)
const rows = ref<OfficeErpCompany[]>([])
const total = ref(0)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
const pickerOpen = ref(false)
const inspectionOpen = ref(false)
const inspectionCompanyId = ref<number | null>(null)
const assignmentOpen = ref(false)
const assignmentTarget = ref<OfficeErpCompany | null>(null)
let assignmentIdentity = ''
let requestGeneration = 0
let disposed = false
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const columns: TableColumn<OfficeErpCompany>[] = [
  { accessorKey: 'name', header: 'Empresa', meta: { class: { th: 'w-full min-w-0 md:w-[32%]', td: 'w-full min-w-0 md:w-[32%]' } } },
  { accessorKey: 'cnpj', header: 'CNPJ' },
  { id: 'registration', header: 'Cadastro' },
  { id: 'situation', header: 'Situação' },
  { id: 'actions', header: '', meta: { class: { th: 'w-11 min-w-11 max-w-11 px-0 md:w-[26%] md:min-w-0 md:max-w-none md:px-3', td: 'w-11 min-w-11 max-w-11 px-0 md:w-[26%] md:min-w-0 md:max-w-none md:px-3' } } }
]

function formattedDate(value: string) {
  const date = new Date(value)
  return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(date) : 'Data indisponível'
}
function updateQuery(value: string) {
  manualSearch.value = true
  query.value = value
}
watch(debouncedQuery, () => {
  if (!manualSearch.value) return
  manualSearch.value = false
  page.value = 1
})
function updateState(value: string | number) {
  state.value = String(value) as 'all' | 'applied' | 'unassigned'
  page.value = 1
}
function updatePerPage(value: 25 | 50 | 100) {
  perPage.value = value
  page.value = 1
}
function inspectCompany(id: number) {
  inspectionCompanyId.value = id
  inspectionOpen.value = true
}
function openAssignment(row: OfficeErpCompany) {
  if (!row.capabilities.can_assign || disposed) return
  assignmentIdentity = identityStamp.value
  assignmentTarget.value = row
  assignmentOpen.value = true
}
function companyActionItems(row: OfficeErpCompany) {
  const items = [{ label: 'Ver cadastro', icon: 'i-lucide-eye', onSelect: () => inspectCompany(row.id) }]
  if (row.capabilities.relationship !== 'unassigned')
    items.push({ label: 'Ver assinatura', icon: 'i-lucide-arrow-up-right', onSelect: () => navigateTo(`/gestao-erp/assinaturas/empresas/${row.id}`) })
  if (row.capabilities.can_assign) items.push({ label: 'Adicionar assinatura', icon: 'i-lucide-plus', onSelect: () => openAssignment(row) })
  return [items]
}
async function assignmentConfirmed(result: { company_id: number, contract_id: number }) {
  const targetCompany = assignmentTarget.value
  if (disposed || !targetCompany || assignmentIdentity !== identityStamp.value || result.company_id !== targetCompany.id || !Number.isSafeInteger(result.contract_id)) return
  assignmentOpen.value = false
  assignmentTarget.value = null
  await navigateTo(`/gestao-erp/assinaturas/empresas/${targetCompany.id}?secao=subscription`)
}
function ordered(field: 'name' | 'registered_at') {
  const next = sort.value === field && direction.value === 'asc' ? 'desc' : 'asc'
  navigation.value = { ...navigation.value, sort: field, direction: next, page: 1 }
}
const registered = async (_outcome: { created: Array<{ company_id: number }>, unchanged: Array<{ company_id: number }> }) => {
  await loadCompanies()
}

async function loadCompanies() {
  const read = reads.begin('loadCompanies')
  const request = ++requestGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) {
      rows.value = []
      total.value = 0
      loadError.value = 'A conta selecionada não tem permissão para consultar as empresas do escritório.'
      return
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value, ...officeErpSortParams('companies', sort.value, direction.value) }
    if (searchTerm.value.trim()) params.search = searchTerm.value.trim()
    if (state.value !== 'all') params.state = state.value
    const response = await api<OfficeErpCompanyListResponse>('/erp-commercial/companies', { signal: read.signal, params })
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta da lista de empresas está incompleta.')
    const availablePage = officeErpAvailablePage(response.meta)
    if (page.value !== availablePage) {
      page.value = availablePage
      return
    }
    rows.value = response.data
    total.value = response.meta.total
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar as empresas agora.'
  } finally {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) loading.value = false
  }
}

watch([searchTerm, page, perPage, sort, direction, state, identityStamp], () => {
  void loadCompanies()
}, { immediate: true })
watch(identityStamp, () => {
  requestGeneration++
  rows.value = []
  total.value = 0
  confirmedEmpty.value = false
  loadError.value = ''
  assignmentOpen.value = false
  assignmentTarget.value = null
  assignmentIdentity = ''
}, { flush: 'sync' })
onBeforeUnmount(() => {
  disposed = true
  requestGeneration++
  assignmentOpen.value = false
  assignmentTarget.value = null
  assignmentIdentity = ''
  inspectionOpen.value = false
  inspectionCompanyId.value = null
})
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-col gap-5">
    <OfficeErpPanel title="Empresas" description="Empresas registradas na carteira do escritório, com ou sem assinatura.">
      <template #action>
        <UDropdownMenu
          :items="[[
            { label: 'Selecionar clientes da carteira', icon: 'i-lucide-list-checks', onSelect: () => pickerOpen = true },
            { label: 'Cadastrar novo cliente', icon: 'i-lucide-building-2', onSelect: () => navigateTo('/gestao-erp/empresas/nova?modo=novo') }
          ]]"
        >
          <UButton icon="i-lucide-plus">
            Novo cliente
          </UButton>
        </UDropdownMenu>
      </template>
      <template #toolbar>
        <UFormField label="Buscar empresa" class="w-full sm:max-w-sm">
          <UInput
            :model-value="query"
            icon="i-lucide-search"
            placeholder="Nome ou CNPJ"
            aria-label="Buscar empresa"
            class="w-full"
            @update:model-value="updateQuery"
          />
        </UFormField>
        <UFormField label="Assinatura" class="w-full sm:w-52">
          <USelect
            :model-value="String(state)"
            :items="[{ label: 'Todas as empresas', value: 'all' }, { label: 'Com assinatura', value: 'applied' }, { label: 'Sem assinatura', value: 'unassigned' }]"
            class="w-full"
            @update:model-value="updateState"
          />
        </UFormField>
        <div class="flex gap-2">
          <UButton
            size="sm"
            color="neutral"
            variant="outline"
            :aria-pressed="sort === 'name'"
            @click="ordered('name')"
          >
            Ordenar por nome
          </UButton>
          <UButton
            size="sm"
            color="neutral"
            variant="outline"
            :aria-pressed="sort === 'registered_at'"
            @click="ordered('registered_at')"
          >
            Cadastro
          </UButton>
        </div>
      </template>
      <div :class="panelBodyClass">
        <UAlert
          v-if="loadError"
          color="error"
          title="Não foi possível carregar as empresas"
          :description="loadError"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>{{ loadError }}</span><UButton
                color="neutral"
                variant="outline"
                size="sm"
                @click="loadCompanies"
              >
                Tentar novamente
              </UButton>
            </div>
          </template>
        </UAlert>
        <UAlert
          v-else-if="confirmedEmpty"
          color="neutral"
          title="Nenhuma empresa encontrada"
          description="Cadastre uma nova empresa ou ajuste os filtros da carteira."
        />
        <template v-else>
          <OfficeErpDataTable
            :columns="columns"
            :data="rows"
            :loading="loading"
            :get-row-id="row => String(row.id)"
            :mobile-columns="['name', 'actions']"
            compact
            label="Empresas"
          >
            <template #name-cell="{ row }">
              <div class="min-w-0 space-y-1">
                <NuxtLink class="block min-w-0 break-words font-medium text-highlighted underline-offset-4 hover:underline" :to="`/gestao-erp/assinaturas/empresas/${row.original.id}`">{{ row.original.name }}</NuxtLink>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 md:hidden">
                  <span class="font-mono text-xs text-muted">{{ row.original.cnpj }}</span>
                  <UBadge :color="row.original.capabilities.relationship === 'current' ? 'success' : row.original.capabilities.relationship === 'unassigned' ? 'neutral' : 'warning'" variant="subtle">
                    {{ row.original.capabilities.relationship === 'current' ? 'Com assinatura' : row.original.capabilities.relationship === 'unassigned' ? 'Sem assinatura' : 'Histórico' }}
                  </UBadge>
                </div>
              </div>
            </template>
            <template #cnpj-cell="{ row }">
              <span class="font-mono text-xs">{{ row.original.cnpj }}</span>
            </template>
            <template #registration-cell="{ row }">
              {{ formattedDate(row.original.registered_at) }}
            </template>
            <template #situation-cell="{ row }">
              <UBadge :color="row.original.capabilities.relationship === 'current' ? 'success' : row.original.capabilities.relationship === 'unassigned' ? 'neutral' : 'warning'" variant="subtle">
                {{ row.original.capabilities.relationship === 'current' ? 'Com assinatura' : row.original.capabilities.relationship === 'unassigned' ? 'Sem assinatura' : 'Histórico' }}
              </UBadge>
            </template>
            <template #actions-cell="{ row }">
              <div class="hidden flex-wrap gap-1 md:flex">
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  @click="inspectCompany(row.original.id)"
                >
                  Ver cadastro
                </UButton>
                <UButton
                  v-if="row.original.capabilities.relationship !== 'unassigned'"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  :to="`/gestao-erp/assinaturas/empresas/${row.original.id}`"
                >
                  Ver assinatura
                </UButton>
                <UButton
                  v-if="row.original.capabilities.can_assign"
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-plus"
                  @click="openAssignment(row.original)"
                >
                  Adicionar assinatura
                </UButton>
              </div>
              <div class="md:hidden">
                <UDropdownMenu :items="companyActionItems(row.original)" :content="{ align: 'end' }">
                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-ellipsis"
                    class="size-11 min-w-11 p-0"
                    :aria-label="`Ações de ${row.original.name}`"
                  />
                </UDropdownMenu>
              </div>
            </template>
          </OfficeErpDataTable>
          <OfficeErpPagination
            v-if="total > 25 || perPage !== 25 || page > 1"
            v-model:page="page"
            :per-page="perPage"
            :total="total"
            label="empresas"
            :loading="loading"
            @update:per-page="updatePerPage"
          />
        </template>
      </div>
    </OfficeErpPanel>
    <OfficeErpCompanyPortfolioModal v-model:open="pickerOpen" @registered="registered" />
    <OfficeErpCompanyInspectionModal v-model:open="inspectionOpen" :company-id="inspectionCompanyId" @updated="loadCompanies" />
    <OfficeErpCompanyAssignmentModal
      v-model:open="assignmentOpen"
      :company-id="assignmentTarget?.id ?? null"
      :company-name="assignmentTarget?.name ?? ''"
      :can-assign="assignmentTarget?.capabilities.can_assign ?? false"
      :current-contract-id="assignmentTarget?.current_contract_id ?? null"
      @assigned="assignmentConfirmed"
    />
  </div>
</template>
