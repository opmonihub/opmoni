import type { ErpCashLedgerTotals, ErpCashMovement, ErpCashPage, ErpCashRegister, ErpCashSession, ErpFinancePage } from '#shared/types/erpBusiness'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '~/composables/useApiError'
import { erpFinanceAllowed, type ErpFinanceAction } from '#shared/utils/erpFinance'
import { createPendingOperation, pendingOperationLookup, readPendingOperation, writePendingOperation, type ErpPendingOperation } from '#shared/utils/erpOperationRecovery'

type Establishment = { id: number, name: string, status?: string }
type CashOperation = ErpPendingOperation
type CashListKind = 'registers' | 'sessions' | 'establishments'
type SelectionSnapshot = { generation: number, selectedId: number | null }

const paths: Record<CashListKind, string> = {
  registers: '/erp/cash-registers',
  sessions: '/erp/cash-sessions',
  establishments: '/erp/establishments'
}
const indexActions: Record<CashListKind, ErpFinanceAction> = {
  registers: 'erp.cash-registers.index',
  sessions: 'erp.cash-sessions.index',
  establishments: 'erp.establishments.index'
}
const operationRoutes: Record<string, string | RegExp> = {
  'erp.cash-registers.store': '/erp/cash-registers',
  'erp.cash-sessions.store': '/erp/cash-sessions',
  'erp.cash-sessions.movements.store': /^\/erp\/cash-sessions\/\d+\/movements$/,
  'erp.cash-sessions.close': /^\/erp\/cash-sessions\/\d+\/close$/,
  'erp.payments.store': '/erp/payments'
}

