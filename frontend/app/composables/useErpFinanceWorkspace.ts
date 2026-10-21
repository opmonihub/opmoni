import type { ErpFinancePage, ErpFinancialAccount, ErpObligation, ErpPayment, ErpPaymentAllocation, ErpFinanceReceipt } from '#shared/types/erpBusiness'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '~/composables/useApiError'
import { erpFinanceAllowed, type ErpFinanceAction } from '#shared/utils/erpFinance'

type Tab = 'accounts' | 'obligations' | 'payments'
type Row = ErpFinancialAccount | ErpObligation | ErpPayment
type FinanceMeta = ErpFinancePage<Row>['meta']
type ReferenceMeta = ErpFinancePage<unknown>['meta'] | null
type FinanceFilter = { page: number, per_page: number, erp_establishment_id: string, erp_financial_account_id: string, direction: string, status: string, currency: string, date_from: string, date_to: string }
type PendingOperation = { identity: string, operation: string, path: string, payload: Record<string, unknown>, key: string }
type ReferenceKind = 'establishments' | 'accounts' | 'cashSessions'
type ReferenceRow<K extends ReferenceKind> = K extends 'establishments'
  ? { id: number, name: string }
  : K extends 'accounts'
    ? ErpFinancialAccount
    : { id: number, currency: string, erp_establishment_id: number | null, status: string }
type ResourceKind = 'accounts' | 'obligations' | 'payments'
type FinanceResource = { kind: ResourceKind, id: number }

const paths: Record<Tab, string> = { accounts: '/erp/financial-accounts', obligations: '/erp/obligations', payments: '/erp/payments' }
const indexActions: Record<Tab, ErpFinanceAction> = { accounts: 'erp.financial-accounts.index', obligations: 'erp.obligations.index', payments: 'erp.payments.index' }
const showActions: Record<ResourceKind, ErpFinanceAction> = { accounts: 'erp.financial-accounts.show', obligations: 'erp.obligations.show', payments: 'erp.payments.show' }
const operationNames: Record<string, string> = {
  '/erp/financial-accounts': 'erp.financial-accounts.store',
  '/erp/obligations': 'erp.obligations.store',
  '/erp/payments': 'erp.payments.store'
}
const referenceConfig: Record<ReferenceKind, { path: string, action: ErpFinanceAction, suffix: string }> = {
  establishments: { path: '/erp/establishments', action: 'erp.establishments.index', suffix: '' },
  accounts: { path: '/erp/financial-accounts', action: 'erp.financial-accounts.index', suffix: '&status=active' },
  cashSessions: { path: '/erp/cash-sessions', action: 'erp.cash-sessions.index', suffix: '&status=open' }
}

