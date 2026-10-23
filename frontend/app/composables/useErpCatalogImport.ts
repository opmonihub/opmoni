import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
import type { ErpImportKind, ErpImportPreview, ErpImportRecovery } from '#shared/types/erpCatalogImport'
import { apiFailure } from '#shared/utils/access'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

export function useErpCatalogImport() {
  const { api, refreshCsrf } = useAccessApi()
  const auth = useAuth()
  const context = useErpAdministrationContext()
  const kind = ref<ErpImportKind>('products')
  const file = ref<File | null>(null)
  const unitId = ref<number | null>(null)
  const preview = ref<ErpImportPreview | null>(null)
  const operationKey = ref('')
  const recoveryToken = ref('')
  const unitValidity = ref<{ id: number | null, valid: boolean }>({ id: null, valid: false })
  const busy = ref(false)
  const unknownOutcome = ref(false)
  const hasSubmittedRecovery = ref(false)
  const canRetryExact = ref(false)
  const authorityBlocked = ref(false)
  const error = ref('')
  const fieldErrors = ref<Record<string, string[]>>({})
  const success = ref<number | null>(null)
  let sequence = 0
  let controller: AbortController | null = null
  let disposed = false
  let mounted = false
  let restoring = false
  const identity = computed(() => JSON.stringify([auth.user.value?.id ?? null, auth.currentCompany.value?.id ?? null, auth.currentCompany.value?.access_kind ?? null, auth.currentCompany.value?.delegated_office_id ?? null]))
  const storageKey = computed(() => `opmoni:erp-import:v1:${identity.value}`)
  function hasAction(action: 'preview' | 'confirm'): boolean {
    const company = auth.currentCompany.value
    if (!auth.user.value || !company || company.status !== 'active' || authorityBlocked.value)
      return false
    const permission = `erp.catalog.import.${kind.value}.${action}`
    if (company.access_kind === 'delegated') {
      const office = auth.currentAccount.value
      return office?.status === 'active' && office.id === company.delegated_office_id && ['admin', 'operador'].includes(office.role) && company.permissions.includes(permission)
    }
    return company.access_kind === 'direct' && ['owner', 'manager', 'operator'].includes(company.role) && erpCompanyHasPermission(company.permissions, permission)
  }
  const canPreview = computed(() => hasAction('preview'))
  const canConfirmOrRecover = computed(() => hasAction('confirm'))
  const canImport = computed(() => canPreview.value || canConfirmOrRecover.value)
  const unitReady = computed(() => unitId.value !== null && unitValidity.value.id === unitId.value && unitValidity.value.valid)
  const canConfirm = computed(() => {
    if (!canConfirmOrRecover.value || busy.value) return false
    if (unknownOutcome.value) return canRetryExact.value && !!recoveryToken.value && !!operationKey.value
    return preview.value?.state === 'pending' && preview.value.errors.length === 0 && preview.value.totals.records > 0 && (kind.value === 'contacts' || unitReady.value)
  })
  const lockedChoices = computed(() => busy.value || unknownOutcome.value || hasSubmittedRecovery.value)
  function invalidate() {
    sequence++
    controller?.abort()
    controller = null
    busy.value = false
  }
  function clearPrivate() {
    file.value = null
    preview.value = null
    unitId.value = null
    operationKey.value = ''
    recoveryToken.value = ''
    unitValidity.value = { id: null, valid: false }
    unknownOutcome.value = false
    canRetryExact.value = false
    fieldErrors.value = {}
  }
  function removeRecovery(key = storageKey.value) {
    try {
      globalThis.sessionStorage?.removeItem(key)
      if (key === storageKey.value)
        hasSubmittedRecovery.value = false
    } catch {
      // Server-side receipt identity remains authoritative if storage removal fails.
    }
  }
  function store(state: ErpImportRecovery['state']): boolean {
    if (!recoveryToken.value || !operationKey.value)
      return false
    const recovery: ErpImportRecovery = { version: 1, identity: identity.value, kind: kind.value, token: recoveryToken.value, key: operationKey.value, unitId: unitId.value, state }
    try {
      globalThis.sessionStorage.setItem(storageKey.value, JSON.stringify(recovery))
      hasSubmittedRecovery.value = state === 'submitted'
      return true
    } catch {
      error.value = 'Não foi possível guardar a identificação da operação nesta aba. Habilite o armazenamento e tente novamente.'
      return false
    }
  }
  function begin() {
    invalidate()
    busy.value = true
    error.value = ''
    fieldErrors.value = {}
    controller = new AbortController()
    return { sequence, token: context.capture(), signal: controller.signal }
  }
  function current(request: ReturnType<typeof begin>) {
    return !disposed && request.sequence === sequence && context.isCurrent(request.token)
  }
  function failure(failure: unknown) {
    const details = apiFailure(failure)
    if (details.status === 401 || details.status === 403 || (details.status === 409 && details.code === 'company_context_changed')) {
      invalidate()
      clearPrivate()
      authorityBlocked.value = true
    }
    error.value = details.message
    fieldErrors.value = details.errors ?? {}
    return details
  }
  function complete(created: number) {
    removeRecovery()
    clearPrivate()
    success.value = created
  }
  async function createPreview() {
    if (!canPreview.value || !file.value || lockedChoices.value)
      return
    const request = begin()
    success.value = null
    try {
      await refreshCsrf()
      if (!current(request))
        return
      const body = new FormData()
      body.append('file', file.value!)
      const result = await api<{
        data: ErpImportPreview
      }>(`/erp/catalog/import/${kind.value}/preview`, { method: 'POST', body, signal: request.signal })
      if (!current(request))
        return
      preview.value = result.data
      recoveryToken.value = result.data.token
      operationKey.value = crypto.randomUUID()
      store('prepared')
    } catch (err) {
      if (current(request))
        failure(err)
    } finally {
      if (current(request))
        busy.value = false
    }
  }
  async function confirm() {
    if (!canConfirm.value || !recoveryToken.value)
      return
    if (!store('submitted'))
      return
    const body = { token: recoveryToken.value, ...(kind.value === 'products' ? { erp_unit_id: unitId.value } : {}) }
    const key = operationKey.value
    const request = begin()
    unknownOutcome.value = true
    canRetryExact.value = false
    try {
      await refreshCsrf()
      if (!current(request))
        return
      const result = await api<{
        data: {
          created: number
        }
      }>(`/erp/catalog/import/${kind.value}/confirm`, { method: 'POST', body, headers: { 'Idempotency-Key': key }, signal: request.signal })
      if (current(request))
        complete(result.data.created)
    } catch (err) {
      if (!current(request))
        return
      const details = failure(err)
      if (details.status === 422) {
        unknownOutcome.value = false
        store('prepared')
      }
    } finally {
      if (current(request))
        busy.value = false
    }
  }
  async function reconcile() {
    if (!canConfirmOrRecover.value || busy.value || !operationKey.value)
      return
    const request = begin()
    try {
      const result = await api<{
        data: {
          state: string
          result: {
            created: number
          }
        }
      }>(`/erp/operations/lookup?operation=erp.catalog.import.${kind.value}.confirm`, { headers: { 'Idempotency-Key': operationKey.value }, signal: request.signal })
      if (current(request))
        complete(result.data.result.created)
    } catch (err) {
      if (!current(request))
        return
      const details = apiFailure(err)
      if (details.status === 404 && details.code === 'operation_not_recorded') {
        canRetryExact.value = true
        error.value = 'Resultado ainda não registrado. Você pode repetir a mesma confirmação com a identificação original.'
      } else
        failure(err)
    } finally {
      if (current(request))
        busy.value = false
    }
  }
  async function recover() {
    if (!mounted || busy.value || !auth.user.value || !auth.currentCompany.value)
      return
    let recovery: ErpImportRecovery
    try {
      const text = globalThis.sessionStorage?.getItem(storageKey.value)
      if (!text)
        return
      recovery = JSON.parse(text)
      hasSubmittedRecovery.value = recovery.state === 'submitted'
      if (recovery.version !== 1 || recovery.identity !== identity.value || !['products', 'contacts'].includes(recovery.kind) || typeof recovery.token !== 'string' || typeof recovery.key !== 'string' || !['prepared', 'submitted'].includes(recovery.state) || (recovery.unitId !== null && (!Number.isSafeInteger(recovery.unitId) || recovery.unitId < 1))) {
        removeRecovery()
        return
      }
    } catch {
      return
    }
    restoring = true
    kind.value = recovery.kind
    restoring = false
    if (!canConfirmOrRecover.value)
      return
    recoveryToken.value = recovery.token
    operationKey.value = recovery.key
    unitId.value = recovery.unitId
    unknownOutcome.value = recovery.state === 'submitted'
    const request = begin()
    try {
      await refreshCsrf()
      if (!current(request))
        return
      const result = await api<{
        data: ErpImportPreview
      }>(`/erp/catalog/import/${recovery.kind}/preview/read`, { method: 'POST', body: { token: recovery.token }, signal: request.signal })
      if (!current(request))
        return
      if (result.data.state === 'completed') {
        complete(result.data.result!.created)
        return
      }
      preview.value = result.data
      unitId.value = recovery.unitId
      operationKey.value = recovery.key
      unknownOutcome.value = recovery.state === 'submitted'
      canRetryExact.value = false
    } catch (err) {
      if (current(request))
        failure(err)
    } finally {
      if (current(request))
        busy.value = false
    }
    if (current(request) && unknownOutcome.value)
      await reconcile()
  }
  function cancel() {
    if (lockedChoices.value)
      return
    invalidate()
    removeRecovery()
    clearPrivate()
    error.value = ''
    success.value = null
  }
  async function refreshAuthority() {
    const request = begin()
    try {
      await auth.refresh()
      if (!current(request))
        return
      authorityBlocked.value = false
    } catch (err) {
      if (current(request))
        failure(err)
    } finally {
      if (current(request))
        busy.value = false
    }
    if (current(request))
      await recover()
  }
  watch([kind, file], () => {
    if (restoring || unknownOutcome.value || hasSubmittedRecovery.value)
      return
    invalidate()
    removeRecovery()
    preview.value = null
    operationKey.value = ''
    recoveryToken.value = ''
    error.value = ''
    success.value = null
  }, { flush: 'sync' })
  function setUnitValidity(state: { id: number | null, valid: boolean }) {
    if (state.id === unitId.value) unitValidity.value = state
  }
  watch(unitId, () => {
    unitValidity.value = { id: unitId.value, valid: false }
    if (preview.value && !unknownOutcome.value && !restoring)
      store('prepared')
  }, { flush: 'sync' })
  function preserveSubmitted(key: string): boolean {
    try {
      const text = globalThis.sessionStorage?.getItem(key)
      return !!text && JSON.parse(text).state === 'submitted'
    } catch {
      // An unreadable identity cannot be proven safe to discard.
      return true
    }
  }
  let previousStorageKey = storageKey.value
  watch(context.key, () => {
    if (!preserveSubmitted(previousStorageKey))
      removeRecovery(previousStorageKey)
    previousStorageKey = storageKey.value
    hasSubmittedRecovery.value = preserveSubmitted(storageKey.value)
    invalidate()
    restoring = true
    clearPrivate()
    restoring = false
    error.value = ''
    success.value = null
    authorityBlocked.value = false
    if (mounted)
      void recover()
  }, { flush: 'sync' })
  onMounted(() => {
    mounted = true
    void recover()
  })
  onScopeDispose(() => {
    disposed = true
    invalidate()
    restoring = true
    clearPrivate()
    restoring = false
  })
  return { kind, file, unitId, preview, busy, error, fieldErrors, success, unknownOutcome, hasSubmittedRecovery, canRetryExact, canImport, canPreview, canConfirmOrRecover, canConfirm, unitReady, recoveryToken, lockedChoices, authorityBlocked, createPreview, confirm, reconcile, recover, cancel, refreshAuthority, setUnitValidity }
}
