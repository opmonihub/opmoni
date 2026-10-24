import type { ErpCompanyInitializationOperationLookup, ErpCompanyInitializationOutcome, ErpCompanyInitializationPreview } from '#shared/types/erp-company-initialization'
import { createPendingOperation, pendingOperationLookup, readPendingOperation, writePendingOperation, type ErpPendingOperation } from '#shared/utils/erpOperationRecovery'
import { apiFailure } from '#shared/utils/access'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'

const operationName = 'erp.company.initialization.apply'
const applyPath = '/erp/company/initialization/apply'
const previewPath = '/erp/company/initialization/preview'

type InitializationOperation = ErpPendingOperation

export function useErpCompanyInitialization() {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany, currentAccount } = useAuth()
  const context = useErpAdministrationContext()
  const review = ref<ErpCompanyInitializationPreview | null>(null)
  const outcome = ref<ErpCompanyInitializationOutcome | null>(null)
  const error = ref('')
  const busy = ref(false)
  const needsReconciliation = ref(false)
  const canRetryExact = ref(false)
  const pendingOperation = ref<InitializationOperation | null>(null)
  const ownerKey = ref(context.key.value)
  let sequence = 0
  let ownedAttempt = 0

  const canPreview = computed(() => !!user.value?.id && context.isCompanyOwner.value
    && currentAccount.value?.status === 'active'
    && currentCompany.value?.status === 'active'
    && erpCompanyHasPermission(currentCompany.value?.permissions, 'erp.company.settings.update'))
  const canApply = computed(() => canPreview.value && !!review.value?.fingerprint && !busy.value && !needsReconciliation.value)
  const operationIdentity = () => `${user.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${operationName}`
  const storageKey = () => `erp-company-initialization:v1:${user.value?.id ?? 'anonymous'}:${currentCompany.value?.id ?? 'none'}`
  const current = (token: ReturnType<typeof context.capture>, attempt: number) => context.isCurrent(token) && sequence === attempt

  function clearPending() {
    pendingOperation.value = null
    if (typeof sessionStorage === 'undefined') return true
    try {
      writePendingOperation(sessionStorage, storageKey(), null)
      return true
    } catch {
      return false
    }
  }

  function restorePending() {
    if (pendingOperation.value) return pendingOperation.value
    try {
      const restored = readPendingOperation(sessionStorage, storageKey(), operationIdentity(), { [operationName]: applyPath })
      if (restored?.operation === operationName) pendingOperation.value = restored
    } catch {
      // Storage can be unavailable in private browsing; the current request can still proceed.
    }
    return pendingOperation.value
  }

  function storePending(operation: InitializationOperation | null) {
    pendingOperation.value = operation
    if (typeof sessionStorage === 'undefined') return true
    try {
      writePendingOperation(sessionStorage, storageKey(), operation)
      return true
    } catch {
      return false
    }
  }

  function clearContext() {
    sequence++
    review.value = null
    outcome.value = null
    error.value = ''
    busy.value = false
    needsReconciliation.value = false
    canRetryExact.value = false
    pendingOperation.value = null
  }

  watch(context.key, (key) => {
    if (ownerKey.value === key) return
    ownerKey.value = key
    clearContext()
  }, { flush: 'sync' })

  onScopeDispose(() => {
    if (busy.value && sequence === ownedAttempt) {
      sequence++
      busy.value = false
    }
  })

  async function preview() {
    error.value = ''
    if (busy.value) return null
    if (!canPreview.value) {
      error.value = 'Somente a pessoa proprietária da empresa, com acesso de configuração, pode revisar esta preparação.'
      return null
    }
    if (restorePending()) {
      needsReconciliation.value = true
      error.value = 'Há uma operação anterior sem resultado confirmado. Consulte-a antes de gerar outra prévia.'
      return null
    }
    const token = context.capture()
    const attempt = ++sequence
    ownedAttempt = attempt
    busy.value = true
    try {
      const response = await api<{ data: ErpCompanyInitializationPreview }>(previewPath, { method: 'POST' })
      if (!current(token, attempt)) return null
      review.value = response.data
      outcome.value = null
      canRetryExact.value = false
      needsReconciliation.value = false
      return response.data
    } catch (failure) {
      if (!current(token, attempt)) return null
      const details = apiFailure(failure)
      error.value = details.code === 'company_context_changed'
        ? 'A empresa selecionada mudou. Atualize a página antes de continuar.'
        : details.message
      if ([401, 403].includes(details.status) || (details.status === 409 && details.code === 'company_context_changed')) clearContext()
      return null
    } finally {
      if (current(token, attempt)) busy.value = false
    }
  }

  async function apply(existing?: InitializationOperation) {
    error.value = ''
    canRetryExact.value = false
    if (busy.value) return null
    if (!canPreview.value || (!existing && !canApply.value)) {
      error.value = 'A prévia atual não pode ser aplicada. Gere uma nova prévia e confira os itens antes de continuar.'
      return null
    }
    const proof = review.value
    if (!proof && !existing) return null
    const payload = existing?.payload ?? { preview_version: proof!.preview_version, fingerprint: proof!.fingerprint }
    const operation = existing ?? createPendingOperation(operationIdentity(), operationName, applyPath, payload, crypto.randomUUID())
    if (operation.identity !== operationIdentity() || operation.operation !== operationName || operation.path !== applyPath) return null
    if (!storePending(operation)) {
      error.value = 'Não foi possível guardar a identificação segura desta operação. Tente novamente antes de aplicar.'
      return null
    }
    const token = context.capture()
    const attempt = ++sequence
    ownedAttempt = attempt
    busy.value = true
    needsReconciliation.value = true
    try {
      await refreshCsrf()
      if (!current(token, attempt) || !canPreview.value) return null
      const response = await api<{ data: ErpCompanyInitializationOutcome }>(operation.path, { method: 'POST', body: operation.payload, headers: { 'Idempotency-Key': operation.key } })
      if (!current(token, attempt) || !canPreview.value) return null
      outcome.value = response.data
      review.value = null
      const cleared = clearPending()
      needsReconciliation.value = !cleared
      if (!response.data.completed) error.value = 'A preparação terminou com itens pendentes. Confira os conflitos e gere uma nova prévia antes de aplicar novamente.'
      else if (!cleared) error.value = 'A preparação foi confirmada, mas não foi possível remover a identificação local. Consulte a operação antes de iniciar outra.'
      return response.data
    } catch (failure) {
      if (!current(token, attempt)) return null
      const details = apiFailure(failure)
      if (details.status === 409 && details.code === 'initialization_preview_stale') {
        review.value = null
        outcome.value = null
        clearPending()
        needsReconciliation.value = false
        error.value = details.message || 'A configuração mudou. Gere uma nova prévia antes de aplicar.'
      } else {
        needsReconciliation.value = true
        error.value = 'Não foi possível confirmar o resultado. Consulte a operação antes de repetir.'
      }
      if ([401, 403].includes(details.status) || (details.status === 409 && details.code === 'company_context_changed')) clearContext()
      return null
    } finally {
      if (current(token, attempt)) busy.value = false
    }
  }

  async function reconcile() {
    const pending = pendingOperation.value ?? restorePending()
    if (!pending || pending.identity !== operationIdentity() || busy.value || !canPreview.value) return null
    const token = context.capture()
    const attempt = ++sequence
    ownedAttempt = attempt
    busy.value = true
    try {
      const lookup = pendingOperationLookup(pending)
      const response = await api<{ data: ErpCompanyInitializationOperationLookup }>(lookup.path, { headers: lookup.headers })
      if (!current(token, attempt) || !canPreview.value) return null
      if (response.data.state !== 'completed' || !response.data.result) {
        needsReconciliation.value = true
        error.value = 'A consulta ainda não confirmou o resultado. Mantenha a operação em conferência e consulte novamente.'
        return null
      }
      outcome.value = response.data.result
      review.value = null
      const cleared = clearPending()
      needsReconciliation.value = !cleared
      if (!response.data.result.completed) error.value = 'A operação foi registrada com itens pendentes. Confira os conflitos e gere uma nova prévia.'
      else if (!cleared) error.value = 'A operação foi confirmada, mas a identificação local não pôde ser removida.'
      return response.data.result
    } catch (failure) {
      if (!current(token, attempt)) return null
      const details = apiFailure(failure)
      if (details.status === 404 && details.code === 'operation_not_recorded') {
        canRetryExact.value = true
        needsReconciliation.value = true
        error.value = 'Ainda não há registro no servidor. Você pode repetir exatamente a mesma operação.'
      } else {
        needsReconciliation.value = true
        error.value = details.message
      }
      if ([401, 403].includes(details.status) || (details.status === 409 && details.code === 'company_context_changed')) clearContext()
      return null
    } finally {
      if (current(token, attempt)) busy.value = false
    }
  }

  async function retryExact() {
    const pending = pendingOperation.value ?? restorePending()
    if (!canRetryExact.value || !pending || !canPreview.value) return null
    canRetryExact.value = false
    return apply(pending)
  }

  return { review, outcome, error, busy, canPreview, canApply, canRetryExact, needsReconciliation, preview, apply, reconcile, retryExact }
}
