<script setup lang="ts">
import { useId } from 'vue'

type ApprovalMode = 'owner' | 'office'
type Delegation = {
  id: number
  account: { id: number, name: string }
  status: 'active' | 'revoked' | string
  effective_permissions: string[]
}
type OwnerMappingState = {
  delegation_id: number | null
  status: string
  delegation: Delegation | null
  mappings: Array<{ id: number, erp_establishment_id: number, revision: number, status: string }>
}
type PermissionChoice = { permission: string, label: string, write: boolean }
type CompanyOption = { id: number, name: string, status: 'active' | 'suspended', access_kind: 'direct' | 'delegated', role: string, permissions: string[], delegated_office_id: number | null }
type EstablishmentOption = { id: number, name: string }
type ClientOption = { id: number, name: string, tax_id: string | null, status: string }
type ClientSearchPage = { data: ClientOption[], meta: { current_page: number, last_page: number } }
type Mapping = { id: number, erp_company_id: number, erp_establishment_id: number, delegation_id: number, client_id: number, revision: number, status: string }
type OfficeContext = { delegation_id: number, status: string, establishments: EstablishmentOption[] }
type OfficeContextPage = { data: OfficeContext, meta: { current_page: number, last_page: number, per_page: number, total: number } }

const props = defineProps<{ mode: ApprovalMode }>()
const mode = computed(() => props.mode)
const { user, currentCompany, currentAccount } = useAuth()
const { $api } = useNuxtApp()
const selectedCompanyId = ref<number | null>(null)
const { key: contextKey, capture, isCurrent, isCompanyOwner, isAccountAdmin } = useErpAdministrationContext(() => selectedCompanyId.value)

const ownerState = ref<OwnerMappingState | null>(null)
const permissionChoices = ref<PermissionChoice[]>([])
const officeContext = ref<OfficeContext | null>(null)
const mappings = ref<Mapping[]>([])
const officePage = ref(1)
const officeLastPage = ref(1)
const clients = ref<ClientOption[]>([])
const clientPage = ref(1)
const clientLastPage = ref(1)
const selectedEstablishmentId = ref<number | null>(null)
const selectedClientId = ref<number | null>(null)
const clientQuery = ref('')
const submittedClientQuery = ref('')
const clientResultsQuery = ref('')
const clientResultsStale = ref(false)
const accountId = ref('')
const ownerPermissions = ref<string[]>(['erp.read'])
const busyAction = ref('')
const loading = ref(false)
const searchingClients = ref(false)
const officeDataStale = ref(false)
const ownerDataStale = ref(false)
const authorityBlocked = ref(false)
const errorMessage = ref('')
const listError = ref('')
const fieldErrors = ref<Record<string, string>>({})
const fieldErrorId = useId()
const confirmAction = ref<'grant' | 'revoke' | 'approval' | 'mapping-revoke' | ''>('')
const pendingMapping = ref<Mapping | null>(null)

const officeCompanies = computed(() => (user.value?.companies ?? []).filter((company): company is CompanyOption =>
  company.status === 'active'
  && (company.access_kind === 'direct' || company.delegated_office_id === currentAccount.value?.id)))
const selectedOfficeCompany = computed(() => officeCompanies.value.find(company => company.id === selectedCompanyId.value) ?? null)
const ownerMode = computed(() => props.mode === 'owner')
const officeMode = computed(() => props.mode === 'office')
const canManageOwnerDelegation = computed(() => ownerMode.value && !authorityBlocked.value && isCompanyOwner.value)
const canApproveForOffice = computed(() => officeMode.value && !authorityBlocked.value && isAccountAdmin.value)
const canUseOfficeContext = computed(() => canApproveForOffice.value && !officeDataStale.value)
const selectedEstablishment = computed(() => officeContext.value?.establishments.find(row => row.id === selectedEstablishmentId.value) ?? null)
const selectedClient = computed(() => !clientResultsStale.value && clientResultsQuery.value === clientQuery.value.trim()
  ? clients.value.find(row => row.id === selectedClientId.value) ?? null
  : null)
let ownerReadGeneration = 0
let officeReadGeneration = 0
let clientSearchGeneration = 0
let ownerReadController: AbortController | undefined
let officeReadController: AbortController | undefined
let clientSearchController: AbortController | undefined

function invalidateReads() {
  ownerReadGeneration++
  officeReadGeneration++
  clientSearchGeneration++
  ownerReadController?.abort()
  officeReadController?.abort()
  clientSearchController?.abort()
}

onScopeDispose(invalidateReads)
watch(authorityBlocked, (blocked) => {
  if (!blocked) return
  invalidateReads()
  loading.value = false
  searchingClients.value = false
}, { flush: 'sync' })

function api<T>(path: string, options?: Record<string, unknown>): Promise<T> {
  return $api<T>(path, options as never)
}