export function useErpCashWorkspace(initialPage: 'registers' | 'sessions' | (() => 'registers' | 'sessions') = 'registers') {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany, refreshWithStatus } = useAuth()
  const context = useErpAdministrationContext()
  const registers = ref<ErpCashRegister[]>([])
  const registerOptions = ref<ErpCashRegister[]>([])
  const registerOptionsMeta = ref<ErpFinancePage<ErpCashRegister>['meta'] | null>(null)
  const registerOptionsLoading = ref(false)
  const registerOptionsError = ref('')
  const registerOptionsCurrent = computed(() => registerOptionsMeta.value !== null && !registerOptionsLoading.value && !registerOptionsError.value)
  const sessions = ref<ErpCashSession[]>([])
  const establishments = ref<Establishment[]>([])
  const listMeta = reactive<Record<CashListKind, ErpFinancePage<unknown>['meta'] | null>>({ registers: null, sessions: null, establishments: null })
  const loading = reactive<Record<CashListKind, boolean>>({ registers: false, sessions: false, establishments: false })
  const pageErrors = reactive<Record<CashListKind, string>>({ registers: '', sessions: '', establishments: '' })
  const selectedSession = ref<ErpCashSession | null>(null)
  const movements = ref<ErpCashMovement[]>([])
  const movementMeta = ref<ErpCashPage<ErpCashMovement>['meta'] | null>(null)
  const ledgerTotals = ref<ErpCashLedgerTotals | null>(null)
  const movementError = ref('')
  const selectedPage = ref<'registers' | 'sessions'>('registers')
  const filters = reactive({ status: '', erp_cash_register_id: '', erp_establishment_id: '', currency: '', date_from: '', date_to: '', page: 1, per_page: 15 })
  const mutationError = ref('')
  const fieldErrors = ref<Record<string, string>>({})
  const mutating = ref(false)
  const selectedOperation = ref<CashOperation | null>(null)
  const needsReconciliation = ref(false)
  const canRetryExact = ref(false)
  const authorityGeneration = ref(0)
  const permissions = computed(() => currentCompany.value?.permissions ?? [])
  const role = computed(() => currentCompany.value?.role)
  const authorityNeedsRefresh = ref(false)
  const deniedActions = ref(new Set<string>())
  const allowed = (action: ErpFinanceAction) => !authorityNeedsRefresh.value && !deniedActions.value.has(action) && erpFinanceAllowed(permissions.value, role.value, action)
  const recoveryContentAllowed = computed(() => {
    const pending = selectedOperation.value
    return pending !== null && !authorityNeedsRefresh.value && deniedActions.value.size === 0
      && erpFinanceAllowed(permissions.value, role.value, pending.operation as ErpFinanceAction)
  })
  const storageKey = computed(() => `erp-cash:v1:${user.value?.id ?? 'anonymous'}:${currentCompany.value?.id ?? 'none'}`)
  let requestGeneration = 0
  let detailSequence = 0
  let movementSequence = 0
  let registerOptionsSequence = 0
  let authorityRefreshEpoch = 0
  let sessionSelectionGeneration = 0
  const listSequences: Record<CashListKind, number> = { registers: 0, sessions: 0, establishments: 0 }

  function operationIdentity(operation: string) {
    return `${user.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${operation}`
  }
  function current(token: ReturnType<typeof context.capture>, generation: number) {
    return requestGeneration === generation && context.isCurrent(token)
  }
  function captureSelection(): SelectionSnapshot {
    return { generation: sessionSelectionGeneration, selectedId: selectedSession.value?.id ?? null }
  }
  function selectionStillCurrent(snapshot: SelectionSnapshot, targetId?: number | null) {
    if (sessionSelectionGeneration !== snapshot.generation) return false
    const selectedId = selectedSession.value?.id ?? null
    return snapshot.selectedId !== null
      ? selectedId === snapshot.selectedId
      : selectedId === null || selectedId === targetId
  }
  function operationSessionId(operation: CashOperation, result?: unknown) {
    if (operation.operation === 'erp.cash-sessions.close' || operation.operation === 'erp.cash-sessions.movements.store') {
      const match = operation.path.match(/^\/erp\/cash-sessions\/(\d+)/)
      return match ? Number(match[1]) : null
    }
    if (operation.operation === 'erp.payments.store') {
      const id = Number(operation.payload.erp_cash_session_id)
      return Number.isSafeInteger(id) && id > 0 ? id : null
    }
    if (operation.operation === 'erp.cash-sessions.store' && typeof result === 'object' && result !== null) {
      const id = Number((result as { id?: unknown }).id)
      return Number.isSafeInteger(id) && id > 0 ? id : null
    }
    return null
  }
  function storePending(operation: CashOperation | null) {
    if (typeof sessionStorage === 'undefined') {
      mutationError.value = 'O navegador não permite guardar a identificação desta operação. Ative o armazenamento da sessão antes de registrar.'
      return false
    }
    try {
      writePendingOperation(sessionStorage, storageKey.value, operation)
      selectedOperation.value = operation
      return true
    } catch {
      mutationError.value = 'Não foi possível guardar a identificação e os dados desta operação na sessão. Libere o armazenamento e tente novamente.'
      return false
    }
  }
  function readPending() {
    if (typeof sessionStorage === 'undefined') return null
    try {
      const raw = sessionStorage.getItem(storageKey.value)
      let operation = ''
      const parsed = raw ? JSON.parse(raw) as { operation?: unknown } : null
      if (typeof parsed?.operation === 'string') operation = parsed.operation
      return operation ? readPendingOperation(sessionStorage, storageKey.value, operationIdentity(operation), operationRoutes) : null
    } catch { return null }
  }
  function restorePending() {
    const pending = readPending()
    if (!pending) return
    selectedOperation.value = pending
    needsReconciliation.value = true
    mutationError.value = 'Existe uma operação anterior aguardando conferência.'
  }
  function clearPrivateState() {
    requestGeneration++
    detailSequence++
    movementSequence++
    authorityGeneration.value++
    registers.value = []
    registerOptions.value = []
    registerOptionsMeta.value = null
    registerOptionsLoading.value = false
    registerOptionsError.value = ''
    registerOptionsSequence++
    sessions.value = []
    establishments.value = []
    selectedSession.value = null
    movements.value = []
    movementMeta.value = null
    ledgerTotals.value = null
    movementError.value = ''
    for (const kind of ['registers', 'sessions', 'establishments'] as const) {
      listSequences[kind]++
      loading[kind] = false
      pageErrors[kind] = ''
      listMeta[kind] = null
    }
    mutationError.value = ''
    fieldErrors.value = {}
    mutating.value = false
    canRetryExact.value = false
    selectedOperation.value = null
    needsReconciliation.value = false
    filters.status = ''
    filters.erp_cash_register_id = ''
    filters.erp_establishment_id = ''
    filters.currency = ''
    filters.date_from = ''
    filters.date_to = ''
    filters.page = 1
  }
  async function refreshAuth(token: ReturnType<typeof context.capture>, epoch: number) {
    if (!context.isCurrent(token)) return
    try {
      const result = await refreshWithStatus()
      if (context.isCurrent(token) && authorityRefreshEpoch === epoch && result.identity && result.committed) {
        authorityNeedsRefresh.value = false
        deniedActions.value = new Set()
      }
    } catch {
      if (context.isCurrent(token) && authorityRefreshEpoch === epoch) mutationError.value = 'A autorização mudou. Atualize sua sessão para continuar.'
    }
  }
  function isAuthorityDenial(error: unknown) {
    const status = apiStatus(error)
    if (status === 401 || status === 403) return true
    if (status !== 409 || typeof error !== 'object' || error === null) return false
    const record = error as { data?: unknown, response?: { _data?: unknown, data?: unknown } }
    let data = record.data ?? record.response?._data ?? record.response?.data
    if (typeof data === 'object' && data !== null && '_data' in data)
      data = (data as { _data?: unknown })._data
    return typeof data === 'object' && data !== null && (data as { code?: unknown }).code === 'company_context_changed'
  }
  async function handleDenial(action: string, error: unknown, token: ReturnType<typeof context.capture>, refreshForbiddenWrite = false) {
    if (!context.isCurrent(token) || !isAuthorityDenial(error)) return false
    deniedActions.value = new Set([...deniedActions.value, action])
    const status = apiStatus(error)
    const epoch = ++authorityRefreshEpoch
    if (status === 401 || status === 409 || (status === 403 && refreshForbiddenWrite)) {
      authorityNeedsRefresh.value = true
    }
    clearPrivateState()
    restorePending()
    mutationError.value = apiErrorMessage(error) ?? 'Sua autorização mudou. Os dados privados do caixa foram removidos.'
    if (status === 401 || status === 409 || (status === 403 && refreshForbiddenWrite)) await refreshAuth(token, epoch)
    return true
  }
  function query(kind: CashListKind, page: number, perPage: number) {
    const params = new URLSearchParams()
    params.set('per_page', String(perPage))
    params.set('page', String(page))
    if (kind === 'sessions') {
      for (const key of ['status', 'erp_cash_register_id', 'erp_establishment_id', 'currency', 'date_from', 'date_to'] as const)
        if (filters[key] !== '') params.set(key, filters[key])
    }
    if (kind === 'establishments') params.set('status', 'active')
    return params.toString()
  }
  async function loadList(kind: CashListKind, page = 1, append = false) {
    const action = indexActions[kind]
    if (!allowed(action) || !currentCompany.value) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++listSequences[kind]
    const previous = kind === 'registers' ? registers.value : kind === 'sessions' ? sessions.value : establishments.value
    const previousMeta = listMeta[kind]
    loading[kind] = true
    pageErrors[kind] = ''
    try {
      const result = await api<ErpFinancePage<unknown>>(`${paths[kind]}?${query(kind, page, kind === 'establishments' ? 100 : 15)}`)
      if (!current(token, generation) || listSequences[kind] !== sequence) return
      if (kind === 'registers') {
        const incoming = result.data as ErpCashRegister[]
        registers.value = append ? [...registers.value, ...incoming.filter(row => !registers.value.some(existing => existing.id === row.id))] : incoming
      }
      if (kind === 'sessions') {
        const incoming = result.data as ErpCashSession[]
        sessions.value = append ? [...sessions.value, ...incoming.filter(row => !sessions.value.some(existing => existing.id === row.id))] : incoming
      }
      if (kind === 'establishments') {
        const incoming = result.data as Establishment[]
        establishments.value = append ? [...establishments.value, ...incoming.filter(row => !establishments.value.some(existing => existing.id === row.id))] : incoming
      }
      listMeta[kind] = result.meta
    } catch (error) {
      if (!current(token, generation) || listSequences[kind] !== sequence) return
      if (await handleDenial(action, error, token)) return
      if (kind === 'registers') registers.value = previous as ErpCashRegister[]
      if (kind === 'sessions') sessions.value = previous as ErpCashSession[]
      if (kind === 'establishments') establishments.value = previous as Establishment[]
      listMeta[kind] = previousMeta
      pageErrors[kind] = apiErrorMessage(error) ?? 'Não foi possível carregar esta lista. Tente novamente.'
    } finally {
      if (current(token, generation) && listSequences[kind] === sequence) loading[kind] = false
    }
  }
  async function load() {
    await Promise.all([
      ...(['registers', 'sessions', 'establishments'] as const).map(kind => loadList(kind)),
      loadRegisterOptions()
    ])
  }
  async function loadRegisterOptions(page = 1, append = false) {
    const action: ErpFinanceAction = 'erp.cash-registers.index'
    if (!allowed(action)) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++registerOptionsSequence
    registerOptionsLoading.value = true
    registerOptionsError.value = ''
    try {
      const result = await api<ErpFinancePage<ErpCashRegister>>(`/erp/cash-registers?per_page=100&page=${page}&status=active`)
      if (!current(token, generation) || registerOptionsSequence !== sequence) return
      const incoming = result.data
      registerOptions.value = append ? [...registerOptions.value, ...incoming.filter(row => !registerOptions.value.some(existing => existing.id === row.id))] : incoming
      registerOptionsMeta.value = result.meta
    } catch (error) {
      if (!current(token, generation) || registerOptionsSequence !== sequence) return
      if (!await handleDenial(action, error, token)) {
        registerOptionsError.value = apiErrorMessage(error) ?? 'Não foi possível carregar os caixas ativos.'
        mutationError.value = registerOptionsError.value
      }
    } finally {
      if (current(token, generation) && registerOptionsSequence === sequence) registerOptionsLoading.value = false
    }
  }
  async function loadMoreRegisterOptions() {
    const meta = registerOptionsMeta.value
    if (!meta || meta.current_page >= meta.last_page || registerOptionsLoading.value) return
    await loadRegisterOptions(meta.current_page + 1, true)
  }
  async function loadMovementPage(sessionId: number, page = 1, token = context.capture(), generation = requestGeneration, sequence = detailSequence) {
    const action: ErpFinanceAction = 'erp.cash-sessions.movements.index'
    if (!allowed(action)) return
    const movementRequest = ++movementSequence
    movementError.value = ''
    try {
      const result = await api<ErpCashPage<ErpCashMovement>>(`/erp/cash-sessions/${sessionId}/movements?per_page=15&page=${page}`)
      if (!current(token, generation) || sequence !== detailSequence || movementRequest !== movementSequence || selectedSession.value?.id !== sessionId) return
      movements.value = result.data
      movementMeta.value = result.meta
      ledgerTotals.value = result.totals ?? null
    } catch (error) {
      if (!current(token, generation) || sequence !== detailSequence || movementRequest !== movementSequence) return
      if (!await handleDenial(action, error, token)) {
        movementError.value = apiErrorMessage(error) ?? 'Não foi possível carregar o histórico do caixa.'
        mutationError.value = movementError.value
      }
    }
  }
  async function inspectSession(id: number, expectedSelection?: SelectionSnapshot & { selectIfEmpty?: boolean }) {
    const action: ErpFinanceAction = 'erp.cash-sessions.show'
    if (!allowed(action)) return
    const explicitSelection = expectedSelection === undefined
    if (explicitSelection) sessionSelectionGeneration++
    const selectionGeneration = expectedSelection?.generation ?? sessionSelectionGeneration
    const canApplySelection = () => sessionSelectionGeneration === selectionGeneration && (explicitSelection
      || (selectedSession.value?.id ?? null) === expectedSelection?.selectedId
      || (expectedSelection?.selectIfEmpty && expectedSelection.selectedId === null && selectedSession.value === null))
    const token = context.capture()
    const generation = requestGeneration
    const canSelectAtStart = canApplySelection() && (explicitSelection || expectedSelection?.selectedId === id || (expectedSelection?.selectIfEmpty && expectedSelection.selectedId === null))
    const sequence = canSelectAtStart ? ++detailSequence : detailSequence
    const previousSession = selectedSession.value
    const previousMovements = movements.value
    const previousMeta = movementMeta.value
    const previousTotals = ledgerTotals.value
    if (canSelectAtStart && selectedSession.value?.id !== id) {
      selectedSession.value = null
      movements.value = []
      movementMeta.value = null
      ledgerTotals.value = null
      movementError.value = ''
      movementSequence++
    }
    try {
      const result = await api<{ data: ErpCashSession }>(`/erp/cash-sessions/${id}`)
      if (!current(token, generation)) return
      if (!canSelectAtStart || !canApplySelection() || sequence !== detailSequence) return
      selectedSession.value = result.data
      ledgerTotals.value = result.data
      if (allowed('erp.cash-sessions.movements.index')) await loadMovementPage(id, 1, token, generation, sequence)
    } catch (error) {
      if (!current(token, generation)) return
      if (await handleDenial(action, error, token)) return
      if (!canSelectAtStart || !canApplySelection() || sequence !== detailSequence) return
      if (previousSession?.id === id) {
        selectedSession.value = previousSession
        movements.value = previousMovements
        movementMeta.value = previousMeta
        ledgerTotals.value = previousTotals
      }
      mutationError.value = apiErrorMessage(error) ?? 'Não foi possível carregar os detalhes da sessão.'
    }
  }
  function activeCashSession(id: unknown) {
    if (typeof id !== 'number' || !selectedSession.value || selectedSession.value.id !== id) return null
    const session = selectedSession.value
    if (session.status !== 'open' || !session.can_operate || session.needs_reconciliation || !session.currency) return null
    return session
  }
  async function post(path: string, body: Record<string, unknown>, action: ErpFinanceAction, existing?: CashOperation) {
    mutationError.value = ''
    fieldErrors.value = {}
    if (mutating.value) return null
    if (!currentCompany.value || !user.value || !allowed(action)) {
      mutationError.value = 'Seu acesso não permite esta operação.'
      return null
    }
    if (!selectedOperation.value && !existing) restorePending()
    if (selectedOperation.value && !existing) {
      needsReconciliation.value = true
      mutationError.value = 'Há uma operação sem resultado confirmado. Consulte ou repita a mesma operação antes de iniciar outra.'
      return null
    }
    canRetryExact.value = false
    const pending = existing ?? createPendingOperation(operationIdentity(action), action, path, body, crypto.randomUUID())
    if (pending.identity !== operationIdentity(action) || !storePending(pending)) return null
    const token = context.capture()
    const generation = requestGeneration
    const authorityToken = authorityGeneration.value
    const selectionToken = captureSelection()
    const selectionIsCurrent = (targetId?: number | null) => current(token, generation)
      && authorityGeneration.value === authorityToken
      && selectionStillCurrent(selectionToken, targetId)
    mutating.value = true
    needsReconciliation.value = false
    try {
      await refreshCsrf()
      if (!selectionIsCurrent()) {
        if (current(token, generation) && authorityGeneration.value === authorityToken) {
          needsReconciliation.value = true
          mutationError.value = 'A sessão selecionada mudou antes do envio. Consulte a operação antes de repetir.'
        }
        return null
      }
      const response = await api<{ data: unknown }>(pending.path, { method: 'POST', body: pending.payload, headers: { 'Idempotency-Key': pending.key } })
      if (!selectionIsCurrent()) {
        if (current(token, generation) && authorityGeneration.value === authorityToken) {
          needsReconciliation.value = true
          mutationError.value = 'A operação pode ter sido concluída na sessão anterior. Consulte o resultado antes de continuar.'
        }
        return null
      }
      const cleared = storePending(null)
      needsReconciliation.value = !cleared
      mutationError.value = cleared ? 'Operação confirmada.' : 'Operação confirmada. A cópia local ainda não pôde ser removida; consulte a operação antes de iniciar outra.'
      const sessionId = operationSessionId(pending, response.data)
      if (sessionId) {
        const selectIfEmpty = pending.operation === 'erp.cash-sessions.store'
        if (selectIfEmpty && selectionToken.selectedId === null && selectionStillCurrent(selectionToken, sessionId)) selectedPage.value = 'sessions'
        await inspectSession(sessionId, { ...selectionToken, selectIfEmpty })
        if (!selectionIsCurrent(sessionId)) return null
      }
      await Promise.all((['registers', 'sessions'] as const).map(kind => loadList(kind)))
      if (!selectionIsCurrent(sessionId)) return null
      return response.data
    } catch (error) {
      if (!current(token, generation) || authorityGeneration.value !== authorityToken) return null
      if (selectionStillCurrent(selectionToken)) fieldErrors.value = apiFieldErrors(error)
      const status = apiStatus(error)
      const denied = await handleDenial(action, error, token, true)
      if (!current(token, generation)) return null
      if (denied) return null
      if (existing && status === 404) {
        needsReconciliation.value = true
        mutationError.value = 'O recurso não está disponível para a repetição. Consulte a operação antes de qualquer nova ação.'
      } else if (status === 409) {
        needsReconciliation.value = true
        mutationError.value = apiErrorMessage(error) ?? 'O sistema exige conciliação antes de continuar.'
      } else if (status === 422 || status === 404) {
        const cleared = storePending(null)
        needsReconciliation.value = !cleared
        mutationError.value = apiErrorMessage(error) ?? 'A operação foi recusada. Revise os dados e tente novamente.'
      } else {
        needsReconciliation.value = true
        mutationError.value = 'Não foi possível confirmar o resultado. Consulte a operação antes de repetir.'
      }
      const sessionId = operationSessionId(pending)
      if (sessionId) await inspectSession(sessionId, { ...selectionToken })
      if (!selectionIsCurrent(sessionId)) return null
      return null
    } finally {
      if (context.isCurrent(token) && requestGeneration === generation) mutating.value = false
    }
  }
  async function registerCashDrawer(body: { name: string, erp_establishment_id: number }) {
    if (!establishments.value.some(item => item.id === body.erp_establishment_id && item.status !== 'inactive')) {
      mutationError.value = 'Selecione um estabelecimento ativo para este caixa.'
      return null
    }
    return post('/erp/cash-registers', body, 'erp.cash-registers.store')
  }
  async function openCashSession(body: { erp_cash_register_id: number, opening_amount: string, currency: string }) {
    if (!registerOptionsCurrent.value) {
      mutationError.value = registerOptionsError.value || 'Atualize os caixas ativos antes de abrir uma sessão.'
      return null
    }
    const register = registerOptions.value.find(item => item.id === body.erp_cash_register_id && item.status === 'active')
    if (!register || !/^[A-Z]{3}$/.test(body.currency)) {
      mutationError.value = 'O caixa selecionado não está mais ativo. Atualize a lista e selecione outro caixa.'
      return null
    }
    return post('/erp/cash-sessions', body, 'erp.cash-sessions.store')
  }
  async function postMovement(body: { direction: 'in' | 'out', kind: 'deposit' | 'withdrawal', amount: string, reason: string }) {
    const session = selectedSession.value
    if (!session || !activeCashSession(session.id) || (body.kind === 'deposit') !== (body.direction === 'in')) {
      mutationError.value = 'Selecione uma sessão aberta, reconciliada e autorizada para registrar movimentos.'
      return null
    }
    return post(`/erp/cash-sessions/${session.id}/movements`, body, 'erp.cash-sessions.movements.store')
  }
  async function closeSession(body: { declared_closing_amount: string }) {
    const session = selectedSession.value
    if (!session || !session.can_operate || session.status !== 'open') return null
    return post(`/erp/cash-sessions/${session.id}/close`, body, 'erp.cash-sessions.close')
  }
  async function payCashPayment(body: Record<string, unknown>) {
    const session = activeCashSession(body.erp_cash_session_id)
    if (!session || !allowed('erp.payments.store') || body.method !== 'cash' || body.currency !== session.currency) {
      mutationError.value = 'Selecione uma sessão aberta e autorizada na mesma moeda do pagamento.'
      return null
    }
    return post('/erp/payments', {
      direction: body.direction,
      amount: body.amount,
      method: 'cash',
      currency: session.currency,
      erp_cash_session_id: session.id,
      erp_establishment_id: session.erp_establishment_id,
      erp_financial_account_id: body.erp_financial_account_id ?? null
    }, 'erp.payments.store')
  }
  async function reconcile() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || pending.identity !== operationIdentity(pending.operation) || mutating.value) return
    selectedOperation.value = pending
    const token = context.capture()
    const generation = requestGeneration
    const authorityToken = authorityGeneration.value
    const selectionToken = captureSelection()
    const recoveryCurrent = () => current(token, generation) && authorityGeneration.value === authorityToken
    mutating.value = true
    try {
      const lookup = pendingOperationLookup(pending)
      const response = await api<{ data: { state: string, result?: unknown } }>(lookup.path, { headers: lookup.headers })
      if (!recoveryCurrent()) return
      if (response.data.state !== 'completed') {
        needsReconciliation.value = true
        canRetryExact.value = false
        mutationError.value = 'A consulta ainda não confirmou a conclusão da operação.'
        return
      }
      const cleared = storePending(null)
      needsReconciliation.value = !cleared
      mutationError.value = cleared ? 'Operação confirmada pelo registro do servidor.' : 'Operação confirmada. A cópia local ainda não pôde ser removida; consulte-a antes de iniciar outra.'
      const id = operationSessionId(pending, response.data.result)
      if (id) {
        const selectIfEmpty = pending.operation === 'erp.cash-sessions.store'
        if (selectIfEmpty && selectionToken.selectedId === null && selectionStillCurrent(selectionToken, id)) selectedPage.value = 'sessions'
        await inspectSession(id, { ...selectionToken, selectIfEmpty })
        if (!recoveryCurrent()) return
      }
      await Promise.all((['registers', 'sessions'] as const).map(kind => loadList(kind)))
      if (!recoveryCurrent()) return
    } catch (error) {
      if (!recoveryCurrent()) return
      if (apiStatus(error) === 404) {
        canRetryExact.value = true
        needsReconciliation.value = true
        mutationError.value = 'Ainda sem registro no servidor. Você pode repetir o mesmo conteúdo com a mesma identificação.'
      } else {
        const denied = await handleDenial(pending.operation, error, token)
        if (!recoveryCurrent()) return
        if (!denied) mutationError.value = apiErrorMessage(error) ?? 'Não foi possível consultar a operação.'
      }
    } finally {
      if (context.isCurrent(token) && requestGeneration === generation) mutating.value = false
    }
  }
  async function retryExact() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || !canRetryExact.value) return null
    return post(pending.path, pending.payload, pending.operation as ErpFinanceAction, pending)
  }
  async function setPage(page: 'registers' | 'sessions') {
    selectedPage.value = page
    await loadList(page)
  }
  watch(() => context.key.value, () => {
    clearPrivateState()
    deniedActions.value = new Set()
    authorityNeedsRefresh.value = false
    filters.status = ''
    filters.erp_cash_register_id = ''
    filters.erp_establishment_id = ''
    filters.currency = ''
    filters.date_from = ''
    filters.date_to = ''
    filters.page = 1
    restorePending()
    void load()
  }, { flush: 'sync' })
  onMounted(() => {
    selectedPage.value = typeof initialPage === 'function' ? initialPage() : initialPage
    restorePending()
    void load()
  })
  onScopeDispose(() => {
    requestGeneration++
    detailSequence++
    movementSequence++
    registerOptionsSequence++
    for (const kind of ['registers', 'sessions', 'establishments'] as const) listSequences[kind]++
  })

  return {
    registers, registerOptions, registerOptionsMeta, registerOptionsLoading, registerOptionsError, registerOptionsCurrent, sessions, establishments, listMeta, loading, pageErrors, selectedSession, movements, movementMeta, ledgerTotals, movementError,
    selectedPage, filters, mutationError, fieldErrors, mutating, selectedOperation, recoveryContentAllowed, needsReconciliation, canRetryExact,
    authorityGeneration, allowed, load, loadList, inspectSession, setPage, registerCashDrawer, openCashSession, postMovement,
    closeSession, payCashPayment, reconcile, retryExact, activeCashSession, loadMovementPage, loadRegisterOptions, loadMoreRegisterOptions
  }
}
