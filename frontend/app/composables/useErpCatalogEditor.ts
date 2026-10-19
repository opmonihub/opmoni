import { computed, onScopeDispose, ref, watch } from 'vue'
import { apiFailure } from '#shared/utils/access'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

type CatalogAction = 'show' | 'store' | 'update' | 'archive'
type CatalogRecord = Record<string, unknown> & { id: number }

const permissionBases: Record<string, string> = {
  [erpCatalogRoutes.contacts]: 'erp.contacts',
  [erpCatalogRoutes.products]: 'erp.products',
  [erpCatalogRoutes.units]: 'erp.units',
  [erpCatalogRoutes.categories]: 'erp.categories',
  [erpCatalogRoutes.brands]: 'erp.brands',
  [erpCatalogRoutes.priceGroups]: 'erp.price-groups',
  [erpCatalogRoutes.discountRules]: 'erp.discount-rules',
  [erpCatalogRoutes.taxRates]: 'erp.tax-rates',
  [erpCatalogRoutes.operationNatures]: 'erp.operation-natures',
  [erpCatalogRoutes.carriers]: 'erp.carriers',
  [erpCatalogRoutes.vehicles]: 'erp.vehicles',
  [erpCatalogRoutes.drivers]: 'erp.drivers'
}

export function useErpCatalogEditor(path: string, refresh: () => Promise<void>) {
  const { api, refreshCsrf } = useAccessApi()
  const auth = useAuth()
  const { user, currentCompany, currentAccount } = auth
  const context = useErpAdministrationContext()
  const base = permissionBases[path]
  const record = ref<CatalogRecord | null>(null)
  const mode = ref<'create' | 'detail' | 'edit'>('create')
  const open = ref(false)
  const loadingDetail = ref(false)
  const saving = ref(false)
  const archiving = ref(false)
  const refreshingAuthority = ref(false)
  const error = ref('')
  const fieldErrors = ref<Record<string, string[]>>({})
  const blockedContextKey = ref<string | null>(null)
  const authorityBlocked = computed(() => blockedContextKey.value === context.key.value)
  const mutationPending = computed(() => saving.value || archiving.value)

  function hasAction(action: CatalogAction): boolean {
    const actor = user.value
    const company = currentCompany.value
    if (!actor?.id || !company || company.status !== 'active' || !base)
      return false

    const permission = `${base}.${action}`
    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id)
        return false
      if (action !== 'show' && !['admin', 'operador'].includes(office.role))
        return false
      return company.permissions.includes(permission)
    }
    if (company.access_kind !== 'direct')
      return false
    if (action !== 'show' && !['owner', 'manager', 'operator'].includes(company.role))
      return false
    return erpCompanyHasPermission(company.permissions, permission)
  }

  const permissions = {
    show: computed(() => hasAction('show')),
    store: computed(() => hasAction('store')),
    update: computed(() => hasAction('update')),
    archive: computed(() => hasAction('archive')),
    edit: computed(() => hasAction('show') && hasAction('update'))
  }
  const canSave = computed(() => !authorityBlocked.value && !mutationPending.value && (
    mode.value === 'create'
      ? permissions.store.value
      : mode.value === 'edit' && permissions.edit.value && record.value !== null
  ))

  let sequence = 0
  let controller: AbortController | null = null
  let disposed = false

  function invalidate() {
    sequence++
    controller?.abort()
    controller = null
    loadingDetail.value = false
    saving.value = false
    archiving.value = false
    refreshingAuthority.value = false
  }

  function current(requestSequence: number, token: ReturnType<typeof context.capture>): boolean {
    return !disposed && sequence === requestSequence && context.isCurrent(token)
  }

  function beginRequest() {
    controller?.abort()
    const requestController = new AbortController()
    controller = requestController
    sequence++
    return { requestSequence: sequence, requestController, token: context.capture() }
  }

  function clearErrors() {
    error.value = ''
    fieldErrors.value = {}
  }

  function isAuthorityLoss(status: number, code: string): boolean {
    return status === 401 || status === 403 || (status === 409 && code === 'company_context_changed')
  }

  function blockAuthority(message: string) {
    blockedContextKey.value = context.key.value
    invalidate()
    open.value = false
    record.value = null
    fieldErrors.value = {}
    error.value = message
  }

  function handleFailure(failure: unknown) {
    const details = apiFailure(failure)
    if (isAuthorityLoss(details.status, details.code)) {
      blockAuthority(details.message)
      return
    }
    error.value = details.message
    fieldErrors.value = details.errors ?? {}
  }

  function cancel() {
    if (mutationPending.value)
      return
    invalidate()
    open.value = false
    record.value = null
    clearErrors()
  }

  function create() {
    if (authorityBlocked.value) {
      error.value = 'Confirme seu acesso antes de criar registros.'
      return
    }
    if (!permissions.store.value) {
      error.value = 'Você não tem permissão para criar registros nesta empresa.'
      return
    }
    if (mutationPending.value)
      return
    invalidate()
    mode.value = 'create'
    record.value = null
    open.value = true
    clearErrors()
  }

  async function loadRecord(id: number, nextMode: 'detail' | 'edit'): Promise<void> {
    if (authorityBlocked.value) {
      error.value = 'Confirme seu acesso antes de consultar registros.'
      return
    }
    if (mutationPending.value || loadingDetail.value)
      return
    if (nextMode === 'detail' && !permissions.show.value) {
      error.value = 'Você não tem permissão para consultar este registro.'
      return
    }
    if (nextMode === 'edit' && !permissions.edit.value) {
      error.value = 'Você precisa de permissão para consultar e editar este registro.'
      return
    }

    invalidate()
    clearErrors()
    mode.value = nextMode
    record.value = null
    open.value = true
    loadingDetail.value = true
    const request = beginRequest()
    try {
      const response = await api<{ data: CatalogRecord }>(`${path}/${id}`, { signal: request.requestController.signal })
      if (current(request.requestSequence, request.token))
        record.value = response.data
    } catch (failure) {
      if (current(request.requestSequence, request.token))
        handleFailure(failure)
    } finally {
      if (current(request.requestSequence, request.token)) {
        loadingDetail.value = false
        if (controller === request.requestController)
          controller = null
      }
    }
  }

  function view(id: number): Promise<void> {
    return loadRecord(id, 'detail')
  }

  function edit(id: number): Promise<void> {
    return loadRecord(id, 'edit')
  }

  async function save(payload: Record<string, unknown>): Promise<void> {
    if (mutationPending.value || authorityBlocked.value)
      return
    if (mode.value === 'detail') {
      error.value = 'Este registro está em modo de consulta.'
      return
    }
    const isEdit = mode.value === 'edit'
    if (isEdit ? !permissions.update.value || !record.value : !permissions.store.value) {
      error.value = 'Sua permissão mudou. Atualize seu acesso antes de salvar.'
      return
    }

    const request = beginRequest()
    saving.value = true
    clearErrors()
    try {
      await refreshCsrf()
      if (!current(request.requestSequence, request.token))
        return
      const id = record.value?.id
      await api<{ data: CatalogRecord }>(isEdit ? `${path}/${id}` : path, {
        method: isEdit ? 'PATCH' : 'POST',
        body: payload,
        signal: request.requestController.signal
      })
      if (!current(request.requestSequence, request.token))
        return
      open.value = false
      record.value = null
      await refresh()
    } catch (failure) {
      if (current(request.requestSequence, request.token))
        handleFailure(failure)
    } finally {
      if (current(request.requestSequence, request.token)) {
        saving.value = false
        if (controller === request.requestController)
          controller = null
      }
    }
  }

  async function archive(id: number): Promise<void> {
    if (mutationPending.value || authorityBlocked.value)
      return
    if (!permissions.archive.value) {
      error.value = 'Você não tem permissão para arquivar este registro.'
      return
    }
    const request = beginRequest()
    archiving.value = true
    clearErrors()
    try {
      await refreshCsrf()
      if (!current(request.requestSequence, request.token))
        return
      await api(`${path}/${id}`, { method: 'DELETE', signal: request.requestController.signal })
      if (!current(request.requestSequence, request.token))
        return
      if (record.value?.id === id) {
        open.value = false
        record.value = null
      }
      await refresh()
    } catch (failure) {
      if (current(request.requestSequence, request.token))
        handleFailure(failure)
    } finally {
      if (current(request.requestSequence, request.token)) {
        archiving.value = false
        if (controller === request.requestController)
          controller = null
      }
    }
  }

  async function refreshAuthority(): Promise<boolean> {
    if (!authorityBlocked.value || refreshingAuthority.value)
      return false
    const rejectedContext = context.key.value
    refreshingAuthority.value = true
    try {
      const identity = await auth.refresh()
      if (disposed || context.key.value !== rejectedContext)
        return false
      if (!identity || !(
        permissions.show.value
        || permissions.store.value
        || permissions.update.value
        || permissions.archive.value
      )) {
        error.value = 'Seu acesso ainda não foi confirmado. Atualize o contexto da empresa para continuar.'
        return false
      }
      blockedContextKey.value = null
      clearErrors()
      return true
    } catch (failure) {
      if (!disposed && context.key.value === rejectedContext)
        error.value = apiFailure(failure).message
      return false
    } finally {
      if (!disposed && context.key.value === rejectedContext)
        refreshingAuthority.value = false
    }
  }

  watch(context.key, () => {
    invalidate()
    open.value = false
    record.value = null
    blockedContextKey.value = null
    clearErrors()
  }, { flush: 'sync' })

  onScopeDispose(() => {
    disposed = true
    invalidate()
  })

  return {
    path,
    contextKey: context.key,
    permissions,
    authorityBlocked,
    mutationPending,
    canSave,
    refreshingAuthority,
    record,
    mode,
    open,
    loadingDetail,
    saving,
    archiving,
    error,
    fieldErrors,
    create,
    view,
    edit,
    save,
    archive,
    refreshAuthority,
    blockAuthority,
    cancel
  }
}