function clearOfficeData(preserveClientQuery = false) {
  const retainedClientQuery = clientQuery.value
  officeContext.value = null
  mappings.value = []
  officePage.value = 1
  officeLastPage.value = 1
  clients.value = []
  clientPage.value = 1
  clientLastPage.value = 1
  selectedEstablishmentId.value = null
  selectedClientId.value = null
  clientQuery.value = preserveClientQuery ? retainedClientQuery : ''
  submittedClientQuery.value = ''
  clientResultsQuery.value = ''
  clientResultsStale.value = false
  officeDataStale.value = false
  confirmAction.value = ''
  pendingMapping.value = null
  fieldErrors.value = {}
  errorMessage.value = ''
  listError.value = ''
}

function clearOwnerData() {
  ownerState.value = null
  permissionChoices.value = []
  accountId.value = ''
  ownerPermissions.value = ['erp.read']
  ownerDataStale.value = false
  confirmAction.value = ''
  fieldErrors.value = {}
  errorMessage.value = ''
  listError.value = ''
}

function isAuthorizationLoss(error: unknown): boolean {
  return [401, 403].includes(apiStatus(error) ?? 0)
}

function clearOwnerResults() {
  ownerState.value = null
  permissionChoices.value = []
  ownerDataStale.value = false
  confirmAction.value = ''
}

function clearOfficeResults(preserveClientQuery = true) {
  clearOfficeData(preserveClientQuery)
}

function handleOwnerAuthorityError(error: unknown) {
  if (isAuthorizationLoss(error)) {
    clearOwnerData()
    authorityBlocked.value = true
    return
  }
  if (apiStatus(error) === 409) {
    clearOwnerResults()
    return
  }
  ownerDataStale.value = ownerState.value !== null
}

function handleOfficeAuthorityError(error: unknown) {
  const status = apiStatus(error)
  if (isAuthorizationLoss(error)) {
    clearOfficeResults()
    authorityBlocked.value = true
    return
  }
  if ([404, 409].includes(status ?? 0)) {
    clearOfficeResults()
    return
  }
  officeDataStale.value = officeContext.value !== null || mappings.value.length > 0
}

function handleOfficeActionError(error: unknown) {
  if (isAuthorizationLoss(error)) {
    clearOfficeResults()
    authorityBlocked.value = true
    return
  }
  if (apiStatus(error) === 409) {
    clearOfficeResults()
    return
  }
  officeDataStale.value = officeContext.value !== null || mappings.value.length > 0
}

function handleOwnerActionError(error: unknown) {
  if (isAuthorizationLoss(error)) {
    clearOwnerData()
    authorityBlocked.value = true
    return
  }
  if (apiStatus(error) === 409) clearOwnerResults()
  else ownerDataStale.value = ownerState.value !== null
}

async function loadOwner() {
  if (!canManageOwnerDelegation.value || !currentCompany.value?.id) return
  const token = capture()
  const request = ++ownerReadGeneration
  ownerReadController?.abort()
  const controller = new AbortController()
  ownerReadController = controller
  loading.value = true
  listError.value = ''
  try {
    const [state, permissions] = await Promise.all([
      api<{ data: OwnerMappingState }>('/erp/accounting-mappings', { signal: controller.signal }),
      api<{ data: PermissionChoice[] }>('/erp/member-permissions', { signal: controller.signal })
    ])
    if (!isCurrent(token) || request !== ownerReadGeneration) return
    ownerState.value = state.data
    ownerDataStale.value = false
    permissionChoices.value = permissions.data.filter(item => !['erp.accounting-mappings.index', 'erp.accounting-deliveries.store'].includes(item.permission))
    if (state.data.delegation) {
      accountId.value = String(state.data.delegation.account.id)
      ownerPermissions.value = [...state.data.delegation.effective_permissions]
    } else {
      ownerPermissions.value = ['erp.read']
    }
  } catch (error) {
    if (controller.signal.aborted) return
    if (isCurrent(token) && request === ownerReadGeneration) {
      controller.abort()
      handleOwnerAuthorityError(error)
      listError.value = apiErrorMessage(error) ?? 'Não foi possível carregar a delegação atual.'
    }
  } finally {
    if (isCurrent(token) && request === ownerReadGeneration) loading.value = false
    if (ownerReadController === controller) ownerReadController = undefined
  }
}