export function useErpFinanceWorkspace() {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany, refresh: refreshIdentity } = useAuth()
  const context = useErpAdministrationContext()
  const tab = ref<Tab>('obligations')
  const rows = ref<Row[]>([])
  const meta = ref<FinanceMeta | null>(null)
  const filters = reactive<FinanceFilter>({ page: 1, per_page: 15, erp_establishment_id: '', erp_financial_account_id: '', direction: '', status: '', currency: '', date_from: '', date_to: '' })
  const establishments = ref<ReferenceRow<'establishments'>[]>([])
  const accountOptions = ref<ReferenceRow<'accounts'>[]>([])
  const cashSessions = ref<ReferenceRow<'cashSessions'>[]>([])
  const referenceMeta = reactive<Record<ReferenceKind, ReferenceMeta>>({ establishments: null, accounts: null, cashSessions: null })
  const referenceLoading = reactive<Record<ReferenceKind, boolean>>({ establishments: false, accounts: false, cashSessions: false })
  const referenceErrors = reactive<Record<ReferenceKind, string>>({ establishments: '', accounts: '', cashSessions: '' })
  const referenceError = computed(() => Object.values(referenceErrors).filter(Boolean).join(' '))
  const allocations = ref<ErpPaymentAllocation[]>([])
  const allocationMeta = ref<ErpFinancePage<ErpPaymentAllocation>['meta'] | null>(null)
  const candidates = ref<ErpObligation[]>([])
  const candidateMeta = ref<ErpFinancePage<ErpObligation>['meta'] | null>(null)
  const receipt = ref<ErpFinanceReceipt | null>(null)
  const selected = ref<Row | null>(null)
  const operationOutcome = ref<unknown>(null)
  const authorityGeneration = ref(0)
  const pageError = ref('')
  const mutationError = ref('')
  const fieldErrors = ref<Record<string, string>>({})
  const loading = ref(false)
  const mutating = ref(false)
  const selectedOperation = ref<PendingOperation | null>(null)
  const canRetryExact = ref(false)
  const needsReconciliation = ref(false)
  const deniedActions = ref(new Set<string>())
  const permissions = computed(() => currentCompany.value?.permissions ?? [])
  const role = computed(() => currentCompany.value?.role)
  const allowed = (action: ErpFinanceAction) => !deniedActions.value.has(action) && erpFinanceAllowed(permissions.value, role.value, action)
  const canRead = computed(() => allowed(indexActions[tab.value]))
  const canWrite = computed(() => allowed('erp.financial-accounts.store') || allowed('erp.obligations.store') || allowed('erp.payments.store'))
  let requestGeneration = 0
  let detailSequence = 0
  let allocationSequence = 0
  let candidateSequence = 0
  let receiptSequence = 0
  const referenceSequences: Record<ReferenceKind, number> = { establishments: 0, accounts: 0, cashSessions: 0 }
  let candidatePaymentId: number | null = null

  const storageKey = computed(() => `erp-finance:v1:${user.value?.id ?? 'anonymous'}:${currentCompany.value?.id ?? 'none'}`)
  function storePending(operation: PendingOperation | null) {
    if (typeof sessionStorage === 'undefined') {
      mutationError.value = 'O navegador não permite guardar a identificação desta operação. Ative o armazenamento da sessão antes de registrar.'
      return false
    }
    try {
      if (operation) sessionStorage.setItem(storageKey.value, JSON.stringify(operation))
      else sessionStorage.removeItem(storageKey.value)
      selectedOperation.value = operation
      return true
    } catch {
      mutationError.value = 'Não foi possível guardar a identificação e os dados desta operação na sessão. Libere o armazenamento e tente novamente.'
      return false
    }
  }
  function operationIdentity(operation: string) {
    return `${user.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${operation}`
  }
  function readPending(): PendingOperation | null {
    if (typeof sessionStorage === 'undefined') return null
    try {
      const value = JSON.parse(sessionStorage.getItem(storageKey.value) ?? 'null') as PendingOperation | null
      if (!value || typeof value.operation !== 'string' || value.identity !== operationIdentity(value.operation) || typeof value.path !== 'string' || typeof value.key !== 'string' || !/^[0-9a-f-]{36}$/i.test(value.key) || !value.payload || typeof value.payload !== 'object') return null
      const expectedPath: string | RegExp | undefined = {
        'erp.financial-accounts.store': '/erp/financial-accounts',
        'erp.obligations.store': '/erp/obligations',
        'erp.payments.store': '/erp/payments',
        'erp.payments.allocate': /^\/erp\/payments\/\d+\/allocate$/,
        'erp.payments.reverse': /^\/erp\/payments\/\d+\/reverse$/,
        'erp.payment-allocations.reverse': /^\/erp\/payment-allocations\/\d+\/reverse$/
      }[value.operation]
      const validRoute = typeof expectedPath === 'string' ? value.path === expectedPath : expectedPath?.test(value.path)
      if (!validRoute) return null
      return value
    } catch { return null }
  }
  function isAuthorityCurrent(token: ReturnType<typeof context.capture>) {
    return context.isCurrent(token)
  }
  function isCurrent(token: ReturnType<typeof context.capture>, generation: number) {
    return requestGeneration === generation && isAuthorityCurrent(token)
  }
  function clearDetailRequests() {
    detailSequence++
    allocationSequence++
    candidateSequence++
    receiptSequence++
    candidatePaymentId = null
  }
  function clearFinanceViews() {
    requestGeneration++
    clearDetailRequests()
    authorityGeneration.value++
    rows.value = []
    meta.value = null
    allocations.value = []
    allocationMeta.value = null
    candidates.value = []
    candidateMeta.value = null
    selected.value = null
    receipt.value = null
    operationOutcome.value = null
    pageError.value = ''
    mutationError.value = ''
    fieldErrors.value = {}
    loading.value = false
    mutating.value = false
    canRetryExact.value = false
  }
  function clearPrivateState() {
    clearFinanceViews()
    establishments.value = []
    accountOptions.value = []
    cashSessions.value = []
    referenceMeta.establishments = null
    referenceMeta.accounts = null
    referenceMeta.cashSessions = null
    referenceErrors.establishments = ''
    referenceErrors.accounts = ''
    referenceErrors.cashSessions = ''
    referenceSequences.establishments++
    referenceSequences.accounts++
    referenceSequences.cashSessions++
    referenceLoading.establishments = false
    referenceLoading.accounts = false
    referenceLoading.cashSessions = false
    deniedActions.value = new Set()
    needsReconciliation.value = false
    selectedOperation.value = null
    filters.page = 1
    filters.erp_establishment_id = ''
    filters.erp_financial_account_id = ''
    filters.direction = ''
    filters.status = ''
    filters.currency = ''
    filters.date_from = ''
    filters.date_to = ''
  }
  function restorePending() {
    const pending = readPending()
    if (!pending || pending.identity !== operationIdentity(pending.operation)) return
    selectedOperation.value = pending
    needsReconciliation.value = true
    mutationError.value = 'Existe uma operação anterior aguardando conferência.'
  }
  watch(() => context.key.value, () => {
    clearPrivateState()
    restorePending()
    load()
    loadReferences()
  }, { flush: 'sync' })

  async function refreshAuthForDenial(token: ReturnType<typeof context.capture>) {
    if (!isAuthorityCurrent(token)) return
    try {
      await refreshIdentity()
    } catch {
      if (isAuthorityCurrent(token)) mutationError.value = 'A autorização mudou. Atualize sua sessão para continuar.'
    }
  }
  function isDefinitiveReadStatus(error: unknown) {
    return [401, 403, 409].includes(apiStatus(error) ?? 0)
  }
  async function handleReadDenial(action: ErpFinanceAction, error: unknown, token: ReturnType<typeof context.capture>) {
    if (!isAuthorityCurrent(token) || !isDefinitiveReadStatus(error)) return false
    deniedActions.value = new Set([...deniedActions.value, action])
    const status = apiStatus(error)
    if (status === 401 || status === 409) {
      clearPrivateState()
      restorePending()
    } else if (action.endsWith('.index') && action.startsWith('erp.financial-accounts')) {
      requestGeneration++
      loading.value = false
      referenceSequences.accounts++
      referenceLoading.accounts = false
      accountOptions.value = []
      referenceMeta.accounts = null
      rows.value = tab.value === 'accounts' ? [] : rows.value
      meta.value = tab.value === 'accounts' ? null : meta.value
      if (tab.value === 'accounts') detailSequence++
      selected.value = null
    } else if (action === 'erp.establishments.index') {
      referenceSequences.establishments++
      referenceLoading.establishments = false
      establishments.value = []
      referenceMeta.establishments = null
    } else if (action === 'erp.cash-sessions.index') {
      referenceSequences.cashSessions++
      referenceLoading.cashSessions = false
      cashSessions.value = []
      referenceMeta.cashSessions = null
    } else if (action === 'erp.payments.allocations.index') {
      allocationSequence++
      allocations.value = []
      allocationMeta.value = null
    } else if (action === 'erp.payments.receipt') {
      receiptSequence++
      receipt.value = null
    } else if (action === 'erp.payments.allocate') {
      candidateSequence++
      candidatePaymentId = null
      candidates.value = []
      candidateMeta.value = null
    } else {
      clearFinanceViews()
    }
    mutationError.value = apiErrorMessage(error) ?? 'Sua autorização mudou. Os dados privados desta área foram removidos.'
    await refreshAuthForDenial(token)
    return true
  }
  function errorMessage(error: unknown, fallback: string) {
    return apiErrorMessage(error) ?? fallback
  }
  function queryString(extra: Record<string, string | number> = {}) {
    const query = new URLSearchParams()
    for (const [key, value] of Object.entries({ ...filters, ...extra }))
      if (value !== '' && value !== null && value !== undefined) query.set(key, String(value))
    return query.toString()
  }
  async function load() {
    if (!canRead.value || !currentCompany.value) return
    const token = context.capture()
    const generation = ++requestGeneration
    const currentTab = tab.value
    const previousRows = rows.value
    const previousMeta = meta.value
    loading.value = true
    pageError.value = ''
    try {
      const result = await api<ErpFinancePage<Row>>(`${paths[currentTab]}?${queryString()}`)
      if (!isCurrent(token, generation)) return
      rows.value = result.data
      meta.value = result.meta
    } catch (error) {
      if (!isCurrent(token, generation)) return
      const handled = await handleReadDenial(indexActions[currentTab], error, token)
      if (!handled) {
        rows.value = previousRows
        meta.value = previousMeta
        pageError.value = errorMessage(error, 'Não foi possível carregar os lançamentos. Tente novamente.')
      } else {
        rows.value = []
        meta.value = null
        pageError.value = 'A autorização para consultar esta área foi recusada. Atualize a sessão antes de continuar.'
      }
    } finally {
      if (isCurrent(token, generation)) loading.value = false
    }
  }

  function appendUnique<T extends { id: number }>(existing: T[], incoming: T[]) {
    const known = new Set(existing.map(row => row.id))
    return [...existing, ...incoming.filter(row => !known.has(row.id))]
  }
  async function loadReferencePage<K extends ReferenceKind>(kind: K, page: number, append: boolean) {
    const config = referenceConfig[kind]
    if (!allowed(config.action)) return
    const token = context.capture()
    const sequence = ++referenceSequences[kind]
    referenceLoading[kind] = true
    referenceErrors[kind] = ''
    try {
      const result = await api<ErpFinancePage<ReferenceRow<K>>>(`${config.path}?per_page=100&page=${page}${config.suffix}`)
      if (!isAuthorityCurrent(token) || referenceSequences[kind] !== sequence) return
      if (kind === 'establishments') establishments.value = append ? appendUnique(establishments.value, result.data as ReferenceRow<'establishments'>[]) : result.data as ReferenceRow<'establishments'>[]
      if (kind === 'accounts') accountOptions.value = append ? appendUnique(accountOptions.value, result.data as ReferenceRow<'accounts'>[]) : result.data as ReferenceRow<'accounts'>[]
      if (kind === 'cashSessions') cashSessions.value = append ? appendUnique(cashSessions.value, result.data as ReferenceRow<'cashSessions'>[]) : result.data as ReferenceRow<'cashSessions'>[]
      referenceMeta[kind] = result.meta
    } catch (error) {
      if (!isAuthorityCurrent(token) || referenceSequences[kind] !== sequence) return
      if (await handleReadDenial(config.action, error, token)) {
        referenceErrors[kind] = 'Esta lista de referências não está autorizada.'
      } else {
        referenceErrors[kind] = errorMessage(error, 'Não foi possível carregar esta lista de referências.')
      }
    } finally {
      if (isAuthorityCurrent(token) && referenceSequences[kind] === sequence) referenceLoading[kind] = false
    }
  }
  async function loadReferences() {
    await Promise.all([
      loadReferencePage('establishments', 1, false),
      loadReferencePage('accounts', 1, false),
      loadReferencePage('cashSessions', 1, false)
    ])
  }
  async function loadMoreReferences(kind: ReferenceKind) {
    const current = referenceMeta[kind]
    if (!current || current.current_page >= current.last_page || referenceLoading[kind]) return
    await loadReferencePage(kind, current.current_page + 1, true)
  }

  function invalidateDetailSelection() {
    detailSequence++
    allocationSequence++
    selected.value = null
    allocations.value = []
    allocationMeta.value = null
  }
  async function selectTab(next: Tab) {
    tab.value = next
    filters.page = 1
    filters.status = ''
    filters.direction = ''
    filters.erp_financial_account_id = ''
    rows.value = []
    meta.value = null
    invalidateDetailSelection()
    candidates.value = []
    candidateMeta.value = null
    await load()
  }
  async function inspect(row: Row) {
    const kind: ResourceKind = 'effective_status' in row ? 'payments' : 'open_amount' in row ? 'obligations' : 'accounts'
    if (!allowed(showActions[kind])) return
    const token = context.capture()
    const sequence = ++detailSequence
    selected.value = null
    allocations.value = []
    allocationMeta.value = null
    try {
      const result = await api<{ data: Row }>(`${paths[kind]}/${row.id}`)
      if (isAuthorityCurrent(token) && sequence === detailSequence) selected.value = result.data
    } catch (error) {
      if (!isAuthorityCurrent(token) || sequence !== detailSequence) return
      if (!await handleReadDenial(showActions[kind], error, token)) mutationError.value = errorMessage(error, 'Não foi possível abrir os detalhes.')
    }
  }
  async function loadAllocations(paymentId: number, page = 1, token = context.capture(), expectedDetailSequence = detailSequence) {
    if (!allowed('erp.payments.allocations.index')) return
    const sequence = ++allocationSequence
    try {
      const result = await api<ErpFinancePage<ErpPaymentAllocation>>(`/erp/payments/${paymentId}/allocations?per_page=15&page=${page}`)
      if (!isAuthorityCurrent(token) || sequence !== allocationSequence || expectedDetailSequence !== detailSequence) return
      allocations.value = result.data
      allocationMeta.value = result.meta
    } catch (error) {
      if (!isAuthorityCurrent(token) || sequence !== allocationSequence || expectedDetailSequence !== detailSequence) return
      if (!await handleReadDenial('erp.payments.allocations.index', error, token)) mutationError.value = errorMessage(error, 'Não foi possível carregar as alocações.')
    }
  }
  async function inspectPayment(payment: ErpPayment) {
    if (!allowed('erp.payments.show')) return
    const token = context.capture()
    const sequence = ++detailSequence
    const allocationSequenceAtStart = allocationSequence
    selected.value = null
    allocations.value = []
    allocationMeta.value = null
    try {
      const result = await api<{ data: ErpPayment }>(`/erp/payments/${payment.id}`)
      if (!isAuthorityCurrent(token) || sequence !== detailSequence) return
      selected.value = result.data
      if (allowed('erp.payments.allocations.index') && allocationSequence === allocationSequenceAtStart)
        await loadAllocations(payment.id, 1, token, sequence)
    } catch (error) {
      if (!isAuthorityCurrent(token) || sequence !== detailSequence) return
      if (!await handleReadDenial('erp.payments.show', error, token)) mutationError.value = errorMessage(error, 'Não foi possível abrir os detalhes do pagamento.')
    }
  }
  async function inspectObligation(id: number) {
    if (!allowed('erp.obligations.show')) return
    const token = context.capture()
    const sequence = ++detailSequence
    selected.value = null
    try {
      const result = await api<{ data: ErpObligation }>(`/erp/obligations/${id}`)
      if (isAuthorityCurrent(token) && sequence === detailSequence) selected.value = result.data
    } catch (error) {
      if (!isAuthorityCurrent(token) || sequence !== detailSequence) return
      if (!await handleReadDenial('erp.obligations.show', error, token)) mutationError.value = errorMessage(error, 'Não foi possível abrir os detalhes da obrigação.')
    }
  }
  function closeSelected() {
    invalidateDetailSelection()
    candidates.value = []
    candidateMeta.value = null
    candidateSequence++
  }
  function closeCandidates() {
    candidateSequence++
    candidatePaymentId = null
    candidates.value = []
    candidateMeta.value = null
  }
  async function loadCandidates(payment: ErpPayment, page = 1) {
    if (!allowed('erp.payments.allocate')) return
    const token = context.capture()
    const sequence = ++candidateSequence
    candidatePaymentId = payment.id
    try {
      const result = await api<ErpFinancePage<ErpObligation>>(`/erp/payments/${payment.id}/allocation-candidates?per_page=15&page=${page}`)
      if (!isAuthorityCurrent(token) || sequence !== candidateSequence || candidatePaymentId !== payment.id) return
      candidates.value = result.data
      candidateMeta.value = result.meta
    } catch (error) {
      if (!isAuthorityCurrent(token) || sequence !== candidateSequence || candidatePaymentId !== payment.id) return
      if (!await handleReadDenial('erp.payments.allocate', error, token)) mutationError.value = errorMessage(error, 'Não foi possível localizar obrigações compatíveis.')
    }
  }
  async function loadReceipt(payment: ErpPayment) {
    if (!allowed('erp.payments.receipt')) return
    const token = context.capture()
    const sequence = ++receiptSequence
    receipt.value = null
    try {
      const result = await api<{ data: ErpFinanceReceipt }>(`/erp/payments/${payment.id}/receipt`)
      if (isAuthorityCurrent(token) && sequence === receiptSequence) receipt.value = result.data
    } catch (error) {
      if (!isAuthorityCurrent(token) || sequence !== receiptSequence) return
      if (!await handleReadDenial('erp.payments.receipt', error, token)) mutationError.value = errorMessage(error, 'Recibo privado indisponível.')
    }
  }
  function closeReceipt() {
    receiptSequence++
    receipt.value = null
  }

  function operationResource(pending: PendingOperation, outcome: unknown): FinanceResource | null {
    const record = typeof outcome === 'object' && outcome !== null ? outcome as Record<string, unknown> : {}
    const id = typeof record.id === 'number' ? record.id : null
    if (pending.path === '/erp/financial-accounts' && id) return { kind: 'accounts', id }
    if (pending.path === '/erp/obligations' && id) return { kind: 'obligations', id }
    if (pending.path === '/erp/payments' && id) return { kind: 'payments', id }
    const paymentPath = pending.path.match(/^\/erp\/payments\/(\d+)\/(?:allocate|reverse)$/)
    if (paymentPath) return { kind: 'payments', id: Number(paymentPath[1]) }
    if (/^\/erp\/payment-allocations\/\d+\/reverse$/.test(pending.path)) {
      const paymentId = typeof record.erp_payment_id === 'number'
        ? record.erp_payment_id
        : selected.value && 'effective_status' in selected.value ? selected.value.id : null
      if (paymentId) return { kind: 'payments', id: paymentId }
    }
    return null
  }
  async function refreshCurrentResource(resource: FinanceResource | null, token: ReturnType<typeof context.capture>, expectedSequence: number) {
    if (!resource || !allowed(showActions[resource.kind]) || !isAuthorityCurrent(token) || detailSequence !== expectedSequence) return
    try {
      const result = await api<{ data: Row }>(`${paths[resource.kind]}/${resource.id}`)
      if (!isAuthorityCurrent(token) || detailSequence !== expectedSequence) return
      selected.value = result.data
      if (resource.kind === 'payments' && allowed('erp.payments.allocations.index'))
        await loadAllocations(resource.id, 1, token, expectedSequence)
    } catch (error) {
      if (!isAuthorityCurrent(token) || detailSequence !== expectedSequence) return
      if (!await handleReadDenial(showActions[resource.kind], error, token)) mutationError.value = errorMessage(error, 'A operação foi confirmada, mas os dados atuais não puderam ser recarregados.')
    }
  }
  async function refreshAfterOperation(pending: PendingOperation, outcome: unknown, token: ReturnType<typeof context.capture>, expectedSequence: number) {
    operationOutcome.value = outcome
    await refreshCurrentResource(operationResource(pending, outcome), token, expectedSequence)
  }
  async function post(path: string, body: Record<string, unknown>, operation?: string, existing?: PendingOperation) {
    mutationError.value = ''
    fieldErrors.value = {}
    canRetryExact.value = false
    needsReconciliation.value = false
    if (mutating.value) return null
    const action = operation ?? operationNames[path]
    if (!action || !currentCompany.value || !user.value) return null
    const writeAction = action as ErpFinanceAction
    if (!allowed(writeAction)) {
      mutationError.value = 'Seu acesso não permite esta operação.'
      return null
    }
    if (!selectedOperation.value && !existing) restorePending()
    if (selectedOperation.value && !existing) {
      mutationError.value = 'Há uma operação sem resultado confirmado. Consulte ou repita a mesma operação antes de iniciar outra.'
      needsReconciliation.value = true
      return null
    }
    if (!existing) operationOutcome.value = null
    const frozen: PendingOperation = existing ?? { identity: operationIdentity(action), operation: action, path, payload: structuredClone(body), key: crypto.randomUUID() }
    if (frozen.identity !== operationIdentity(action)) return null
    if (!storePending(frozen)) return null
    const token = context.capture()
    const generation = requestGeneration
    const selectionAtStart = detailSequence
    mutating.value = true
    try {
      await refreshCsrf()
      if (!isCurrent(token, generation)) return null
      const result = await api<{ data: unknown }>(frozen.path, { method: 'POST', body: frozen.payload, headers: { 'Idempotency-Key': frozen.key } })
      if (!isCurrent(token, generation)) return null
      const cleared = storePending(null)
      mutationError.value = cleared ? 'Lançamento confirmado.' : 'Lançamento confirmado. A cópia local ainda não pôde ser removida; consulte a operação antes de iniciar outra.'
      needsReconciliation.value = !cleared
      await refreshAfterOperation(frozen, result.data, token, selectionAtStart)
      await load()
      await loadReferences()
      return result.data
    } catch (error) {
      if (!isCurrent(token, generation)) return null
      fieldErrors.value = apiFieldErrors(error)
      const status = apiStatus(error)
      if (existing && (status === 403 || status === 404)) {
        needsReconciliation.value = true
        mutationError.value = 'A repetição foi recusada pela autorização ou pelo recurso atual. A operação original continua sem resultado confirmado; consulte-a antes de qualquer nova ação.'
      } else if (status === 422 || status === 403 || status === 404) {
        const cleared = storePending(null)
        needsReconciliation.value = !cleared
        mutationError.value = errorMessage(error, 'A operação foi recusada. Revise os dados e tente novamente.')
        if (!cleared) mutationError.value += ' A identificação local permanece guardada até o navegador permitir sua remoção.'
      } else {
        mutationError.value = 'Não foi possível confirmar o resultado. Consulte a operação antes de repetir.'
        needsReconciliation.value = true
      }
      return null
    } finally {
      if (isAuthorityCurrent(token)) mutating.value = false
    }
  }
  async function reconcile() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || pending.identity !== operationIdentity(pending.operation) || mutating.value) return
    selectedOperation.value = pending
    operationOutcome.value = null
    const token = context.capture()
    const generation = requestGeneration
    const selectionAtStart = detailSequence
    mutating.value = true
    try {
      const result = await api<{ data: { state: string, result?: unknown, resource?: unknown } }>(`/erp/operations/lookup?operation=${encodeURIComponent(pending.operation)}`, { headers: { 'Idempotency-Key': pending.key } })
      if (!isCurrent(token, generation)) return
      if (result.data.state !== 'completed') {
        canRetryExact.value = false
        needsReconciliation.value = true
        mutationError.value = result.data.state === 'legacy_observed'
          ? 'A operação foi observada em um registro anterior, mas o sistema não confirmou o resultado financeiro original. Mantenha-a em conferência e procure suporte antes de agir novamente.'
          : 'A consulta não confirmou que a operação foi concluída. Mantenha a identificação original e tente reconciliar novamente.'
        return
      }
      const outcome = result.data.result ?? null
      operationOutcome.value = outcome
      const cleared = storePending(null)
      needsReconciliation.value = !cleared
      mutationError.value = 'Operação confirmada pelo registro do servidor.'
      if (!cleared) mutationError.value += ' A cópia local ainda não pôde ser removida.'
      await refreshCurrentResource(operationResource(pending, outcome), token, selectionAtStart)
      await load()
    } catch (error) {
      if (!isCurrent(token, generation)) return
      if (apiStatus(error) === 404) {
        canRetryExact.value = true
        needsReconciliation.value = true
        mutationError.value = 'Ainda sem registro no servidor. Você pode repetir o mesmo conteúdo com a mesma identificação.'
      } else if (!await handleReadDenial(pending.operation as ErpFinanceAction, error, token)) {
        mutationError.value = errorMessage(error, 'Não foi possível consultar a operação.')
      }
    } finally {
      if (isAuthorityCurrent(token)) mutating.value = false
    }
  }
  async function retryExact() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || !canRetryExact.value) return null
    return post(pending.path, pending.payload, pending.operation, pending)
  }
  onMounted(() => {
    restorePending()
    load()
    loadReferences()
  })
  onScopeDispose(() => {
    requestGeneration++
    clearDetailRequests()
    referenceSequences.establishments++
    referenceSequences.accounts++
    referenceSequences.cashSessions++
  })

  return {
    tab, rows, meta, filters, establishments, accountOptions, cashSessions, referenceMeta, referenceLoading, referenceErrors,
    allocations, allocationMeta, candidates, candidateMeta, receipt, selected, operationOutcome, authorityGeneration,
    pageError, referenceError, mutationError, fieldErrors, loading, mutating, selectedOperation,
    canRead, canWrite, canRetryExact, needsReconciliation, allowed, paths,
    load, loadReferences, loadMoreReferences, selectTab, inspect, inspectPayment, inspectObligation, closeSelected,
    loadAllocations, loadCandidates, closeCandidates, loadReceipt, closeReceipt, post, reconcile, retryExact
  }
}
