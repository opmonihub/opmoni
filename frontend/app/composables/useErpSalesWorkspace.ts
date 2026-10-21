import type {
  ErpCommercialDraftPayload,
  ErpCommercialDocument,
  ErpCommercialKind,
  ErpFinanceReceipt,
  ErpObligation,
  ErpPayment,
  ErpSaleReturn,
  ErpSalesContact,
  ErpSalesEstablishment,
  ErpSalesPage,
  ErpSalesUnit,
  ErpSalesVariant
} from '#shared/types/erpBusiness'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '~/composables/useApiError'
import { createPendingOperation, parsePendingOperation, pendingOperationLookup, writePendingOperation, type ErpPendingOperation } from '#shared/utils/erpOperationRecovery'
import { erpSalePostPayload, erpSalesActionAllowed } from '#shared/utils/erpSales'

export type ErpSalesArea = 'quotes' | 'orders' | 'sales' | 'returns'
type SalesAction = string
type SalesRow = ErpCommercialDocument | ErpSaleReturn
type Page<T> = ErpSalesPage<T>
type PendingSalesOperation = ErpPendingOperation & { method: 'POST' | 'PATCH' }
type SalesReferences = { establishments: ErpSalesEstablishment[], contacts: ErpSalesContact[], units: ErpSalesUnit[], variants: ErpSalesVariant[] }