async function loadOfficeContext(companyId: number, page = 1, append = false) {
  if (!canApproveForOffice.value || !officeCompanies.value.some(company => company.id === companyId)) return
  const token = capture()
  const request = ++officeReadGeneration
  officeReadController?.abort()
  const controller = new AbortController()
  officeReadController = controller
  loading.value = true
  listError.value = ''
  try {
    const query = new URLSearchParams({ company_id: String(companyId), page: String(page), per_page: '100' })
    const contextPromise = api<OfficeContextPage>(`/account/erp-accounting-mappings/context?${query}`, { signal: controller.signal })
    const mappingPromise = append ? Promise.resolve(null) : api<{ data: Mapping[] }>(`/account/erp-accounting-mappings?company_id=${companyId}`, { signal: controller.signal })
    const [context, approved] = await Promise.all([
      contextPromise,
      mappingPromise
    ])
    if (!isCurrent(token) || request !== officeReadGeneration) return
    const priorEstablishments = append ? officeContext.value?.establishments ?? [] : []
    const establishments = [...priorEstablishments, ...context.data.establishments]
    officeContext.value = { ...context.data, establishments: [...new Map(establishments.map(item => [item.id, item])).values()] }
    officePage.value = context.meta.current_page
    officeLastPage.value = context.meta.last_page
    officeDataStale.value = false
    if (approved) mappings.value = approved.data
    if (!append && !selectedEstablishmentId.value) selectedEstablishmentId.value = context.data.establishments[0]?.id ?? null
  } catch (error) {
    if (controller.signal.aborted) return
    if (isCurrent(token) && request === officeReadGeneration) {
      controller.abort()
      handleOfficeAuthorityError(error)
      listError.value = apiErrorMessage(error) ?? 'Não foi possível carregar o contexto da empresa.'
    }
  } finally {
    if (isCurrent(token) && request === officeReadGeneration) loading.value = false
    if (officeReadController === controller) officeReadController = undefined
  }
}

function loadMoreEstablishments() {
  if (canUseOfficeContext.value && selectedCompanyId.value && officePage.value < officeLastPage.value && !loading.value)
    void loadOfficeContext(selectedCompanyId.value, officePage.value + 1, true)
}

function selectOfficeCompany(rawId: string | number | null) {
  const id = rawId === null || rawId === '' ? null : Number(rawId)
  selectedCompanyId.value = Number.isSafeInteger(id) && Number(id) > 0 ? Number(id) : null
  clearOfficeData()
}

async function searchClients(page = 1, append = false) {
  if (!canUseOfficeContext.value || !selectedOfficeCompany.value || busyAction.value || searchingClients.value) return
  const query = append ? submittedClientQuery.value : clientQuery.value.trim()
  if (page === 1 && query.length < 2) {
    if (clientResultsQuery.value !== query) {
      clientResultsStale.value = clients.value.length > 0
      selectedClientId.value = null
      confirmAction.value = ''
    }
    fieldErrors.value = { ...fieldErrors.value, client_query: 'Informe ao menos dois caracteres para pesquisar clientes deste escritório.' }
    return
  }
  if (page > 1 && (!submittedClientQuery.value || page !== clientPage.value + 1 || page > clientLastPage.value)) return
  const token = capture()
  const request = ++clientSearchGeneration
  clientSearchController?.abort()
  const controller = new AbortController()
  clientSearchController = controller
  if (!append) {
    submittedClientQuery.value = query
    clientResultsStale.value = clients.value.length > 0 && clientResultsQuery.value !== query
    if (clientResultsQuery.value !== query) selectedClientId.value = null
  }
  searchingClients.value = true
  fieldErrors.value = { ...fieldErrors.value, client_query: '' }
  try {
    const params = new URLSearchParams({ q: query, status: 'active', per_page: '25', page: String(page) })
    const response = await api<ClientSearchPage>(`/clients?${params}`, { signal: controller.signal })
    if (!isCurrent(token) || request !== clientSearchGeneration) return
    const activeClients = response.data.filter(client => client.status === 'active')
    clients.value = append
      ? [...new Map([...clients.value, ...activeClients].map(client => [client.id, client])).values()]
      : activeClients
    clientPage.value = response.meta.current_page
    clientLastPage.value = response.meta.last_page
    clientResultsQuery.value = query
    clientResultsStale.value = false
    if (!append && !clients.value.some(client => client.id === selectedClientId.value)) selectedClientId.value = null
    if (clients.value.length === 0) fieldErrors.value = { ...fieldErrors.value, client_query: 'Nenhum cliente ativo deste escritório corresponde à busca.' }
  } catch (error) {
    if (controller.signal.aborted) return
    if (!isCurrent(token) || request !== clientSearchGeneration) return
    const status = apiStatus(error)
    if (isAuthorizationLoss(error) || [404, 409].includes(status ?? 0)) {
      clearOfficeResults()
      if (isAuthorizationLoss(error)) authorityBlocked.value = true
    } else {
      clientResultsStale.value = clients.value.length > 0
      selectedClientId.value = null
    }
    fieldErrors.value = { ...fieldErrors.value, ...apiFieldErrors(error) }
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível pesquisar clientes.'
  } finally {
    if (isCurrent(token) && request === clientSearchGeneration) searchingClients.value = false
    if (clientSearchController === controller) clientSearchController = undefined
  }
}

