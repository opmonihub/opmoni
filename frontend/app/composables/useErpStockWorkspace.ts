import type {
  ErpStockAdjustment,
  ErpStockBalance,
  ErpStockEstablishment,
  ErpStockLot,
  ErpStockMovement,
  ErpStockMovementDetail,
  ErpStockPage,
  ErpStockTransfer,
  ErpStockTransferDetail,
  ErpStockVariant
} from '#shared/types/erpBusiness'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '~/composables/useApiError'
import { createPendingOperation, pendingOperationLookup, readPendingOperation, writePendingOperation, type ErpPendingOperation } from '#shared/utils/erpOperationRecovery'
import { buildErpStockAdjustmentPayload, buildErpStockReversalPayload, buildErpStockTransferPayload, stockActionAllowed } from '#shared/utils/erpStock'

type StockOperation = ErpPendingOperation
type StockOperationName = 'erp.stock.adjustments.store' | 'erp.stock.transfers.store' | 'erp.stock.transfers.reverse' | 'erp.stock.movements.reverse'
type StockArea = 'balances' | 'movements' | 'lots' | 'adjustments' | 'transfers'
type DetailSelection
  = | { kind: 'movement', row: ErpStockMovementDetail }
    | { kind: 'adjustment', row: ErpStockAdjustment }
    | { kind: 'transfer', row: ErpStockTransferDetail }

const readActions: Record<StockArea, string> = {
  balances: 'erp.stock.balances.index',
  movements: 'erp.stock.movements.index',
  lots: 'erp.stock.lots.index',
  adjustments: 'erp.stock.adjustments.index',
  transfers: 'erp.stock.transfers.index'
}
const operationRoutes: Record<string, string | RegExp> = {
  'erp.stock.adjustments.store': '/erp/stock/adjustments',
  'erp.stock.transfers.store': '/erp/stock/transfers',
  'erp.stock.transfers.reverse': /^\/erp\/stock\/transfers\/\d+\/reverse$/,
  'erp.stock.movements.reverse': /^\/erp\/stock\/movements\/\d+\/reverse$/
}

export function erpStockActionAllowed(grants: readonly string[] | undefined, role: string | undefined, action: string) {
  return stockActionAllowed(grants, role, action)
}