const paths: Record<ErpSalesArea, string> = { quotes: '/erp/quotes', orders: '/erp/orders', sales: '/erp/sales', returns: '/erp/sale-returns' }
const indexActions: Record<ErpSalesArea, SalesAction> = { quotes: 'erp.quotes.index', orders: 'erp.orders.index', sales: 'erp.sales.index', returns: 'erp.sale-returns.index' }
const showActions: Record<ErpSalesArea, SalesAction> = { quotes: 'erp.quotes.show', orders: 'erp.orders.show', sales: 'erp.sales.show', returns: 'erp.sale-returns.show' }
const writeActions: Record<string, SalesAction> = {
  'quote.store': 'erp.quotes.store', 'quote.update': 'erp.quotes.update', 'quote.convert': 'erp.quotes.convert',
  'order.store': 'erp.orders.store', 'order.update': 'erp.orders.update', 'order.convert': 'erp.orders.convert',
  'sale.store': 'erp.sales.store', 'sale.update': 'erp.sales.update', 'sale.post': 'erp.sales.post',
  'return.store': 'erp.sale-returns.store', 'return.post': 'erp.sale-returns.post'
}
const operationRoutes: Record<string, string | RegExp> = {
  'erp.quotes.store': '/erp/quotes', 'erp.quotes.update': /^\/erp\/quotes\/\d+$/, 'erp.quotes.convert': /^\/erp\/quotes\/\d+\/convert$/,
  'erp.orders.store': '/erp/orders', 'erp.orders.update': /^\/erp\/orders\/\d+$/, 'erp.orders.convert': /^\/erp\/orders\/\d+\/convert$/,
  'erp.sales.store': '/erp/sales', 'erp.sales.update': /^\/erp\/sales\/\d+$/, 'erp.sales.post': /^\/erp\/sales\/\d+\/post$/,
  'erp.sale-returns.store': '/erp/sale-returns', 'erp.sale-returns.post': /^\/erp\/sale-returns\/\d+\/post$/
}
function operationMethod(operation: string): PendingSalesOperation['method'] {
  return operation.endsWith('.update') ? 'PATCH' : 'POST'
}
const areaOf = (path: string): ErpSalesArea => path.includes('/sale-returns') ? 'returns' : path.includes('/quotes') ? 'quotes' : path.includes('/orders') ? 'orders' : 'sales'
export function useErpSalesWorkspace(initialArea: ErpSalesArea = 'quotes') {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany, refreshWithStatus } = useAuth()
  const context = useErpAdministrationContext()
  const area = ref<ErpSalesArea>(initialArea)
  const rows = reactive<Record<ErpSalesArea, SalesRow[]>>({ quotes: [], orders: [], sales: [], returns: [] })
  const metas = reactive<Record<ErpSalesArea, Page<unknown>['meta'] | null>>({ quotes: null, orders: null, sales: null, returns: null })
  const filters = reactive({ page: 1, per_page: 15, status: '' })
  const loading = reactive<Record<ErpSalesArea, boolean>>({ quotes: false, orders: false, sales: false, returns: false })
  const stale = reactive<Record<ErpSalesArea, boolean>>({ quotes: false, orders: false, sales: false, returns: false })
  const listErrors = reactive<Record<ErpSalesArea, string>>({ quotes: '', orders: '', sales: '', returns: '' })
  const references = reactive<SalesReferences>({ establishments: [], contacts: [], units: [], variants: [] })
  const referenceLoading = ref(false)
  const referenceError = ref('')
  const selected = ref<SalesRow | null>(null)
  const paymentDetails = ref<ErpPayment[]>([])
  const selectedObligation = ref<ErpObligation | null>(null)
  const receipt = ref<ErpFinanceReceipt | null>(null)
  const detailLoading = ref(false)
  const detailError = ref('')
  const mutationError = ref('')
  const fieldErrors = ref<Record<string, string>>({})
  const mutating = ref(false)
  const selectedOperation = ref<PendingSalesOperation | null>(null)
  const needsReconciliation = ref(false)
  const revisionConflict = ref<{ area: ErpSalesArea, id: number, latestRevision: number } | null>(null)
  const canRetryExact = ref(false)
  const resolvedOperation = ref<{ key: string, operation: string, outcome: unknown } | null>(null)
  const completedDetailRefresh = ref<Array<{ area: ErpSalesArea, id: number }>>([])
  const authorityGeneration = ref(0)
  const operationIntentSequence = ref(0)
  const deniedActions = ref(new Set<string>())
  const authorityNeedsRefresh = ref(false)
  const permissions = computed(() => currentCompany.value?.permissions ?? [])
  const role = computed(() => currentCompany.value?.role)
  const allowed = (action: SalesAction) => !authorityNeedsRefresh.value && !deniedActions.value.has(action) && erpSalesActionAllowed(permissions.value, role.value, action)
  const canRead = computed(() => allowed(indexActions[area.value]))
  const currentRows = computed(() => rows[area.value])
  const currentMeta = computed(() => metas[area.value])
  const currentLoading = computed(() => loading[area.value])
  const currentStale = computed(() => stale[area.value])
  const currentError = computed(() => listErrors[area.value])
  const storageKey = computed(() => `erp-sales:v1:${user.value?.id ?? 'anonymous'}:${currentCompany.value?.id ?? 'none'}`)
  let listSequence: Record<ErpSalesArea, number> = { quotes: 0, orders: 0, sales: 0, returns: 0 }
  let detailSequence = 0
  let referenceSequence = 0
  let requestGeneration = 0
  let operationSequence = 0

  function operationIdentity(operation: string) {
    return `${user.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${operation}`
  }
  function message(error: unknown, fallback: string) {
    return apiErrorMessage(error) ?? fallback
  }
  function authorityCurrent(token: ReturnType<typeof context.capture>, generation: number) {
    return requestGeneration === generation && context.isCurrent(token)
  }
  function current(token: ReturnType<typeof context.capture>, generation: number, intent: number) {
    return authorityCurrent(token, generation) && operationIntentSequence.value === intent
  }
  function operationCurrent(token: ReturnType<typeof context.capture>, generation: number, sequence: number) {
    return authorityCurrent(token, generation) && sequence === operationSequence
  }
  function storePending(operation: PendingSalesOperation | null) {
    if (typeof sessionStorage === 'undefined') return false
    try {
      writePendingOperation(sessionStorage, storageKey.value, operation)
      selectedOperation.value = operation
      return true
    } catch { return false }
  }
  function restorePending() {
    if (typeof sessionStorage === 'undefined') return
    try {
      const raw = sessionStorage.getItem(storageKey.value)
      const value = raw ? JSON.parse(raw) as { operation?: unknown } : null
      if (typeof value?.operation !== 'string') return
      const pending = parsePendingOperation(value, operationIdentity(value.operation), operationRoutes) as PendingSalesOperation | null
      if (!pending) return
      if (pending.method !== operationMethod(pending.operation)) return
      selectedOperation.value = pending
      needsReconciliation.value = true
      mutationError.value = 'Há uma operação de vendas sem resultado confirmado. Consulte ou repita a mesma operação antes de iniciar outra.'
    } catch { /* Invalid session data is not trusted. */ }
  }
  function clearPrivateState() {
    requestGeneration++
    operationIntentSequence.value++
    operationSequence++
    detailSequence++
    referenceSequence++
    listSequence = { quotes: listSequence.quotes + 1, orders: listSequence.orders + 1, sales: listSequence.sales + 1, returns: listSequence.returns + 1 }
    for (const key of Object.keys(rows) as ErpSalesArea[]) {
      rows[key] = []
      metas[key] = null
      loading[key] = false
      stale[key] = false
      listErrors[key] = ''
    }
    references.establishments = []
    references.contacts = []
    references.units = []
    references.variants = []
    referenceLoading.value = false
    referenceError.value = ''
    selected.value = null
    completedDetailRefresh.value = []
    paymentDetails.value = []
    selectedObligation.value = null
    receipt.value = null
    detailLoading.value = false
    detailError.value = ''
    mutationError.value = ''
    fieldErrors.value = {}
    mutating.value = false
    selectedOperation.value = null
    needsReconciliation.value = false
    revisionConflict.value = null
    canRetryExact.value = false
    resolvedOperation.value = null
    authorityGeneration.value++
  }
  async function refreshAuthority(token: ReturnType<typeof context.capture>, generation = requestGeneration) {
    if (!authorityCurrent(token, generation)) return
    authorityNeedsRefresh.value = true
    const result = await refreshWithStatus()
    if (authorityCurrent(token, generation) && result.committed) authorityNeedsRefresh.value = false
  }
  async function handleDenial(action: string, error: unknown, token: ReturnType<typeof context.capture>) {
    if (!context.isCurrent(token)) return false
    const status = apiStatus(error)
    if (status === 401 || status === 403) {
      deniedActions.value = new Set(deniedActions.value).add(action)
      await refreshAuthority(token)
      return true
    }
    return false
  }
  function queryFor(areaName: ErpSalesArea, page: number) {
    const query = new URLSearchParams({ page: String(page), per_page: String(filters.per_page) })
    if (filters.status) query.set('status', filters.status)
    return `${paths[areaName]}?${query}`
  }
  async function load(areaName: ErpSalesArea = area.value, page = filters.page, intent = operationIntentSequence.value) {
    const action = indexActions[areaName]
    if (!allowed(action) || !currentCompany.value || intent !== operationIntentSequence.value) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++listSequence[areaName]
    loading[areaName] = true
    listErrors[areaName] = ''
    try {
      const result = await api<Page<SalesRow>>(queryFor(areaName, page))
      if (!current(token, generation, intent) || sequence !== listSequence[areaName]) return
      rows[areaName] = result.data
      metas[areaName] = result.meta ?? null
      stale[areaName] = false
      if (areaName === area.value) filters.page = page
    } catch (error) {
      if (!current(token, generation, intent) || sequence !== listSequence[areaName]) return
      stale[areaName] = rows[areaName].length > 0
      listErrors[areaName] = message(error, 'Não foi possível atualizar esta lista.')
      await handleDenial(action, error, token)
    } finally {
      if (current(token, generation, intent) && sequence === listSequence[areaName]) loading[areaName] = false
    }
  }
  async function loadAllPages<T>(path: string, action: string, token: ReturnType<typeof context.capture>, generation: number): Promise<T[]> {
    if (!allowed(action)) return []
    const data: T[] = []
    let page = 1
    let lastPage = 1
    while (page <= lastPage) {
      const result = await api<Page<T>>(`${path}?${new URLSearchParams({ status: 'active', page: String(page), per_page: '100' })}`)
      if (!authorityCurrent(token, generation)) return []
      data.push(...result.data)
      lastPage = result.meta.last_page
      page++
    }
    return data
  }
  async function loadReferences(_intent = operationIntentSequence.value) {
    if (!currentCompany.value) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++referenceSequence
    referenceLoading.value = true
    referenceError.value = ''
    const configs = [
      ['/erp/establishments', 'erp.establishments.index'], ['/erp/contacts', 'erp.contacts.index'], ['/erp/units', 'erp.units.index'], ['/erp/products', 'erp.products.index']
    ] as const
    const results = await Promise.allSettled(configs.map(([path, action]) => loadAllPages<Record<string, unknown>>(path, action, token, generation)))
    if (!authorityCurrent(token, generation) || sequence !== referenceSequence) return
    const failures = results.flatMap(result => result.status === 'rejected' ? [result.reason] : [])
    for (let index = 0; index < results.length; index++) {
      const result = results[index]
      if (result?.status === 'rejected' && await handleDenial(configs[index]![1], result.reason, token)) {
        if (!authorityCurrent(token, generation) || sequence !== referenceSequence) return
      }
    }
    if (results[0]?.status === 'fulfilled') references.establishments = results[0].value.map(row => ({ id: Number(row.id), name: String(row.name ?? ''), status: String(row.status ?? '') })).filter(row => row.status === 'active')
    if (results[1]?.status === 'fulfilled') references.contacts = results[1].value.map(row => ({ id: Number(row.id), name: String(row.name ?? ''), trade_name: typeof row.trade_name === 'string' ? row.trade_name : null, is_customer: row.is_customer === true, status: String(row.status ?? '') })).filter(row => row.status === 'active' && row.is_customer === true)
    if (results[2]?.status === 'fulfilled') references.units = results[2].value.map(row => ({ id: Number(row.id), name: String(row.name ?? ''), symbol: typeof row.symbol === 'string' ? row.symbol : null, status: String(row.status ?? '') })).filter(row => row.status === 'active')
    if (results[3]?.status === 'fulfilled') references.variants = results[3].value.flatMap((product) => {
      if (product.status !== 'active' || !Array.isArray(product.variants)) return []
      return product.variants.filter((variant: Record<string, unknown>) => variant.status === 'active' && Number.isSafeInteger(Number(variant.id))).map((variant: Record<string, unknown>) => ({
        id: Number(variant.id), name: String(variant.name ?? product.name ?? ''), sku: typeof variant.sku === 'string' ? variant.sku : null, status: 'active',
        product: { id: Number(product.id), name: String(product.name ?? ''), kind: typeof product.kind === 'string' ? product.kind : undefined, track_stock: product.track_stock === true }
      }))
    })
    if (failures.length) referenceError.value = message(failures[0], 'Algumas referências não puderam ser carregadas.')
    if (authorityCurrent(token, generation) && sequence === referenceSequence) referenceLoading.value = false
  }
  function claimIntent() {
    operationIntentSequence.value++
    for (const key of Object.keys(loading) as ErpSalesArea[]) {
      listSequence[key]++
      loading[key] = false
    }
    detailSequence++
    detailLoading.value = false
    return operationIntentSequence.value
  }
  async function selectArea(next: ErpSalesArea) {
    const intent = claimIntent()
    area.value = next
    filters.page = 1
    filters.status = ''
    await Promise.all([load(next, 1, intent), loadReferences(intent)])
  }
  function setPage(page: number) {
    const lastPage = metas[area.value]?.last_page ?? 1
    if (!Number.isSafeInteger(page) || page < 1 || page > Math.max(1, lastPage)) return
    const intent = claimIntent()
    filters.page = page
    void load(area.value, page, intent)
  }
  function setStatus(status: string) {
    claimIntent()
    filters.status = status
    filters.page = 1
    void load(area.value, 1)
  }
  async function refreshArea() {
    const intent = claimIntent()
    await load(area.value, filters.page, intent)
  }
  async function inspect(areaName: ErpSalesArea, id: number, intent = claimIntent()) {
    if (!allowed(showActions[areaName]) || intent !== operationIntentSequence.value) return null
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++detailSequence
    detailLoading.value = true
    detailError.value = ''
    paymentDetails.value = []
    selectedObligation.value = null
    receipt.value = null
    const base = paths[areaName]
    try {
      const result = await api<{ data: SalesRow }>(`${base}/${id}`)
      if (!current(token, generation, intent) || sequence !== detailSequence) return null
      completedDetailRefresh.value = completedDetailRefresh.value.filter(item => item.area !== areaName || item.id !== id)
      selected.value = result.data
      const commercial = areaName === 'sales' ? result.data as ErpCommercialDocument : null
      if (commercial?.finance) {
        if (commercial.finance.payment_ids?.length && allowed('erp.payments.show')) {
          const payments = await Promise.allSettled(commercial.finance.payment_ids.map(async paymentId => (await api<{ data: ErpPayment }>(`/erp/payments/${paymentId}`)).data))
          if (!current(token, generation, intent) || sequence !== detailSequence) return null
          paymentDetails.value = payments.flatMap(payment => payment.status === 'fulfilled' ? [payment.value] : [])
          const paymentFailure = payments.find(payment => payment.status === 'rejected')
          if (paymentFailure?.status === 'rejected') {
            detailError.value = message(paymentFailure.reason, 'Alguns recebimentos não estão disponíveis nesta autorização.')
            await handleDenial('erp.payments.show', paymentFailure.reason, token)
            if (!current(token, generation, intent) || sequence !== detailSequence) return null
          }
        }
        const obligationId = commercial.finance.original_obligation_id
        if (obligationId && allowed('erp.obligations.show')) {
          try {
            const obligation = await api<{ data: ErpObligation }>(`/erp/obligations/${obligationId}`)
            if (!current(token, generation, intent) || sequence !== detailSequence) return null
            selectedObligation.value = obligation.data
          } catch (error) {
            if (!current(token, generation, intent) || sequence !== detailSequence) return null
            detailError.value = message(error, 'A situação da obrigação não está disponível nesta autorização.')
            await handleDenial('erp.obligations.show', error, token)
            if (!current(token, generation, intent) || sequence !== detailSequence) return null
          }
        }
      }
      return result.data
    } catch (error) {
      if (!current(token, generation, intent) || sequence !== detailSequence) return null
      detailError.value = message(error, 'Não foi possível abrir o documento.')
      await handleDenial(showActions[areaName], error, token)
      return null
    } finally {
      if (current(token, generation, intent) && sequence === detailSequence) detailLoading.value = false
    }
  }
  function closeSelected() {
    claimIntent()
    detailSequence++
    selected.value = null
    paymentDetails.value = []
    selectedObligation.value = null
    receipt.value = null
    detailError.value = ''
    detailLoading.value = false
  }
  function requiresFreshDetail(areaName: ErpSalesArea, id: number) {
    return completedDetailRefresh.value.some(item => item.area === areaName && item.id === id)
  }
  async function refreshCompletedDetail(areaName: ErpSalesArea, id: number) {
    const intent = claimIntent()
    return inspect(areaName, id, intent)
  }
  function pendingRead() {
    if (typeof sessionStorage === 'undefined') return null
    try {
      const raw = sessionStorage.getItem(storageKey.value)
      const record = raw ? JSON.parse(raw) as { operation?: unknown } : null
      if (typeof record?.operation !== 'string') return null
      const pending = parsePendingOperation(record, operationIdentity(record.operation), operationRoutes) as PendingSalesOperation | null
      return pending && pending.method === operationMethod(pending.operation) ? pending : null
    } catch { return null }
  }
  function currentOperationKey() {
    return selectedOperation.value?.key ?? null
  }
  function resultId(value: unknown): number | null {
    if (typeof value !== 'object' || value === null) return null
    const record = value as Record<string, unknown>
    if (typeof record.id === 'number' && Number.isSafeInteger(record.id)) return record.id
    for (const child of Object.values(record)) {
      if (typeof child === 'object' && child !== null && 'id' in child && typeof (child as { id?: unknown }).id === 'number') return (child as { id: number }).id
    }
    return null
  }
  async function reloadAfterOperation(operation: PendingSalesOperation, data: unknown, token: ReturnType<typeof context.capture>, generation: number, intent: number) {
    if (!current(token, generation, intent)) return
    const sourceArea = areaOf(operation.path)
    let targetArea = sourceArea
    const pathId = operation.path.match(/\/(\d+)(?:\/|$)/)?.[1]
    let targetId: number | null = pathId ? Number(pathId) : resultId(data)
    if (operation.operation === 'erp.quotes.convert' && typeof data === 'object' && data !== null) {
      const order = (data as { order?: { id?: unknown } }).order
      if (typeof order?.id === 'number') {
        targetArea = 'orders'
        targetId = order.id
      }
    } else if (operation.operation === 'erp.orders.convert' && typeof data === 'object' && data !== null) {
      const sale = (data as { sale?: { id?: unknown } }).sale
      if (typeof sale?.id === 'number') {
        targetArea = 'sales'
        targetId = sale.id
      }
    }
    const sourceId = pathId ? Number(pathId) : null
    const targets: Array<{ area: ErpSalesArea, id: number }> = []
    if (sourceId) targets.push({ area: sourceArea, id: sourceId })
    if (targetId) targets.push({ area: targetArea, id: targetId })
    for (const target of targets) {
      if (target.id && allowed(showActions[target.area]) && !requiresFreshDetail(target.area, target.id)) {
        completedDetailRefresh.value = [...completedDetailRefresh.value, target]
      }
    }
    await load(sourceArea, filters.page, intent)
    if (!current(token, generation, intent)) return
    if (targetArea !== sourceArea) await load(targetArea, 1, intent)
    if (!current(token, generation, intent)) return
    if (targetArea !== sourceArea && sourceId && requiresFreshDetail(sourceArea, sourceId) && allowed(showActions[sourceArea])) {
      try {
        await api<{ data: SalesRow }>(`${paths[sourceArea]}/${sourceId}`)
        if (!current(token, generation, intent)) return
        completedDetailRefresh.value = completedDetailRefresh.value.filter(item => item.area !== sourceArea || item.id !== sourceId)
      } catch (error) {
        if (!current(token, generation, intent)) return
        await handleDenial(showActions[sourceArea], error, token)
        if (!current(token, generation, intent)) return
      }
    }
    if (targetId && allowed(showActions[targetArea])) {
      const refreshed = await inspect(targetArea, targetId, intent)
      if (!refreshed && current(token, generation, intent) && requiresFreshDetail(targetArea, targetId)) {
        detailError.value = 'A operação foi confirmada, mas o detalhe atualizado ainda não pôde ser lido. Atualize a consulta antes de iniciar outra alteração neste documento.'
      }
    }
  }
  async function execute(path: string, body: Record<string, unknown>, action: string, existing?: PendingSalesOperation) {
    mutationError.value = ''
    fieldErrors.value = {}
    canRetryExact.value = false
    resolvedOperation.value = null
    if (mutating.value) return null
    if (!allowed(action)) {
      mutationError.value = 'Seu acesso não permite esta operação.'
      return null
    }
    if (selectedOperation.value && !existing) {
      mutationError.value = 'Consulte ou repita a operação original antes de iniciar outra.'
      needsReconciliation.value = true
      return null
    }
    const pathId = path.match(/\/(\d+)(?:\/|$)/)?.[1]
    if (!existing && pathId && requiresFreshDetail(areaOf(path), Number(pathId))) {
      mutationError.value = 'Atualize o detalhe confirmado pelo servidor antes de iniciar outra alteração neste documento.'
      return null
    }
    const operation = existing ?? { ...createPendingOperation(operationIdentity(action), action, path, body, crypto.randomUUID()), method: operationMethod(action) }
    if (operation.identity !== operationIdentity(action)) return null
    if (!storePending(operation)) {
      mutationError.value = 'Não foi possível guardar a identificação desta operação nesta sessão.'
      return null
    }
    const token = context.capture()
    const generation = requestGeneration
    const intent = operationIntentSequence.value
    const sequence = ++operationSequence
    mutating.value = true
    needsReconciliation.value = false
    try {
      await refreshCsrf()
      if (!operationCurrent(token, generation, sequence)) return null
      const result = await api<{ data: unknown }>(operation.path, { method: operation.method, body: operation.payload, headers: { 'Idempotency-Key': operation.key } })
      if (!operationCurrent(token, generation, sequence)) return null
      const removed = storePending(null)
      needsReconciliation.value = !removed
      mutationError.value = removed ? 'Operação confirmada.' : 'Operação confirmada. Consulte-a antes de iniciar outra.'
      await reloadAfterOperation(operation, result.data, token, generation, intent)
      if (!operationCurrent(token, generation, sequence)) return null
      resolvedOperation.value = { key: operation.key, operation: action, outcome: result.data }
      return result.data
    } catch (error) {
      if (!operationCurrent(token, generation, sequence)) return null
      const status = apiStatus(error)
      if (status === 422) {
        fieldErrors.value = apiFieldErrors(error)
        storePending(null)
        mutationError.value = message(error, 'Revise os campos destacados.')
      } else if (status === 409 && action.endsWith('.update') && message(error, '') === 'Rascunho alterado.') {
        const sourceArea = areaOf(path)
        const id = Number(path.split('/').at(-1))
        const showAction = showActions[sourceArea]
        if (Number.isSafeInteger(id) && id > 0 && allowed(showAction) && intent === operationIntentSequence.value) {
          const latest = await inspect(sourceArea, id, intent)
          if (!operationCurrent(token, generation, sequence) || intent !== operationIntentSequence.value) return null
          if (latest && 'draft_revision' in latest && typeof latest.draft_revision === 'number' && latest.draft_revision !== body.draft_revision) {
            storePending(null)
            needsReconciliation.value = false
            canRetryExact.value = false
            revisionConflict.value = { area: sourceArea, id, latestRevision: latest.draft_revision }
            mutationError.value = 'O rascunho mudou no servidor. Suas alterações continuam abertas para revisão.'
          } else {
            needsReconciliation.value = true
            mutationError.value = 'A revisão mudou, mas o estado atual não pôde ser confirmado. Mantenha a operação original e consulte-a.'
          }
        } else {
          needsReconciliation.value = true
          mutationError.value = 'A revisão mudou. Consulte a versão atual autorizada antes de continuar.'
        }
      } else if (status === 401 || status === 403) {
        await handleDenial(action, error, token)
        if (!operationCurrent(token, generation, sequence)) return null
        mutationError.value = 'A autorização mudou. Atualize o contexto antes de continuar.'
      } else {
        needsReconciliation.value = true
        mutationError.value = status === 409
          ? message(error, 'A revisão ou o resultado precisa ser conferido antes de continuar.')
          : 'Não foi possível confirmar o resultado. Consulte a operação original antes de repetir.'
      }
      return null
    } finally {
      if (operationCurrent(token, generation, sequence)) mutating.value = false
    }
  }
  async function saveDraft(kind: ErpCommercialKind, payload: ErpCommercialDraftPayload, id?: number | null) {
    const action = writeActions[`${kind}.${id ? 'update' : 'store'}`]!
    const base = paths[kind === 'quote' ? 'quotes' : kind === 'order' ? 'orders' : 'sales']
    return execute(id ? `${base}/${id}` : base, payload as unknown as Record<string, unknown>, action, undefined)
  }
  async function convertQuote(row: ErpCommercialDocument, orderNo?: string) {
    if (!allowed('erp.quotes.convert')) return null
    const body: Record<string, unknown> = { draft_revision: row.draft_revision }
    if (orderNo) body.order_no = orderNo
    return execute(`/erp/quotes/${row.id}/convert`, body, 'erp.quotes.convert')
  }
  async function convertOrder(row: ErpCommercialDocument, invoiceNo?: string) {
    if (!allowed('erp.orders.convert')) return null
    const body: Record<string, unknown> = { draft_revision: row.draft_revision }
    if (invoiceNo) body.invoice_no = invoiceNo
    return execute(`/erp/orders/${row.id}/convert`, body, 'erp.orders.convert')
  }
  async function postSale(row: ErpCommercialDocument, payment?: Record<string, unknown>) {
    if (!allowed('erp.sales.post')) return null
    const body = erpSalePostPayload(row, payment)
    return execute(`/erp/sales/${row.id}/post`, body, 'erp.sales.post')
  }
  async function storeReturn(saleId: number, reason: string, lines: Array<{ source_line_id: number, quantity: string }>) {
    return execute('/erp/sale-returns', { erp_sale_id: saleId, reason, lines }, 'erp.sale-returns.store')
  }
  async function postReturn(row: ErpSaleReturn) {
    return execute(`/erp/sale-returns/${row.id}/post`, {}, 'erp.sale-returns.post')
  }
  async function reconcile() {
    const pending = selectedOperation.value ?? pendingRead()
    if (!pending || pending.identity !== operationIdentity(pending.operation) || mutating.value) return
    selectedOperation.value = pending
    const token = context.capture()
    const generation = requestGeneration
    const intent = operationIntentSequence.value
    const sequence = ++operationSequence
    mutating.value = true
    try {
      const lookup = pendingOperationLookup(pending)
      const result = await api<{ data: { state: string, result?: unknown } }>(lookup.path, { headers: lookup.headers })
      if (!operationCurrent(token, generation, sequence)) return
      if (result.data.state !== 'completed') {
        needsReconciliation.value = true
        mutationError.value = 'O servidor ainda não confirmou o resultado. Mantenha a operação original e consulte novamente.'
        return
      }
      const removed = storePending(null)
      needsReconciliation.value = !removed
      mutationError.value = 'Operação confirmada pelo registro do servidor.'
      await reloadAfterOperation(pending, result.data.result ?? null, token, generation, intent)
      if (!operationCurrent(token, generation, sequence)) return
      resolvedOperation.value = { key: pending.key, operation: pending.operation, outcome: result.data.result ?? null }
    } catch (error) {
      if (!operationCurrent(token, generation, sequence)) return
      if (apiStatus(error) === 404) {
        needsReconciliation.value = true
        canRetryExact.value = true
        mutationError.value = 'Ainda sem registro no servidor. Você pode repetir exatamente a operação original.'
      } else {
        await handleDenial(pending.operation, error, token)
        if (!operationCurrent(token, generation, sequence)) return
        mutationError.value = message(error, 'Não foi possível consultar a operação.')
      }
    } finally {
      if (operationCurrent(token, generation, sequence)) mutating.value = false
    }
  }
  async function retryExact() {
    const pending = selectedOperation.value ?? pendingRead()
    if (!pending || !canRetryExact.value) return null
    return execute(pending.path, pending.payload, pending.operation, pending)
  }
  async function loadReceipt(paymentId: number, intent = claimIntent()) {
    if (!allowed('erp.payments.receipt')) return null
    const token = context.capture()
    const generation = requestGeneration
    try {
      const result = await api<{ data: unknown }>(`/erp/payments/${paymentId}/receipt`)
      if (!current(token, generation, intent)) return null
      receipt.value = result.data as ErpFinanceReceipt
      return receipt.value
    } catch (error) {
      if (!current(token, generation, intent)) return null
      detailError.value = message(error, 'Não foi possível abrir o recibo privado.')
      await handleDenial('erp.payments.receipt', error, token)
      return null
    }
  }
  watch(context.key, () => {
    deniedActions.value = new Set()
    authorityNeedsRefresh.value = false
    clearPrivateState()
    restorePending()
    if (currentCompany.value) void loadReferences()
  }, { flush: 'sync' })
  onMounted(() => {
    restorePending()
    void loadReferences()
    if (canRead.value) void load(area.value)
  })
  onScopeDispose(clearPrivateState)

  return {
    area, rows, metas, filters, loading, stale, listErrors, currentRows, currentMeta, currentLoading, currentStale, currentError,
    references, referenceLoading, referenceError, selected, paymentDetails, selectedObligation, receipt, detailLoading, detailError, mutationError, fieldErrors,
    mutating, selectedOperation, currentOperationKey, needsReconciliation, revisionConflict, canRetryExact, resolvedOperation, authorityGeneration, operationIntentSequence,
    completedDetailRefresh, requiresFreshDetail, refreshCompletedDetail,
    allowed, canRead, load, loadReferences, selectArea, setPage, setStatus, refreshArea, inspect, closeSelected, saveDraft, convertQuote,
    convertOrder, postSale, storeReturn, postReturn, reconcile, retryExact, loadReceipt, claimIntent
  }
}