function loadMoreClients() {
  if (clientPage.value < clientLastPage.value && !searchingClients.value && !busyAction.value)
    void searchClients(clientPage.value + 1, true)
}

function requestApprovalConfirmation() {
  if (!canUseOfficeContext.value || clientResultsStale.value || !officeContext.value || !selectedEstablishment.value || !selectedClient.value || busyAction.value) return
  confirmAction.value = 'approval'
  errorMessage.value = ''
}

async function approveMapping() {
  if (!canUseOfficeContext.value || clientResultsStale.value || !officeContext.value || !selectedEstablishment.value || !selectedClient.value || busyAction.value || confirmAction.value !== 'approval') return
  const token = capture()
  busyAction.value = 'approve'
  errorMessage.value = ''
  fieldErrors.value = {}
  try {
    await api('/account/erp-accounting-mappings', {
      method: 'POST',
      body: {
        delegation_id: officeContext.value.delegation_id,
        erp_establishment_id: selectedEstablishment.value.id,
        client_id: selectedClient.value.id
      }
    })
    if (!isCurrent(token)) return
    confirmAction.value = ''
    await loadOfficeContext(selectedCompanyId.value!)
  } catch (error) {
    if (!isCurrent(token)) return
    handleOfficeActionError(error)
    fieldErrors.value = apiFieldErrors(error)
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível aprovar o vínculo.'
    confirmAction.value = ''
  } finally {
    if (isCurrent(token)) busyAction.value = ''
  }
}

function requestMappingRevocation(mapping: Mapping) {
  if (!canUseOfficeContext.value || busyAction.value || mapping.status !== 'active') return
  pendingMapping.value = mapping
  confirmAction.value = 'mapping-revoke'
}

async function revokeMapping() {
  const mapping = pendingMapping.value
  if (!mapping || !canUseOfficeContext.value || busyAction.value || confirmAction.value !== 'mapping-revoke') return
  const token = capture()
  busyAction.value = 'mapping-revoke'
  errorMessage.value = ''
  try {
    await api(`/account/erp-accounting-mappings/${mapping.id}`, { method: 'DELETE' })
    if (!isCurrent(token)) return
    pendingMapping.value = null
    confirmAction.value = ''
    await loadOfficeContext(selectedCompanyId.value!)
  } catch (error) {
    if (!isCurrent(token)) return
    handleOfficeActionError(error)
    fieldErrors.value = apiFieldErrors(error)
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível revogar o vínculo.'
  } finally {
    if (isCurrent(token)) busyAction.value = ''
  }
}

function toggleOwnerPermission(permission: string, checked: boolean) {
  const grants = new Set(ownerPermissions.value)
  if (checked) grants.add(permission)
  else grants.delete(permission)
  ownerPermissions.value = [...grants]
}

function requestDelegationGrant() {
  if (!canManageOwnerDelegation.value || ownerDataStale.value || busyAction.value) return
  const parsed = Number(accountId.value)
  if (!Number.isSafeInteger(parsed) || parsed <= 0) {
    fieldErrors.value = { account_id: 'Informe o ID positivo do escritório ao qual a empresa concederá acesso.' }
    return
  }
  fieldErrors.value = {}
  confirmAction.value = 'grant'
}

async function grantDelegation() {
  if (!canManageOwnerDelegation.value || ownerDataStale.value || busyAction.value || confirmAction.value !== 'grant' || !currentCompany.value?.id) return
  const parsedAccountId = Number(accountId.value)
  if (!Number.isSafeInteger(parsedAccountId) || parsedAccountId <= 0) {
    fieldErrors.value = { account_id: 'Informe o ID positivo do escritório.' }
    return
  }
  const token = capture()
  busyAction.value = 'grant'
  errorMessage.value = ''
  fieldErrors.value = {}
  try {
    await api('/erp/delegations', {
      method: 'POST',
      body: {
        company_id: currentCompany.value.id,
        account_id: parsedAccountId,
        permissions: ownerPermissions.value
      }
    })
    if (!isCurrent(token)) return
    confirmAction.value = ''
    await loadOwner()
  } catch (error) {
    if (!isCurrent(token)) return
    handleOwnerActionError(error)
    fieldErrors.value = apiFieldErrors(error)
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível salvar a delegação.'
    confirmAction.value = ''
  } finally {
    if (isCurrent(token)) busyAction.value = ''
  }
}

function requestDelegationRevocation() {
  if (!canManageOwnerDelegation.value || ownerDataStale.value || !ownerState.value?.delegation || busyAction.value) return
  confirmAction.value = 'revoke'
}

