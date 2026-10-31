<script setup lang="ts">
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import type { OfficeErpCompany, OfficeErpCompanyListResponse } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { officeErpSortParams } from '~/utils/officeErpListSort'
import { companyListBodyClass, companyListFooterClass } from '~/components/office-erp/companyList'
import { formatTaxId } from '~/utils/taxId'
import type { FilterPanelColumn } from '~/utils/filterPanel'
import type { DataTableFilterModel } from '~/components/data-table/filter-model'

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
const companyFilterColumns: FilterPanelColumn[] = [{ id: 'state', label: 'Assinatura', icon: 'i-lucide-file-key', control: 'select', options: [{ label: 'Com assinatura', value: 'applied' }, { label: 'Sem assinatura', value: 'unassigned' }] }]
const companyFilters = computed<DataTableFilterModel[]>({
  get: (): DataTableFilterModel[] => state.value === 'all' ? [] : [{ columnId: 'state', type: 'option', operator: 'is', values: [state.value] }],
  set: filters => updateState(String(filters.find(filter => filter.columnId === 'state')?.values[0] ?? 'all'))
})
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
  { accessorKey: 'name', header: 'Empresa', enableSorting: true, meta: { class: { th: 'min-w-0', td: 'min-w-0' } } },
  { accessorKey: 'registered_at', header: 'Cadastrada em', enableSorting: true, meta: { class: { th: 'w-40', td: 'tabular-nums' } } },
  { id: 'actions', header: '', meta: { class: { th: 'w-44', td: 'text-right' } } }
]
const tableSorting = computed(() => sort.value ? [{ id: sort.value, desc: direction.value === 'desc' }] : [])

function formattedDate(value: string) {
  const date = new Date(value)
  return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(date) : 'Data indisponível'
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
function companyActions(row: OfficeErpCompany): DropdownMenuItem[] {
  const base = `/gestao-erp/assinaturas/empresas/${row.id}`
  const items: DropdownMenuItem[] = [
    { label: 'Gerenciar empresa', icon: 'i-lucide-building-2', to: `${base}?secao=company` },
    { label: row.capabilities.can_edit_profile ? 'Editar cadastro' : 'Consultar cadastro', icon: row.capabilities.can_edit_profile ? 'i-lucide-pencil' : 'i-lucide-file-search', onSelect: () => inspectCompany(row.id) }
  ]
  if (row.capabilities.relationship !== 'unassigned') {
    items.push({ label: 'Ver assinatura', icon: 'i-lucide-file-key', to: base })
    if (row.capabilities.relationship === 'current') {
      items.push({ label: 'Gerenciar assinatura', icon: 'i-lucide-settings-2', to: `${base}?secao=subscription` })
    }
    items.push({ label: 'Faturas da empresa', icon: 'i-lucide-receipt', to: `${base}?secao=invoices` })
    items.push({ label: 'Histórico', icon: 'i-lucide-history', to: `${base}?secao=history` })
  }
  if (row.capabilities.can_manage_members) items.push({ label: 'Usuários e acessos', icon: 'i-lucide-users-round', to: `${base}?secao=members` })
  if (row.capabilities.can_assign) items.push({ label: 'Adicionar assinatura', icon: 'i-lucide-plus', onSelect: () => openAssignment(row) })
  return items
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
    <OfficeErpPanel
      title="Empresas"
      description="Clientes cadastrados no ERP do escritório."
      company-list
    >
      <template #title>
        <span class="flex items-center gap-1.5">
          Empresas
          <UPopover :content="{ align: 'start' }">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-circle-help"
              aria-label="Sobre empresas e assinaturas"
              class="min-h-9 min-w-9 justify-center"
            />
            <template #content>
              <p class="max-w-64 p-3 text-sm text-default">
                Adicione uma assinatura pela linha da empresa. Ativação e acesso ficam nos detalhes da assinatura.
              </p>
            </template>
          </UPopover>
        </span>
      </template>
      <template #action>
        <UDropdownMenu
          :items="[[
            { label: 'Selecionar clientes da carteira', icon: 'i-lucide-list-checks', onSelect: () => pickerOpen = true },
            { label: 'Cadastrar novo cliente', icon: 'i-lucide-building-2', onSelect: () => navigateTo('/gestao-erp/empresas/nova?modo=novo') }
          ]]"
        >
          <UButton icon="i-lucide-plus" trailing-icon="i-lucide-chevron-down" color="neutral">
            Novo cliente
          </UButton>
        </UDropdownMenu>
      </template>
      <template #toolbar>
        <DataTableFilterPanel v-model="companyFilters" :columns="companyFilterColumns" class="w-full sm:w-auto">
          <UInput
            :model-value="query"
            icon="i-lucide-search"
            placeholder="Buscar por nome ou CNPJ"
            aria-label="Buscar empresa"
            class="w-full min-w-0 sm:w-64"
            @update:model-value="updateQuery"
          />
        </DataTableFilterPanel>
      </template>
      <div :class="companyListBodyClass">
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
            :sorting="tableSorting"
          >
            <template #name-header>
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                class="-mx-2 min-h-8"
                :trailing-icon="sort === 'name' ? (direction === 'asc' ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down') : 'i-lucide-arrow-up-down'"
                @click="ordered('name')"
              >
                Empresa
              </UButton>
            </template>
            <template #registered_at-header>
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                class="-mx-2 min-h-8"
                :trailing-icon="sort === 'registered_at' ? (direction === 'asc' ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down') : 'i-lucide-arrow-up-down'"
                @click="ordered('registered_at')"
              >
                Cadastrada em
              </UButton>
            </template>
            <template #name-cell="{ row }">
              <div role="group" aria-label="Identificação da empresa" class="flex min-w-0 items-center gap-3">
                <div class="min-w-0 space-y-0.5">
                  <NuxtLink class="block min-w-0 break-words font-medium text-highlighted underline-offset-4 hover:underline" :to="`/gestao-erp/assinaturas/empresas/${row.original.id}?secao=company`">
                    {{ row.original.name }}
                  </NuxtLink>
                  <p class="text-xs text-muted">
                    {{ formatTaxId(row.original.cnpj) }}
                  </p>
                  <p class="text-xs text-muted md:hidden">
                    Cadastrada em {{ formattedDate(row.original.registered_at) }}
                  </p>
                  <UBadge
                    v-if="row.original.capabilities.relationship !== 'current'"
                    :color="row.original.capabilities.relationship === 'unassigned' ? 'neutral' : 'warning'"
                    variant="subtle"
                    size="sm"
                  >
                    {{ row.original.capabilities.relationship === 'unassigned' ? 'Sem assinatura' : 'Histórico' }}
                  </UBadge>
                </div>
              </div>
            </template>
            <template #registered_at-cell="{ row }">
              {{ formattedDate(row.original.registered_at) }}
            </template>
            <template #actions-cell="{ row }">
              <div class="flex flex-wrap items-center justify-end gap-2 sm:gap-3">
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  class="min-h-11 justify-center md:min-h-8"
                  @click="inspectCompany(row.original.id)"
                >
                  Ver cadastro
                </UButton>
                <DataTableRowActionsMenu
                  v-if="companyActions(row.original).length"
                  :items="companyActions(row.original)"
                  :label="`Mais opções de ${row.original.name}`"
                />
              </div>
            </template>
          </OfficeErpDataTable>
          <OfficeErpPagination
            v-model:page="page"
            :class="companyListFooterClass"
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