export function useErpStockWorkspace() {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany, refreshWithStatus } = useAuth()
  const context = useErpAdministrationContext()
  const balances = ref<ErpStockBalance[]>([])
  const movements = ref<ErpStockMovement[]>([])
  const lots = ref<ErpStockLot[]>([])
  const adjustments = ref<ErpStockAdjustment[]>([])
  const transfers = ref<ErpStockTransfer[]>([])
  const metas = reactive<Record<StockArea, ErpStockPage<unknown>['meta'] | null>>({ balances: null, movements: null, lots: null, adjustments: null, transfers: null })
  const stale = reactive<Record<StockArea, boolean>>({ balances: false, movements: false, lots: false, adjustments: false, transfers: false })
  const loading = reactive<Record<StockArea, boolean>>({ balances: false, movements: false, lots: false, adjustments: false, transfers: false })
  const listErrors = reactive<Record<StockArea, string>>({ balances: '', movements: '', lots: '', adjustments: '', transfers: '' })
  const establishments = ref<ErpStockEstablishment[]>([])
  const variants = ref<ErpStockVariant[]>([])
  const referencesLoading = ref(false)
  const referencesError = ref('')
  const detail = ref<DetailSelection | null>(null)
  const detailLoading = ref(false)
  const detailError = ref('')
  const detailOpen = ref(false)
  const mutationError = ref('')
  const fieldErrors = ref<Record<string, string>>({})
  const mutating = ref(false)
  const selectedOperation = ref<StockOperation | null>(null)
  const needsReconciliation = ref(false)
  const canRetryExact = ref(false)
  const resolvedOperation = ref<{ key: string, operation: string, outcome: unknown } | null>(null)
  const operationIntentSequence = ref(0)
  const authorityNeedsRefresh = ref(false)
  const authorityGeneration = ref(0)
  const deniedActions = ref(new Set<string>())
  const filters = reactive({
    erp_establishment_id: '', erp_product_variant_id: '', source_type: '', source_id: '', direction: '', date_from: '', date_to: '', status: '', page: 1, per_page: 15
  })
  const grants = computed(() => currentCompany.value?.permissions ?? [])
  const role = computed(() => currentCompany.value?.role)
  const actorId = computed(() => user.value?.id ?? null)
  const companyId = computed(() => currentCompany.value?.id ?? null)
  const storageKey = computed(() => `erp-stock:v1:${actorId.value ?? 'anonymous'}:${companyId.value ?? 'none'}`)
  let requestGeneration = 0
  let operationSequence = 0
  let referenceSequence = 0
  let selectionIntentSequence = 0
  let detailSequence = 0
  const listSequence: Record<StockArea, number> = { balances: 0, movements: 0, lots: 0, adjustments: 0, transfers: 0 }
  let authorityRefreshEpoch = 0
  let disposed = false

  function allowed(action: string) {
    return !authorityNeedsRefresh.value && !deniedActions.value.has(action) && erpStockActionAllowed(grants.value, role.value, action)
  }
  function current(token: ReturnType<typeof context.capture>, generation: number) {
    return !disposed && requestGeneration === generation && context.isCurrent(token)
  }
  function operationIdentity(operation: string) {
    return `${actorId.value ?? ''}:${companyId.value ?? ''}:${operation}`
  }
  function errorMessage(error: unknown, fallback: string) {
    return apiErrorMessage(error) ?? fallback
  }
  function isAuthorityDenial(error: unknown) {
    const status = apiStatus(error)
    if (status === 401 || status === 403) return true
    if (status !== 409) return false
    const record = error as { data?: unknown, response?: { _data?: unknown } }
    const data = record.data ?? record.response?._data
    return typeof data === 'object' && data !== null && (data as { code?: unknown }).code === 'company_context_changed'
  }
  function clearPrivateState() {
    requestGeneration++
    referenceSequence++
    selectionIntentSequence++
    detailSequence++
    operationSequence++
    for (const area of Object.keys(listSequence) as StockArea[]) {
      listSequence[area]++
      loading[area] = false
      listErrors[area] = ''
      stale[area] = false
      metas[area] = null
    }
    balances.value = []
    movements.value = []
    lots.value = []
    adjustments.value = []
    transfers.value = []
    establishments.value = []
    variants.value = []
    referencesLoading.value = false
    referencesError.value = ''
    detail.value = null
    detailLoading.value = false
    detailError.value = ''
    detailOpen.value = false
    mutationError.value = ''
    fieldErrors.value = {}
    mutating.value = false
    resolvedOperation.value = null
    needsReconciliation.value = false
    canRetryExact.value = false
    selectedOperation.value = null
    filters.erp_establishment_id = ''
    filters.erp_product_variant_id = ''
    filters.source_type = ''
    filters.source_id = ''
    filters.direction = ''
    filters.date_from = ''
    filters.date_to = ''
    filters.status = ''
    filters.page = 1
    authorityGeneration.value++
  }
  function readPending() {
    if (typeof sessionStorage === 'undefined') return null
    return readPendingOperation(sessionStorage, storageKey.value, operationIdentityFromStored(), operationRoutes)
  }
  function operationIdentityFromStored() {
    try {
      const raw = typeof sessionStorage === 'undefined' ? null : sessionStorage.getItem(storageKey.value)
      const operation = raw ? (JSON.parse(raw) as { operation?: unknown }).operation : null
      return typeof operation === 'string' ? operationIdentity(operation) : ''
    } catch {
      return ''
    }
  }
  function storePending(operation: StockOperation | null) {
    if (typeof sessionStorage === 'undefined') {
      mutationError.value = 'O navegador não permite guardar a identificação da operação. Ative o armazenamento da sessão para continuar.'
      return false
    }
    try {
      writePendingOperation(sessionStorage, storageKey.value, operation)
      return true
    } catch {
      mutationError.value = 'Não foi possível guardar a identificação da operação. Nenhum envio foi feito.'
      return false
    }
  }
  function restorePending() {
    const pending = readPending()
    if (!pending) return
    if (!allowed(pending.operation)) {
      selectedOperation.value = null
      needsReconciliation.value = false
      canRetryExact.value = false
      return
    }
    selectedOperation.value = pending
    needsReconciliation.value = true
    mutationError.value = 'Existe uma operação de estoque sem resultado confirmado. Consulte ou repita a mesma operação antes de iniciar outra.'
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
      if (context.isCurrent(token) && authorityRefreshEpoch === epoch)
        mutationError.value = 'A autorização mudou. Atualize sua sessão para continuar.'
    }
  }
  async function handleDenial(action: string, error: unknown, token: ReturnType<typeof context.capture>, refresh = false) {
    if (!context.isCurrent(token) || !isAuthorityDenial(error)) return false
    deniedActions.value = new Set([...deniedActions.value, action])
    const status = apiStatus(error)
    const epoch = ++authorityRefreshEpoch
    if (status === 401 || status === 409 || (status === 403 && refresh)) authorityNeedsRefresh.value = true
    clearPrivateState()
    restorePending()
    mutationError.value = errorMessage(error, 'Sua autorização mudou. Os dados privados de estoque foram removidos.')
    if (status === 401 || status === 409 || (status === 403 && refresh)) await refreshAuth(token, epoch)
    return true
  }

  function pageQuery(area: StockArea, page: number) {
    const query = new URLSearchParams({ page: String(page), per_page: String(filters.per_page) })
    if (filters.erp_establishment_id) query.set('erp_establishment_id', filters.erp_establishment_id)
    if (filters.erp_product_variant_id) query.set('erp_product_variant_id', filters.erp_product_variant_id)
    if (['movements', 'lots', 'adjustments', 'transfers'].includes(area)) {
      if (filters.source_type && filters.source_id) {
        query.set('source_type', filters.source_type)
        query.set('source_id', filters.source_id)
      }
    }
    if (['movements', 'lots'].includes(area)) {
      if (filters.date_from) query.set('date_from', filters.date_from)
      if (filters.date_to) query.set('date_to', filters.date_to)
    }
    if (area === 'movements' && filters.direction) query.set('direction', filters.direction)
    if (['adjustments', 'transfers'].includes(area)) {
      if (filters.status) query.set('status', filters.status)
      if (filters.date_from) query.set('date_from', filters.date_from)
      if (filters.date_to) query.set('date_to', filters.date_to)
    }
    return query.toString()
  }
  function assignRows(area: StockArea, rows: unknown[]) {
    if (area === 'balances') balances.value = rows as ErpStockBalance[]
    else if (area === 'movements') movements.value = rows as ErpStockMovement[]
    else if (area === 'lots') lots.value = rows as ErpStockLot[]
    else if (area === 'adjustments') adjustments.value = rows as ErpStockAdjustment[]
    else transfers.value = rows as ErpStockTransfer[]
  }
  function rowsFor(area: StockArea) {
    if (area === 'balances') return balances.value
    if (area === 'movements') return movements.value
    if (area === 'lots') return lots.value
    if (area === 'adjustments') return adjustments.value
    return transfers.value
  }
  async function load(area: StockArea, page = filters.page) {
    const action = readActions[area]
    if (!allowed(action) || !currentCompany.value) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++listSequence[area]
    loading[area] = true
    listErrors[area] = ''
    try {
      const result = await api<ErpStockPage<unknown>>(`/erp/stock/${area}?${pageQuery(area, page)}`)
      if (!current(token, generation) || sequence !== listSequence[area]) return
      assignRows(area, result.data)
      metas[area] = result.meta ?? null
      stale[area] = false
      filters.page = page
    } catch (error) {
      if (!current(token, generation) || sequence !== listSequence[area]) return
      stale[area] = rowsFor(area).length > 0
      listErrors[area] = errorMessage(error, 'Não foi possível atualizar esta lista.')
      await handleDenial(action, error, token)
    } finally {
      if (current(token, generation) && sequence === listSequence[area]) loading[area] = false
    }
  }
  async function loadAllPages<T>(path: string, token: ReturnType<typeof context.capture>, generation: number): Promise<T[]> {
    const rows: T[] = []
    let page = 1
    let lastPage = 1
    while (page <= lastPage) {
      const query = new URLSearchParams({ status: 'active', per_page: '100', page: String(page) })
      const result = await api<ErpStockPage<T>>(`${path}?${query}`)
      if (!current(token, generation)) return []
      rows.push(...result.data)
      lastPage = result.meta?.last_page ?? page
      page++
    }
    return rows
  }
  async function loadReferences() {
    if (!currentCompany.value) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++referenceSequence
    referencesLoading.value = true
    referencesError.value = ''
    const settled = await Promise.allSettled([
      allowed('erp.establishments.index') ? loadAllPages<ErpStockEstablishment>('/erp/establishments', token, generation) : Promise.resolve([]),
      allowed('erp.products.index') ? loadAllPages<Record<string, unknown>>('/erp/products', token, generation) : Promise.resolve([])
    ])
    if (!current(token, generation) || sequence !== referenceSequence) return
    const failures = settled.flatMap(result => result.status === 'rejected' ? [result.reason] : [])
    if (settled[0].status === 'fulfilled') establishments.value = settled[0].value.filter(row => row.status === 'active')
    if (settled[1].status === 'fulfilled') variants.value = (settled[1].value as Record<string, unknown>[]).flatMap((product) => {
      if (product.status !== 'active' || product.kind !== 'goods' || product.track_stock !== true || !Array.isArray(product.variants)) return []
      const productId = Number(product.id)
      if (!Number.isSafeInteger(productId) || productId < 1) return []
      return product.variants.filter((variant: Record<string, unknown>) => variant.status === 'active' && Number.isSafeInteger(Number(variant.id)) && Number(variant.id) > 0).map((variant: Record<string, unknown>) => ({
        id: Number(variant.id), name: String(variant.name ?? product.name), sku: typeof variant.sku === 'string' ? variant.sku : null, status: 'active' as const,
        product: { id: productId, name: String(product.name ?? ''), kind: 'goods', track_stock: true }
      }))
    })
    if (failures.length > 0) {
      referencesError.value = errorMessage(failures[0], 'Algumas referências não puderam ser carregadas. Atualize-as antes de salvar.')
      const deniedEstablishments = settled[0].status === 'rejected' && isAuthorityDenial(settled[0].reason) ? settled[0].reason : null
      const deniedProducts = settled[1].status === 'rejected' && isAuthorityDenial(settled[1].reason) ? settled[1].reason : null
      if (deniedEstablishments) await handleDenial('erp.establishments.index', deniedEstablishments, token)
      else if (deniedProducts) await handleDenial('erp.products.index', deniedProducts, token)
    }
    if (current(token, generation) && sequence === referenceSequence) referencesLoading.value = false
  }
  function invalidateDetailSelection() {
    selectionIntentSequence++
    detailSequence++
    detail.value = null
    detailError.value = ''
    detailLoading.value = false
    detailOpen.value = false
  }
  async function readDetailForIntent(kind: DetailSelection['kind'], id: number, intent: number) {
    if (intent !== selectionIntentSequence) return null
    const action = kind === 'movement' ? 'erp.stock.movements.show' : kind === 'adjustment' ? 'erp.stock.adjustments.show' : 'erp.stock.transfers.show'
    if (!Number.isSafeInteger(id) || id < 1 || !allowed(action)) return null
    if (intent !== selectionIntentSequence) return null
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++detailSequence
    detailLoading.value = true
    detailError.value = ''
    try {
      const path = kind === 'movement' ? `/erp/stock/movements/${id}` : kind === 'adjustment' ? `/erp/stock/adjustments/${id}` : `/erp/stock/transfers/${id}`
      const result = await api<{ data: unknown }>(path)
      if (!current(token, generation) || sequence !== detailSequence || intent !== selectionIntentSequence) return null
      detail.value = { kind, row: result.data } as DetailSelection
      detailOpen.value = true
      return result.data
    } catch (error) {
      if (!current(token, generation) || sequence !== detailSequence || intent !== selectionIntentSequence) return null
      detailError.value = errorMessage(error, 'Não foi possível carregar o detalhe do movimento.')
      await handleDenial(action, error, token)
      return null
    } finally {
      if (current(token, generation) && sequence === detailSequence && intent === selectionIntentSequence) detailLoading.value = false
    }
  }
  async function inspectMovement(id: number) {
    const intent = ++selectionIntentSequence
    detail.value = null
    return readDetailForIntent('movement', id, intent)
  }
  async function inspectAdjustment(id: number) {
    const intent = ++selectionIntentSequence
    detail.value = null
    return readDetailForIntent('adjustment', id, intent)
  }
  async function inspectTransfer(id: number) {
    const intent = ++selectionIntentSequence
    detail.value = null
    return readDetailForIntent('transfer', id, intent)
  }
  function closeDetail() {
    invalidateDetailSelection()
  }
  function beginManualIntent() {
    invalidateDetailSelection()
  }
  async function refreshCurrentDetail(intent = selectionIntentSequence, expected = detail.value) {
    if (intent !== selectionIntentSequence || !expected || detail.value !== expected) return
    const kind = expected.kind
    const id = expected.row.id
    await readDetailForIntent(kind, id, intent)
  }
  async function refreshListsAfterOperation(token: ReturnType<typeof context.capture>, generation: number, intent: number, selectedAtStart: DetailSelection | null) {
    await Promise.all((Object.keys(readActions) as StockArea[]).filter(area => allowed(readActions[area])).map(area => load(area, metas[area]?.current_page ?? 1)))
    if (!current(token, generation)) return
    if (intent === selectionIntentSequence && selectedAtStart && detail.value === selectedAtStart)
      await refreshCurrentDetail(intent, selectedAtStart)
  }
  function makeOperation(action: StockOperationName, path: string, payload: Record<string, unknown>) {
    if (!user.value || !currentCompany.value) return null
    if (selectedOperation.value) {
      needsReconciliation.value = true
      mutationError.value = 'Há uma operação sem resultado confirmado. Consulte ou repita a mesma operação antes de iniciar outra.'
      return null
    }
    const operation = createPendingOperation(operationIdentity(action), action, path, payload, crypto.randomUUID())
    if (!storePending(operation)) return null
    selectedOperation.value = operation
    operationIntentSequence.value++
    needsReconciliation.value = false
    canRetryExact.value = false
    resolvedOperation.value = null
    return operation
  }
  async function executeOperation(action: StockOperationName, path: string, payload: Record<string, unknown>, existing: StockOperation | null = null) {
    mutationError.value = ''
    fieldErrors.value = {}
    canRetryExact.value = false
    if (mutating.value) return null
    if (!allowed(action)) {
      mutationError.value = 'Seu acesso não permite esta operação.'
      return null
    }
    if (!selectedOperation.value && !existing) restorePending()
    const pending = existing ?? makeOperation(action, path, payload)
    if (!pending || pending.identity !== operationIdentity(action) || pending.operation !== action) return null
    if (existing && selectedOperation.value?.key !== existing.key) return null
    const token = context.capture()
    const generation = requestGeneration
    const opSequence = ++operationSequence
    const selectionAtStart = detail.value
    const intentAtStart = selectionIntentSequence
    mutating.value = true
    try {
      await refreshCsrf()
      if (!current(token, generation) || opSequence !== operationSequence) return null
      const result = await api<{ data: unknown }>(pending.path, { method: 'POST', body: pending.payload, headers: { 'Idempotency-Key': pending.key } })
      if (!current(token, generation) || opSequence !== operationSequence) return null
      const cleared = storePending(null)
      if (cleared) selectedOperation.value = null
      needsReconciliation.value = !cleared
      mutationError.value = cleared ? 'Operação confirmada.' : 'Operação confirmada, mas a identificação local ainda não pôde ser removida.'
      resolvedOperation.value = cleared ? { key: pending.key, operation: pending.operation, outcome: result.data } : null
      canRetryExact.value = false
      if (cleared && detail.value === selectionAtStart && selectionIntentSequence === intentAtStart) {
        if (action === 'erp.stock.movements.reverse' && detail.value?.kind === 'movement') {
          const refreshed = await readDetailForIntent('movement', detail.value.row.id, intentAtStart)
          if (refreshed && (refreshed as ErpStockMovementDetail).reversal.remaining_quantity === '0.0000') detailOpen.value = true
        } else if (action === 'erp.stock.transfers.reverse' && detail.value?.kind === 'transfer') {
          await readDetailForIntent('transfer', detail.value.row.id, intentAtStart)
        } else if (action === 'erp.stock.adjustments.store' || action === 'erp.stock.transfers.store') {
          detailOpen.value = false
        }
      }
      if (!current(token, generation) || opSequence !== operationSequence) return result.data
      await refreshListsAfterOperation(token, generation, intentAtStart, selectionAtStart)
      return result.data
    } catch (error) {
      if (!current(token, generation) || opSequence !== operationSequence) return null
      fieldErrors.value = apiFieldErrors(error)
      const status = apiStatus(error)
      if (await handleDenial(action, error, token, true)) return null
      if (status === 422) {
        const cleared = storePending(null)
        if (cleared) selectedOperation.value = null
        needsReconciliation.value = !cleared
        mutationError.value = errorMessage(error, 'Revise os campos indicados antes de tentar novamente.')
      } else {
        needsReconciliation.value = true
        mutationError.value = status === 409
          ? errorMessage(error, 'A operação exige conciliação. Consulte o registro do servidor antes de tentar novamente.')
          : 'Não foi possível confirmar o resultado. Consulte a operação antes de repetir.'
      }
      return null
    } finally {
      if (current(token, generation) && opSequence === operationSequence) mutating.value = false
    }
  }
  async function postAdjustment(input: Parameters<typeof buildErpStockAdjustmentPayload>[0]) {
    if (!allowed('erp.stock.adjustments.store')) return null
    try {
      const payload = buildErpStockAdjustmentPayload(input)
      return executeOperation('erp.stock.adjustments.store', '/erp/stock/adjustments', payload)
    } catch (error) {
      mutationError.value = error instanceof Error ? error.message : 'Revise a quantidade e o motivo.'
      return null
    }
  }
  async function postTransfer(input: Parameters<typeof buildErpStockTransferPayload>[0]) {
    if (!allowed('erp.stock.transfers.store')) return null
    try {
      const payload = buildErpStockTransferPayload(input)
      return executeOperation('erp.stock.transfers.store', '/erp/stock/transfers', payload)
    } catch (error) {
      mutationError.value = error instanceof Error ? error.message : 'Revise os estabelecimentos e a quantidade.'
      return null
    }
  }
  async function reverseMovement(id: number, input: Parameters<typeof buildErpStockReversalPayload>[0]) {
    if (!allowed('erp.stock.movements.reverse')) return null
    try {
      const payload = buildErpStockReversalPayload(input)
      return executeOperation('erp.stock.movements.reverse', `/erp/stock/movements/${id}/reverse`, payload)
    } catch (error) {
      mutationError.value = error instanceof Error ? error.message : 'Revise a quantidade e o motivo da correção.'
      return null
    }
  }
  async function reverseTransfer(id: number, input: Parameters<typeof buildErpStockReversalPayload>[0]) {
    if (!allowed('erp.stock.transfers.reverse')) return null
    try {
      const payload = buildErpStockReversalPayload(input)
      return executeOperation('erp.stock.transfers.reverse', `/erp/stock/transfers/${id}/reverse`, payload)
    } catch (error) {
      mutationError.value = error instanceof Error ? error.message : 'Revise a quantidade e o motivo da correção.'
      return null
    }
  }
  async function reconcile() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || pending.identity !== operationIdentity(pending.operation) || mutating.value) return
    if (!allowed(pending.operation)) return
    selectedOperation.value = pending
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++operationSequence
    const detailAtStart = detail.value
    const intentAtStart = selectionIntentSequence
    mutating.value = true
    canRetryExact.value = false
    try {
      const lookup = pendingOperationLookup(pending)
      const result = await api<{ data: { state: string, result?: unknown } }>(lookup.path, { headers: lookup.headers })
      if (!current(token, generation) || sequence !== operationSequence) return
      if (result.data.state !== 'completed') {
        needsReconciliation.value = true
        mutationError.value = 'A consulta ainda não confirmou o resultado. Mantenha a mesma identificação e consulte novamente.'
        return
      }
      const outcome = result.data.result ?? null
      const cleared = storePending(null)
      if (cleared) selectedOperation.value = null
      needsReconciliation.value = !cleared
      mutationError.value = cleared ? 'Operação confirmada pelo registro do servidor.' : 'Operação confirmada; a cópia local continua guardada.'
      resolvedOperation.value = cleared ? { key: pending.key, operation: pending.operation, outcome } : null
      if (intentAtStart === selectionIntentSequence && detail.value === detailAtStart)
        await refreshCurrentDetail(intentAtStart, detailAtStart)
      if (!current(token, generation) || sequence !== operationSequence) return
      await refreshListsAfterOperation(token, generation, intentAtStart, detailAtStart)
    } catch (error) {
      if (!current(token, generation) || sequence !== operationSequence) return
      if (apiStatus(error) === 404) {
        canRetryExact.value = true
        needsReconciliation.value = true
        mutationError.value = 'Ainda sem registro no servidor. Você pode repetir o mesmo conteúdo com a mesma identificação.'
      } else {
        mutationError.value = errorMessage(error, 'Não foi possível consultar a operação.')
        await handleDenial(pending.operation, error, token, true)
      }
    } finally {
      if (current(token, generation) && sequence === operationSequence) mutating.value = false
    }
  }
  async function retryExact() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || !canRetryExact.value) return null
    return executeOperation(pending.operation as StockOperationName, pending.path, pending.payload, pending)
  }
  function clearOperationNotice() {
    if (selectedOperation.value) return
    mutationError.value = ''
    fieldErrors.value = {}
    resolvedOperation.value = null
  }
  function setPage(area: StockArea, page: number) {
    const maximum = metas[area]?.last_page ?? 1
    if (!Number.isSafeInteger(page) || page < 1 || page > Math.max(1, maximum)) return
    filters.page = page
    void load(area, page)
  }
  async function selectArea(area: StockArea) {
    beginManualIntent()
    filters.page = 1
    await load(area, 1)
  }
  function openRecoveryDetail() {
    const pending = selectedOperation.value
    if (!pending) return
    const movement = pending.path.match(/^\/erp\/stock\/movements\/(\d+)\/reverse$/)
    const transfer = pending.path.match(/^\/erp\/stock\/transfers\/(\d+)\/reverse$/)
    if (movement?.[1] && allowed('erp.stock.movements.show')) void readDetailForIntent('movement', Number(movement[1]), selectionIntentSequence)
    else if (transfer?.[1] && allowed('erp.stock.transfers.show')) void readDetailForIntent('transfer', Number(transfer[1]), selectionIntentSequence)
  }
  watch(context.key, () => {
    clearPrivateState()
    restorePending()
    if (currentCompany.value) {
      void loadReferences()
    }
  }, { flush: 'sync' })
  onMounted(() => {
    restorePending()
    void loadReferences()
    const pending = selectedOperation.value
    const movement = pending?.path.match(/^\/erp\/stock\/movements\/(\d+)\/reverse$/)
    const transfer = pending?.path.match(/^\/erp\/stock\/transfers\/(\d+)\/reverse$/)
    if (movement?.[1] && allowed('erp.stock.movements.show')) void readDetailForIntent('movement', Number(movement[1]), selectionIntentSequence)
    else if (transfer?.[1] && allowed('erp.stock.transfers.show')) void readDetailForIntent('transfer', Number(transfer[1]), selectionIntentSequence)
  })
  onScopeDispose(() => {
    disposed = true
    clearPrivateState()
  })

  return {
    balances, movements, lots, adjustments, transfers, metas, stale, loading, listErrors,
    establishments, variants, referencesLoading, referencesError, detail, detailLoading, detailError, detailOpen,
    mutationError, fieldErrors, mutating, selectedOperation, needsReconciliation, canRetryExact, resolvedOperation,
    authorityGeneration, operationIntentSequence, filters, allowed, load, loadReferences, setPage, selectArea,
    inspectMovement, inspectAdjustment, inspectTransfer, closeDetail, beginManualIntent, refreshCurrentDetail, openRecoveryDetail,
    postAdjustment, postTransfer, reverseMovement, reverseTransfer, reconcile, retryExact, clearOperationNotice
  }
}