async function revokeDelegation() {
  const delegation = ownerState.value?.delegation
  if (!canManageOwnerDelegation.value || ownerDataStale.value || !delegation || busyAction.value || confirmAction.value !== 'revoke') return
  const token = capture()
  busyAction.value = 'revoke'
  errorMessage.value = ''
  try {
    const response = await api<{ data: { id: number, status: string } }>(`/erp/delegations/${delegation.id}`, { method: 'DELETE' })
    if (!isCurrent(token)) return
    ownerState.value = { ...(ownerState.value as OwnerMappingState), status: response.data.status, delegation: { ...delegation, status: response.data.status } }
    confirmAction.value = ''
  } catch (error) {
    if (!isCurrent(token)) return
    handleOwnerActionError(error)
    fieldErrors.value = apiFieldErrors(error)
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível revogar a delegação.'
  } finally {
    if (isCurrent(token)) busyAction.value = ''
  }
}

watch(contextKey, () => {
  invalidateReads()
  loading.value = false
  busyAction.value = ''
  searchingClients.value = false
  authorityBlocked.value = false
  if (ownerMode.value) {
    clearOwnerData()
    if (canManageOwnerDelegation.value) void loadOwner()
    return
  }
  clearOfficeData()
  if (canApproveForOffice.value && selectedOfficeCompany.value) void loadOfficeContext(selectedOfficeCompany.value.id)
}, { immediate: true, flush: 'sync' })

watch(clientQuery, (query) => {
  if (searchingClients.value && query.trim() !== submittedClientQuery.value) {
    clientSearchGeneration++
    clientSearchController?.abort()
    searchingClients.value = false
  }
  const matchesLastSearch = query.trim() === clientResultsQuery.value
  if (!matchesLastSearch) {
    clientResultsStale.value = clients.value.length > 0
    selectedClientId.value = null
    if (confirmAction.value === 'approval') confirmAction.value = ''
  } else if (clientResultsQuery.value) {
    clientResultsStale.value = false
  }
}, { flush: 'sync' })

watch(() => currentAccount.value?.id, (id, before) => {
  if (id !== before) selectedCompanyId.value = null
}, { flush: 'sync' })

defineExpose({ ownerState, permissionChoices, officeContext, mappings, clients, clientPage, clientLastPage, clientResultsStale, officeDataStale, ownerDataStale, canApproveForOffice, selectedCompanyId, selectedEstablishmentId, selectedClientId, officePage, officeLastPage,
  accountId, ownerPermissions, busyAction, errorMessage, fieldErrors, confirmAction, selectOfficeCompany, loadOwner, loadOfficeContext,
  loadMoreEstablishments, searchClients, loadMoreClients, requestApprovalConfirmation, approveMapping, requestMappingRevocation, revokeMapping, toggleOwnerPermission,
  requestDelegationGrant, grantDelegation, requestDelegationRevocation, revokeDelegation })
</script>

