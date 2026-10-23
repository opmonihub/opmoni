import { computed, onScopeDispose, ref, watch } from 'vue'
import type { ErpPaginated } from '#shared/types/erpCatalogs'
import { apiFailure } from '#shared/utils/access'
import { erpCatalogListQuery, erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

const catalogReadPermissions: Record<string, string> = {
  [erpCatalogRoutes.contacts]: 'erp.contacts.index',
  [erpCatalogRoutes.products]: 'erp.products.index',
  [erpCatalogRoutes.units]: 'erp.units.index',
  [erpCatalogRoutes.categories]: 'erp.categories.index',
  [erpCatalogRoutes.brands]: 'erp.brands.index',
  [erpCatalogRoutes.priceGroups]: 'erp.price-groups.index',
  [erpCatalogRoutes.customerGroups]: 'erp.customer-groups.index',
  [erpCatalogRoutes.discountRules]: 'erp.discount-rules.index',
  [erpCatalogRoutes.taxRates]: 'erp.tax-rates.index',
  [erpCatalogRoutes.operationNatures]: 'erp.operation-natures.index',
  [erpCatalogRoutes.carriers]: 'erp.carriers.index',
  [erpCatalogRoutes.vehicles]: 'erp.vehicles.index',
  [erpCatalogRoutes.drivers]: 'erp.drivers.index',
  '/erp/establishments': 'erp.establishments.index'
}

const searchDebounceMs = 250

export function useErpCatalogList<T>(path: string) {
  const { api } = useAccessApi()
  const auth = useAuth()
  const { user, currentCompany, currentAccount } = auth
  const { key: contextKey, capture, isCurrent } = useErpAdministrationContext()
  const search = ref('')
  const page = ref(1)
  const pending = ref(false)
  const searchPending = ref(false)
  const state = ref<ErpPaginated<T> | null>(null)
  const error = ref('')
  const permission = catalogReadPermissions[path]
  const hasLoaded = computed(() => state.value !== null)
  const blockedContextKey = ref<string | null>(null)
  const authorityBlocked = computed(() => blockedContextKey.value === contextKey.value)
  const stale = computed(() => hasLoaded.value && (pending.value || searchPending.value || error.value !== ''))
  const canRead = computed(() => {
    const actor = user.value
    const company = currentCompany.value
    if (!actor?.id || !company || !Number.isSafeInteger(company.id) || company.id < 1 || company.status !== 'active' || !permission)
      return false

    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id)
        return false
    } else if (company.access_kind !== 'direct') {
      return false
    }

    return erpCompanyHasPermission(company.permissions, permission)
  })

  let requestSequence = 0
  let activeController: AbortController | null = null
  let searchTimer: ReturnType<typeof setTimeout> | null = null
  let resettingForContext = false
  let resettingPageForSearch = false
  let disposed = false

  function accessError(): string {
    const company = currentCompany.value
    if (!permission)
      return 'Esta lista não está disponível para consulta.'
    if (!user.value?.id || !company || company.status !== 'active')
      return 'Selecione uma empresa ERP ativa para consultar estes registros.'
    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id)
        return 'Selecione o escritório autorizado para consultar estes registros.'
    }
    return 'Você não tem permissão para consultar estes registros nesta empresa.'
  }

  function clearSearchTimer() {
    if (searchTimer !== null) {
      clearTimeout(searchTimer)
      searchTimer = null
    }
    searchPending.value = false
  }

  function invalidateRequest() {
    requestSequence++
    const controller = activeController
    activeController = null
    controller?.abort()
    pending.value = false
  }

  function blockAuthority(message: string) {
    clearSearchTimer()
    invalidateRequest()
    state.value = null
    blockedContextKey.value = contextKey.value
    error.value = message
  }

  function requestIsCurrent(sequence: number, token: ReturnType<typeof capture>): boolean {
    return !disposed && requestSequence === sequence && isCurrent(token)
  }

  function isAuthorityLoss(failure: ReturnType<typeof apiFailure>): boolean {
    return failure.status === 401
      || failure.status === 403
      || (failure.status === 409 && failure.code === 'company_context_changed')
  }

  async function requestPage(): Promise<void> {
    if (disposed || authorityBlocked.value)
      return
    if (!canRead.value) {
      state.value = null
      pending.value = false
      error.value = accessError()
      return
    }

    invalidateRequest()
    const sequence = requestSequence
    const token = capture()
    const controller = new AbortController()
    activeController = controller
    pending.value = true
    error.value = ''

    try {
      const query = erpCatalogListQuery(search.value, page.value)
      const result = await api<ErpPaginated<T>>(`${path}?${query}`, { signal: controller.signal })
      if (requestIsCurrent(sequence, token))
        state.value = result
    } catch (failure) {
      if (!requestIsCurrent(sequence, token))
        return
      const details = apiFailure(failure)
      if (isAuthorityLoss(details))
        blockAuthority(details.message)
      else
        error.value = details.message
    } finally {
      if (requestIsCurrent(sequence, token)) {
        pending.value = false
        if (activeController === controller)
          activeController = null
      }
    }
  }

  async function refresh(): Promise<void> {
    if (disposed)
      return
    clearSearchTimer()
    if (authorityBlocked.value)
      return
    return requestPage()
  }

  async function retry(): Promise<void> {
    if (disposed || pending.value)
      return
    if (!authorityBlocked.value)
      return refresh()

    const rejectedContext = contextKey.value
    pending.value = true
    error.value = ''
    try {
      const identity = await auth.refresh()
      if (disposed || contextKey.value !== rejectedContext)
        return
      pending.value = false
      if (!identity) {
        error.value = 'Sua sessão precisa ser confirmada antes de consultar estes registros.'
        return
      }
      blockedContextKey.value = null
      if (!canRead.value) {
        error.value = accessError()
        return
      }
      return requestPage()
    } catch (failure) {
      if (!disposed && contextKey.value === rejectedContext && authorityBlocked.value) {
        pending.value = false
        error.value = apiFailure(failure).message
      }
    }
  }

  watch(search, (nextSearch, previousSearch) => {
    if (disposed || resettingForContext || nextSearch === previousSearch)
      return
    if (page.value !== 1) {
      resettingPageForSearch = true
      page.value = 1
      resettingPageForSearch = false
    }
    clearSearchTimer()
    invalidateRequest()
    if (authorityBlocked.value) {
      error.value = accessError()
      return
    }
    searchPending.value = true
    searchTimer = setTimeout(() => {
      searchTimer = null
      searchPending.value = false
      void requestPage()
    }, searchDebounceMs)
  }, { flush: 'sync' })

  watch(page, (nextPage, previousPage) => {
    if (disposed || resettingForContext || resettingPageForSearch || nextPage === previousPage)
      return
    clearSearchTimer()
    void refresh()
  }, { flush: 'sync' })

  watch(contextKey, () => {
    clearSearchTimer()
    invalidateRequest()
    blockedContextKey.value = null
    state.value = null
    error.value = ''
    resettingForContext = true
    search.value = ''
    page.value = 1
    resettingForContext = false
    if (canRead.value)
      void requestPage()
    else
      error.value = accessError()
  }, { flush: 'sync', immediate: true })

  onScopeDispose(() => {
    disposed = true
    clearSearchTimer()
    invalidateRequest()
    state.value = null
    error.value = ''
  })

  return { search, page, pending, searchPending, state, error, hasLoaded, stale, canRead, authorityBlocked, blockAuthority, refresh, retry }
}
