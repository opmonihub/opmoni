import type { ErpFinancePage, ErpFinancialAccount, ErpObligation, ErpPayment, ErpPaymentAllocation, ErpFinanceReceipt } from '#shared/types/erpBusiness'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '~/composables/useApiError'
import { erpFinanceAllowed, type ErpFinanceAction } from '#shared/utils/erpFinance'

type Tab = 'accounts' | 'obligations' | 'payments'
type Row = ErpFinancialAccount | ErpObligation | ErpPayment
type PageMeta = ErpFinancePage<Row>['meta']
type FinanceFilter = { page: number, per_page: number, erp_establishment_id: string, erp_financial_account_id: string, direction: string, status: string, currency: string, date_from: string, date_to: string }
type PendingOperation = { identity: string, operation: string, path: string, payload: Record<string, unknown>, key: string }

const paths: Record<Tab, string> = { accounts: '/erp/financial-accounts', obligations: '/erp/obligations', payments: '/erp/payments' }
const operationNames: Record<string, string> = {
  '/erp/financial-accounts': 'erp.financial-accounts.store',
  '/erp/obligations': 'erp.obligations.store',
  '/erp/payments': 'erp.payments.store'
}

export function useErpFinanceWorkspace() {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany } = useAuth()
  const context = useErpAdministrationContext()
  const tab = ref<Tab>('obligations')
  const rows = ref<Row[]>([])
  const meta = ref<PageMeta | null>(null)
  const filters = reactive<FinanceFilter>({ page: 1, per_page: 15, erp_establishment_id: '', erp_financial_account_id: '', direction: '', status: '', currency: '', date_from: '', date_to: '' })
  const establishments = ref<{ id: number, name: string }[]>([])
  const accounts = ref<ErpFinancialAccount[]>([])
  const payments = ref<ErpPayment[]>([])
  const cashSessions = ref<{ id: number, currency: string, erp_establishment_id: number | null, status: string }[]>([])
  const allocations = ref<ErpPaymentAllocation[]>([])
  const candidates = ref<ErpObligation[]>([])
  const receipt = ref<ErpFinanceReceipt | null>(null)
  const selected = ref<Row | null>(null)
  const authorityGeneration = ref(0)
  const pageError = ref('')
  const referenceError = ref('')
  const mutationError = ref('')
  const fieldErrors = ref<Record<string, string>>({})
  const loading = ref(false)
  const mutating = ref(false)
  const selectedOperation = ref<PendingOperation | null>(null)
  const canRetryExact = ref(false)
  const needsReconciliation = ref(false)
  const permissions = computed(() => currentCompany.value?.permissions ?? [])
  const role = computed(() => currentCompany.value?.role)
  const allowed = (action: ErpFinanceAction) => erpFinanceAllowed(permissions.value, role.value, action)
  const canRead = computed(() => allowed(tab.value === 'accounts' ? 'erp.financial-accounts.index' : tab.value === 'obligations' ? 'erp.obligations.index' : 'erp.payments.index'))
  const canWrite = computed(() => allowed('erp.financial-accounts.store') || allowed('erp.obligations.store') || allowed('erp.payments.store'))
  let requestGeneration = 0
  let authority = context.capture()

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
  function isCurrent(token: ReturnType<typeof context.capture>, generation: number) {
    return requestGeneration === generation && context.isCurrent(token) && authority.key === token.key
  }
  function authorityIsCurrent(token: ReturnType<typeof context.capture>) {
    return context.isCurrent(token) && authority.key === token.key
  }
  function clearPrivateState() {
    requestGeneration++
    authorityGeneration.value++
    rows.value = []
    meta.value = null
    establishments.value = []
    accounts.value = []
    payments.value = []
    cashSessions.value = []
    allocations.value = []
    candidates.value = []
    selected.value = null
    receipt.value = null
    pageError.value = ''
    referenceError.value = ''
    mutationError.value = ''
    fieldErrors.value = {}
    mutating.value = false
    needsReconciliation.value = false
    canRetryExact.value = false
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
    authority = context.capture()
    clearPrivateState()
    restorePending()
    load()
    loadReferences()
  }, { flush: 'sync' })

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
    const previousRows = rows.value
    const previousMeta = meta.value
    loading.value = true
    pageError.value = ''
    try {
      const suffix = queryString()
      const result = await api<ErpFinancePage<Row>>(`${paths[tab.value]}?${suffix}`)
      if (!isCurrent(token, generation)) return
      rows.value = result.data
      meta.value = result.meta
      if (tab.value === 'accounts') accounts.value = result.data as ErpFinancialAccount[]
      if (tab.value === 'payments') payments.value = result.data as ErpPayment[]
    } catch (error) {
      if (!isCurrent(token, generation)) return
      rows.value = previousRows
      meta.value = previousMeta
      pageError.value = apiErrorMessage(error) ?? 'Não foi possível carregar os lançamentos. Tente novamente.'
    } finally {
      if (isCurrent(token, generation)) loading.value = false
    }
  }
  async function loadReferences() {
    if (!currentCompany.value) return
    const token = context.capture()
    const generation = requestGeneration
    try {
      const [establishmentResult, accountResult, paymentResult, sessionResult] = await Promise.all([
        api<ErpFinancePage<{ id: number, name: string }>>('/erp/establishments?per_page=100&page=1'),
        allowed('erp.financial-accounts.index') ? api<ErpFinancePage<ErpFinancialAccount>>('/erp/financial-accounts?per_page=100&page=1&status=active') : Promise.resolve(null),
        allowed('erp.payments.index') ? api<ErpFinancePage<ErpPayment>>('/erp/payments?per_page=100&page=1&status=posted') : Promise.resolve(null),
        allowed('erp.cash-sessions.index') ? api<ErpFinancePage<{ id: number, currency: string, erp_establishment_id: number | null, status: string }>>('/erp/cash-sessions?per_page=100&page=1&status=open') : Promise.resolve(null)
      ])
      if (!isCurrent(token, generation)) return
      establishments.value = establishmentResult.data
      if (accountResult) accounts.value = accountResult.data
      if (paymentResult) payments.value = paymentResult.data
      if (sessionResult) cashSessions.value = sessionResult.data
      referenceError.value = ''
    } catch (error) {
      if (isCurrent(token, generation)) referenceError.value = apiErrorMessage(error) ?? 'Não foi possível carregar as referências autorizadas.'
    }
  }
  async function selectTab(next: Tab) {
    tab.value = next
    filters.page = 1
    filters.status = ''
    filters.direction = ''
    filters.erp_financial_account_id = ''
    rows.value = []
    meta.value = null
    selected.value = null
    allocations.value = []
    candidates.value = []
    await load()
  }
  async function inspect(row: Row) {
    const token = context.capture()
    const generation = requestGeneration
    const path = `${paths[tab.value]}/${row.id}`
    try {
      const result = await api<{ data: Row }>(path)
      if (isCurrent(token, generation)) selected.value = result.data
    } catch (error) {
      if (isCurrent(token, generation)) mutationError.value = apiErrorMessage(error) ?? 'Não foi possível abrir os detalhes.'
    }
  }
  async function inspectPayment(payment: ErpPayment) {
    const id = payment.id
    await inspect(payment)
    if (!context.isCurrent(authority)) return
    const token = context.capture()
    const generation = requestGeneration
    try {
      const result = await api<ErpFinancePage<ErpPaymentAllocation>>(`/erp/payments/${id}/allocations?per_page=100&page=1`)
      if (isCurrent(token, generation)) allocations.value = result.data
    } catch (error) {
      if (isCurrent(token, generation)) mutationError.value = apiErrorMessage(error) ?? 'Não foi possível carregar as alocações.'
    }
  }
  async function inspectObligation(id: number) {
    const token = context.capture()
    const generation = requestGeneration
    try {
      const result = await api<{ data: ErpObligation }>(`/erp/obligations/${id}`)
      if (isCurrent(token, generation)) selected.value = result.data
    } catch (error) {
      if (isCurrent(token, generation)) mutationError.value = apiErrorMessage(error) ?? 'Não foi possível abrir os detalhes da obrigação.'
    }
  }
  async function loadCandidates(payment: ErpPayment) {
    if (!allowed('erp.payments.allocate')) return
    const token = context.capture()
    const generation = requestGeneration
    try {
      const result = await api<ErpFinancePage<ErpObligation>>(`/erp/payments/${payment.id}/allocation-candidates?per_page=100&page=1`)
      if (isCurrent(token, generation)) candidates.value = result.data
    } catch (error) {
      if (isCurrent(token, generation)) mutationError.value = apiErrorMessage(error) ?? 'Não foi possível localizar obrigações compatíveis.'
    }
  }
  async function loadReceipt(payment: ErpPayment) {
    if (!allowed('erp.payments.receipt')) return
    const token = context.capture()
    const generation = requestGeneration
    try {
      const result = await api<{ data: ErpFinanceReceipt }>(`/erp/payments/${payment.id}/receipt`)
      if (isCurrent(token, generation)) receipt.value = result.data
    } catch (error) {
      if (isCurrent(token, generation)) mutationError.value = apiErrorMessage(error) ?? 'Recibo privado indisponível.'
    }
  }
  function operationIdentity(operation: string) {
    return `${user.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${operation}`
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
    const frozen: PendingOperation = existing ?? { identity: operationIdentity(action), operation: action, path, payload: structuredClone(body), key: crypto.randomUUID() }
    if (frozen.identity !== operationIdentity(action)) return null
    if (!storePending(frozen)) return null
    const token = context.capture()
    const generation = requestGeneration
    mutating.value = true
    try {
      await refreshCsrf()
      if (!isCurrent(token, generation)) return null
      const result = await api<{ data: unknown }>(frozen.path, { method: 'POST', body: frozen.payload, headers: { 'Idempotency-Key': frozen.key } })
      if (!isCurrent(token, generation)) return null
      const cleared = storePending(null)
      selected.value = result.data as Row
      mutationError.value = cleared ? 'Lançamento confirmado.' : 'Lançamento confirmado. A cópia local ainda não pôde ser removida; consulte a operação antes de iniciar outra.'
      needsReconciliation.value = !cleared
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
        mutationError.value = apiErrorMessage(error) ?? 'A operação foi recusada. Revise os dados e tente novamente.'
        if (!cleared) mutationError.value += ' A identificação local permanece guardada até o navegador permitir sua remoção.'
      } else {
        mutationError.value = 'Não foi possível confirmar o resultado. Consulte a operação antes de repetir.'
        needsReconciliation.value = true
      }
      return null
    } finally {
      if (authorityIsCurrent(token)) mutating.value = false
    }
  }
  async function reconcile() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || pending.identity !== operationIdentity(pending.operation) || mutating.value) return
    selectedOperation.value = pending
    const token = context.capture()
    const generation = requestGeneration
    mutating.value = true
    try {
      const result = await api<{ data: { state: string, result?: Row } }>(`/erp/operations/lookup?operation=${encodeURIComponent(pending.operation)}`, { headers: { 'Idempotency-Key': pending.key } })
      if (!isCurrent(token, generation)) return
      selected.value = result.data.result ?? null
      const cleared = storePending(null)
      needsReconciliation.value = !cleared
      mutationError.value = 'Operação confirmada pelo registro do servidor.'
      if (!cleared) mutationError.value += ' A cópia local ainda não pôde ser removida.'
      await load()
    } catch (error) {
      if (!isCurrent(token, generation)) return
      if (apiStatus(error) === 404) {
        canRetryExact.value = true
        needsReconciliation.value = true
        mutationError.value = 'Ainda sem registro no servidor. Você pode repetir o mesmo conteúdo com a mesma identificação.'
      } else {
        mutationError.value = apiErrorMessage(error) ?? 'Não foi possível consultar a operação.'
      }
    } finally {
      if (authorityIsCurrent(token)) mutating.value = false
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
  })

  return {
    tab, rows, meta, filters, establishments, accounts, payments, cashSessions, allocations, candidates, receipt, selected, authorityGeneration,
    pageError, referenceError, mutationError, fieldErrors, loading, mutating, selectedOperation,
    canRead, canWrite, canRetryExact, needsReconciliation, allowed, paths,
    load, loadReferences, selectTab, inspect, inspectPayment, inspectObligation, loadCandidates, loadReceipt, post, reconcile, retryExact
  }
}