<template>
  <section class="mx-auto w-full max-w-4xl space-y-5" :data-mode="mode">
    <template v-if="ownerMode">
      <header class="space-y-1">
        <h2 class="text-xl font-semibold">
          Escritório contábil
        </h2>
        <p class="text-muted text-sm">
          Conceda acesso à empresa para um escritório identificado pelo ID da conta. A aprovação dos clientes é feita pelo administrador do próprio escritório.
        </p>
      </header>
      <UAlert v-if="!canManageOwnerDelegation" color="warning" title="Acesso restrito">
        Somente o proprietário direto da empresa pode alterar a delegação.
      </UAlert>
      <UAlert v-if="errorMessage" color="error" title="Não foi possível concluir a ação">
        {{ errorMessage }}
      </UAlert>
      <UAlert v-if="listError" color="error" title="Não foi possível carregar a delegação">
        <template #description>
          <span>{{ listError }}</span><UButton
            class="ml-2"
            variant="link"
            :disabled="loading || authorityBlocked"
            @click="loadOwner"
          >
            Tentar novamente
          </UButton>
        </template>
      </UAlert>
      <UAlert v-if="ownerDataStale" color="warning" title="Delegação desatualizada">
        A última leitura permanece visível. Atualize antes de agir.
      </UAlert>

      <template v-if="canManageOwnerDelegation">
        <UCard v-if="ownerState?.delegation" class="space-y-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="font-medium">
                {{ ownerState.delegation.account.name }}
              </h3>
              <p class="text-muted text-sm">
                ID do escritório: {{ ownerState.delegation.account.id }}
              </p>
            </div>
            <UBadge :color="ownerState.delegation.status === 'active' ? 'success' : 'neutral'" variant="subtle">
              {{ ownerState.delegation.status === 'active' ? 'Delegação ativa' : 'Delegação revogada' }}
            </UBadge>
          </div>
          <p class="text-muted text-sm">
            Permissões efetivas concedidas:
          </p>
          <div class="flex flex-wrap gap-1">
            <UBadge
              v-for="permission in ownerState.delegation.effective_permissions"
              :key="permission"
              color="neutral"
              variant="subtle"
            >
              {{ permission }}
            </UBadge>
          </div>
          <div v-if="ownerState.delegation.status === 'active'" class="space-y-3">
            <h3 class="font-medium">
              Aprovação pelo escritório
            </h3>
            <p v-if="!ownerState.mappings.length" class="text-muted text-sm" role="status">
              Aguardando aprovação pelo escritório. Nenhum vínculo foi aprovado para esta delegação.
            </p>
            <ul v-else class="space-y-2" aria-label="Vínculos contábeis dos estabelecimentos">
              <li v-for="mapping in ownerState.mappings" :key="mapping.id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>Estabelecimento #{{ mapping.erp_establishment_id }} · Revisão {{ mapping.revision }}</span>
                <UBadge :color="mapping.status === 'active' ? 'success' : 'neutral'" variant="subtle">
                  {{ mapping.status === 'active' ? 'Aprovado' : mapping.status === 'revoked' ? 'Revogado' : 'Aguardando aprovação' }}
                </UBadge>
              </li>
            </ul>
            <UButton
              color="error"
              variant="soft"
              :disabled="!!busyAction || ownerDataStale"
              @click="requestDelegationRevocation"
            >
              Revogar delegação
            </UButton>
            <div v-if="confirmAction === 'revoke'" class="rounded-lg border border-warning/50 p-3" role="alertdialog">
              <p class="text-sm">
                Confirme a revogação do acesso deste escritório à empresa.
              </p>
              <div class="mt-2 flex gap-2">
                <UButton
                  color="error"
                  :loading="busyAction === 'revoke'"
                  :disabled="!!busyAction"
                  @click="revokeDelegation"
                >
                  Confirmar revogação
                </UButton><UButton
                  color="neutral"
                  variant="outline"
                  :disabled="!!busyAction"
                  @click="confirmAction = ''"
                >
                  Cancelar
                </UButton>
              </div>
            </div>
          </div>
        </UCard>
        <p v-else-if="!loading && !listError" class="rounded-xl border border-dashed border-default p-5 text-sm">
          Nenhuma delegação ativa. O primeiro vínculo precisa ser concedido pelo proprietário.
        </p>

        <form class="space-y-4 rounded-xl border border-default p-4" @submit.prevent="requestDelegationGrant">
          <h3 class="font-medium">
            {{ ownerState?.delegation?.status === 'active' ? 'Substituir escritório autorizado' : 'Conceder acesso ao escritório' }}
          </h3>
          <label class="block space-y-1 text-sm">
            <span>ID da conta do escritório</span>
            <input
              v-model="accountId"
              inputmode="numeric"
              required
              min="1"
              type="number"
              class="w-full rounded-lg border border-default bg-default px-3 py-2"
              :aria-invalid="!!fieldErrors.account_id"
              :aria-describedby="fieldErrors.account_id ? `${fieldErrorId}-account_id` : undefined"
            >
            <span v-if="fieldErrors.account_id" :id="`${fieldErrorId}-account_id`" class="text-error">{{ fieldErrors.account_id }}</span>
          </label>
          <fieldset class="space-y-2">
            <legend class="text-sm font-medium">
              Permissões do escritório
            </legend>
            <label v-for="choice in permissionChoices" :key="choice.permission" class="flex items-start gap-2 text-sm">
              <input type="checkbox" :checked="ownerPermissions.includes(choice.permission)" @change="toggleOwnerPermission(choice.permission, ($event.target as HTMLInputElement).checked)">
              <span>{{ choice.label }} <span class="text-muted">{{ choice.write ? '· escrita' : '· leitura' }}</span></span>
            </label>
            <p class="text-muted text-xs">
              Sem permissões selecionadas, a delegação efetiva fica limitada a erp.read.
            </p>
          </fieldset>
          <div v-if="confirmAction === 'grant'" class="rounded-lg border border-warning/50 p-3" role="alertdialog">
            <p class="text-sm">
              A confirmação substitui qualquer delegação ativa desta empresa. ID do escritório: <strong>{{ accountId }}</strong>.
            </p>
            <div class="mt-2 flex gap-2">
              <UButton
                type="button"
                :loading="busyAction === 'grant'"
                :disabled="!!busyAction || ownerDataStale"
                @click="grantDelegation"
              >
                Confirmar concessão
              </UButton><UButton
                type="button"
                color="neutral"
                variant="outline"
                :disabled="!!busyAction || ownerDataStale"
                @click="confirmAction = ''"
              >
                Cancelar
              </UButton>
            </div>
          </div>
          <UButton v-else type="submit" :disabled="!!busyAction || ownerDataStale">
            {{ ownerState?.delegation?.status === 'active' ? 'Revisar substituição' : 'Revisar concessão' }}
          </UButton>
        </form>
      </template>
    </template>

    <template v-else>
      <header class="space-y-1">
        <h2 class="text-xl font-semibold">
          Aprovação de clientes para o ERP
        </h2>
        <p class="text-muted text-sm">
          Escolha uma empresa delegada e associe cada estabelecimento a um cliente ativo deste escritório. A correspondência por CNPJ nunca aprova um vínculo automaticamente.
        </p>
      </header>
      <UAlert v-if="!canApproveForOffice" color="warning" title="Acesso restrito">
        Esta aprovação exige o papel administrador da conta selecionada.
      </UAlert>
      <UAlert v-if="errorMessage" color="error" title="A ação não foi concluída">
        {{ errorMessage }}
      </UAlert>
      <UAlert v-if="listError" color="error" title="Não foi possível carregar o contexto">
        <template #description>
          <span>{{ listError }}</span><UButton
            class="ml-2"
            variant="link"
            :disabled="loading || authorityBlocked"
            @click="selectedCompanyId && loadOfficeContext(selectedCompanyId)"
          >
            Tentar novamente
          </UButton>
        </template>
      </UAlert>
      <UAlert v-if="officeDataStale" color="warning" title="Contexto desatualizado">
        A última leitura permanece visível. Atualize antes de aprovar ou revogar vínculos.
      </UAlert>

      <template v-if="canApproveForOffice">
        <label class="block space-y-1 text-sm">
          <span>Empresa para aprovação por {{ currentAccount?.name }}</span>
          <select :value="selectedCompanyId ?? ''" class="w-full rounded-lg border border-default bg-default px-3 py-2" @change="selectOfficeCompany(($event.target as HTMLSelectElement).value)">
            <option value="">Selecione uma empresa</option>
            <option v-for="company in officeCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
          </select>
        </label>

        <template v-if="selectedOfficeCompany">
          <UCard v-if="officeContext" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="font-medium">
                  {{ selectedOfficeCompany.name }}
                </h3><p class="text-muted text-sm">
                  Delegação ativa · ID {{ officeContext.delegation_id }}
                </p>
              </div>
              <UBadge color="success" variant="subtle">
                Aguardando aprovação do escritório
              </UBadge>
            </div>
            <label class="block space-y-1 text-sm">
              <span>Estabelecimento ativo</span>
              <select
                v-model.number="selectedEstablishmentId"
                :aria-invalid="!!fieldErrors.erp_establishment_id"
                :aria-describedby="fieldErrors.erp_establishment_id ? `${fieldErrorId}-erp_establishment_id` : undefined"
                class="w-full rounded-lg border border-default bg-default px-3 py-2"
                :disabled="loading || officeDataStale"
              >
                <option :value="null">Selecione um estabelecimento</option>
                <option v-for="establishment in officeContext.establishments" :key="establishment.id" :value="establishment.id">{{ establishment.name }}</option>
              </select>
              <span v-if="fieldErrors.erp_establishment_id" :id="`${fieldErrorId}-erp_establishment_id`" class="text-error">{{ fieldErrors.erp_establishment_id }}</span>
            </label>
            <div v-if="officeLastPage > officePage" class="flex flex-wrap items-center justify-between gap-3">
              <span class="text-muted text-xs">{{ officeContext.establishments.length }} estabelecimentos carregados · página {{ officePage }} de {{ officeLastPage }}</span>
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                :disabled="loading || officeDataStale"
                @click="loadMoreEstablishments"
              >
                Carregar próxima página
              </UButton>
            </div>
            <form class="space-y-2" @submit.prevent="searchClients()">
              <label class="block space-y-1 text-sm">
                <span>Pesquisar cliente ativo neste escritório</span>
                <input
                  v-model="clientQuery"
                  class="w-full rounded-lg border border-default bg-default px-3 py-2"
                  placeholder="Nome ou documento"
                  :aria-invalid="!!fieldErrors.client_query"
                  :aria-describedby="fieldErrors.client_query ? `${fieldErrorId}-client_query` : undefined"
                  autocomplete="off"
                >
              </label>
              <p v-if="fieldErrors.client_query" :id="`${fieldErrorId}-client_query`" class="text-error text-sm">
                {{ fieldErrors.client_query }}
              </p>
              <UButton
                type="submit"
                color="neutral"
                variant="outline"
                :loading="searchingClients"
                :disabled="!!busyAction || searchingClients || officeDataStale"
              >
                Pesquisar clientes
              </UButton>
              <p v-if="clientResultsStale" class="text-warning text-sm" role="status">
                Resultados desatualizados. A seleção foi removida; atualize a busca antes de aprovar.
              </p>
            </form>
            <label v-if="clients.length" class="block space-y-1 text-sm">
              <span>Cliente do escritório</span>
              <select
                v-model.number="selectedClientId"
                :aria-invalid="!!fieldErrors.client_id"
                :aria-describedby="fieldErrors.client_id ? `${fieldErrorId}-client_id` : undefined"
                class="w-full rounded-lg border border-default bg-default px-3 py-2"
                :disabled="clientResultsStale || officeDataStale || searchingClients"
              >
                <option :value="null">Selecione um cliente</option>
                <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}{{ client.tax_id ? ` · ${client.tax_id}` : '' }}</option>
              </select>
            </label>
            <div v-if="clientLastPage > clientPage" class="flex flex-wrap items-center justify-between gap-3">
              <span class="text-muted text-xs">{{ clients.length }} clientes carregados · página {{ clientPage }} de {{ clientLastPage }}</span>
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                :disabled="searchingClients || !!busyAction || clientResultsStale || officeDataStale"
                @click="loadMoreClients"
              >
                Carregar mais clientes
              </UButton>
            </div>
            <p v-if="fieldErrors.client_id" :id="`${fieldErrorId}-client_id`" class="text-error text-sm">
              {{ fieldErrors.client_id }}
            </p>
            <div v-if="selectedClient && selectedEstablishment" class="rounded-lg border border-default p-3 text-sm">
              <p>Vínculo proposto</p>
              <p class="mt-1 font-medium">
                {{ selectedEstablishment.name }} → {{ selectedClient.name }}
              </p>
              <UButton class="mt-3" :disabled="!!busyAction || clientResultsStale || officeDataStale" @click="requestApprovalConfirmation">
                Revisar aprovação
              </UButton>
            </div>
            <div v-if="confirmAction === 'approval'" class="rounded-lg border border-warning/50 p-3" role="alertdialog">
              <p class="text-sm">
                Confirme que <strong>{{ selectedClient?.name }}</strong> é o cliente deste escritório para o estabelecimento <strong>{{ selectedEstablishment?.name }}</strong>.
              </p>
              <div class="mt-2 flex gap-2">
                <UButton :loading="busyAction === 'approve'" :disabled="!!busyAction || officeDataStale" @click="approveMapping">
                  Confirmar aprovação
                </UButton><UButton
                  color="neutral"
                  variant="outline"
                  :disabled="!!busyAction"
                  @click="confirmAction = ''"
                >
                  Cancelar
                </UButton>
              </div>
            </div>
            <p v-if="fieldErrors.delegation_id" class="text-error text-sm">
              {{ fieldErrors.delegation_id }} A delegação pode ter sido revogada; atualize o contexto.
            </p>
          </UCard>

          <div class="space-y-3">
            <h3 class="font-medium">
              Vínculos e histórico
            </h3>
            <p v-if="!mappings.length && !loading" class="text-muted rounded-xl border border-dashed border-default p-4 text-sm">
              Nenhum vínculo aprovado para esta empresa.
            </p>
            <article v-for="mapping in mappings" :key="mapping.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-default p-4">
              <div>
                <p class="font-medium">
                  {{ officeContext?.establishments.find(item => item.id === mapping.erp_establishment_id)?.name ?? `Estabelecimento #${mapping.erp_establishment_id}` }} → Cliente #{{ mapping.client_id }}
                </p>
                <p class="text-muted text-sm">
                  Revisão {{ mapping.revision }} · {{ mapping.status === 'active' ? 'Aprovado' : mapping.status === 'revoked' ? 'Revogado' : mapping.status }}
                </p>
              </div>
              <UButton
                v-if="mapping.status === 'active'"
                color="error"
                variant="soft"
                :disabled="!!busyAction || officeDataStale"
                @click="requestMappingRevocation(mapping)"
              >
                Revogar vínculo
              </UButton>
            </article>
            <div v-if="confirmAction === 'mapping-revoke' && pendingMapping" class="rounded-lg border border-warning/50 p-3" role="alertdialog">
              <p class="text-sm">
                Confirme a revogação do vínculo ativo da revisão {{ pendingMapping.revision }}.
              </p>
              <div class="mt-2 flex gap-2">
                <UButton
                  color="error"
                  :loading="busyAction === 'mapping-revoke'"
                  :disabled="!!busyAction || officeDataStale"
                  @click="revokeMapping"
                >
                  Confirmar revogação
                </UButton><UButton
                  color="neutral"
                  variant="outline"
                  :disabled="!!busyAction"
                  @click="confirmAction = ''; pendingMapping = null"
                >
                  Cancelar
                </UButton>
              </div>
            </div>
          </div>
        </template>
        <p v-else-if="!loading && !listError" class="text-muted rounded-xl border border-dashed border-default p-5 text-sm">
          Escolha uma empresa delegada à conta atual. Essa seleção não altera a empresa ativa no ERP.
        </p>
      </template>
    </template>
  </section>
</template>
