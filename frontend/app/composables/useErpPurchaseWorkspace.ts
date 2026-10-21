import type {
  ErpPurchase,
  ErpPurchaseEstablishment,
  ErpPurchasePage,
  ErpPurchaseReturn,
  ErpPurchaseSupplier,
  ErpPurchaseUnit,
  ErpPurchaseVariant,
  ErpPurchaseXmlPreview,
  ErpPurchaseXmlMapping
} from '#shared/types/erpBusiness'
import { apiErrorMessage, apiFieldErrors, apiMessage, apiStatus } from '~/composables/useApiError'
import { buildPurchaseReturnPayload, buildXmlConfirmationItems, type ErpPurchaseReturnSource } from '#shared/utils/erpPurchase'
import { createPendingOperation, pendingOperationLookup, readPendingOperation, writePendingOperation, type ErpPendingOperation } from '#shared/utils/erpOperationRecovery'

type PurchaseOperation = ErpPendingOperation & { stale_revision_rejection?: number }
type PageResult<T> = { data: T[], meta?: { current_page: number, last_page: number, per_page: number, total: number } }
type PurchaseAction = string

const operationRoutes: Record<string, string | RegExp> = {
  'erp.purchases.store': '/erp/purchases',
  'erp.purchases.update': /^\/erp\/purchases\/\d+$/,
  'erp.purchases.post': /^\/erp\/purchases\/\d+\/post$/,
  'erp.purchases.xml-confirm': '/erp/purchases/xml-confirm',
  'erp.purchase-returns.store': '/erp/purchase-returns',
  'erp.purchase-returns.post': /^\/erp\/purchase-returns\/\d+\/post$/
}
const writeActions = new Set<PurchaseAction>([
  'erp.purchases.store', 'erp.purchases.update', 'erp.purchases.post', 'erp.purchases.xml-preview', 'erp.purchases.xml-preview.read', 'erp.purchases.xml-confirm',
  'erp.purchase-returns.store', 'erp.purchase-returns.post'
])
export interface ErpPurchasePreviewState {
  preview: ErpPurchaseXmlPreview
  erp_establishment_id: number
  erp_contact_id: number | null
  mappings: ErpPurchaseXmlMapping[]
}

export function erpPurchaseAllowed(grants: readonly string[] | undefined, role: string | undefined, action: PurchaseAction): boolean {
  if (!grants?.length) return false
  const write = writeActions.has(action)
  if (write && role === 'viewer') return false
  const separator = action.lastIndexOf('.')
  if (separator <= 0 || separator === action.length - 1) return false
  const family = action.slice(0, separator)
  const scope = write ? 'write' : 'read'
  return grants.some(grant => grant === '*' || grant === action || grant === `${family}.*` || grant === `${family}.${scope}` || grant === (write ? 'erp.write' : 'erp.read'))
}

export function useErpPurchaseWorkspace() {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany, refreshWithStatus } = useAuth()
  const context = useErpAdministrationContext()
  const purchases = ref<ErpPurchase[]>([])
  const returns = ref<ErpPurchaseReturn[]>([])
  const meta = ref<ErpPurchasePage['meta'] | null>(null)
  const returnMeta = ref<PageResult<ErpPurchaseReturn>['meta'] | null>(null)
  const suppliers = ref<ErpPurchaseSupplier[]>([])
  const establishments = ref<ErpPurchaseEstablishment[]>([])
  const variants = ref<ErpPurchaseVariant[]>([])
  const units = ref<ErpPurchaseUnit[]>([])
  const selected = ref<ErpPurchase | null>(null)
  const selectedReturn = ref<ErpPurchaseReturn | null>(null)
  const previewState = ref<ErpPurchasePreviewState | null>(null)
  const listLoading = ref(false)
  const returnsLoading = ref(false)
  const referencesLoading = ref(false)
  const mutating = ref(false)
  const loadingDetail = ref(false)
  const pageError = ref('')
  const referenceError = ref('')
  const mutationError = ref('')
  const detailError = ref('')
  const fieldErrors = ref<Record<string, string>>({})
  const selectedOperation = ref<PurchaseOperation | null>(null)
  const needsReconciliation = ref(false)
  const canRetryExact = ref(false)
  const staleRevisionRefreshed = ref<{ id: number, revision: number | null, status: ErpPurchase['status'] } | null>(null)
  const resolvedOperation = ref<{ key: string, operation: string, outcome: unknown } | null>(null)
  const confirmedPostedPurchases = ref(new Set<number>())
  const authorityGeneration = ref(0)
  const deniedActions = ref(new Set<string>())
  const authorityNeedsRefresh = ref(false)
  const filters = reactive({ status: '', search: '', page: 1, per_page: 15 })
  const returnFilters = reactive({ status: '', page: 1, per_page: 15 })
  let requestGeneration = 0
  let listSequence = 0
  let returnListSequence = 0
  let detailSequence = 0
  let selectionIntentSequence = 0
  let returnSequence = 0
  let previewSequence = 0
  let authorityRefreshEpoch = 0
  const grants = computed(() => currentCompany.value?.permissions ?? [])
  const role = computed(() => currentCompany.value?.role)
  const storageKey = computed(() => `erp-purchases:v1:${user.value?.id ?? 'anonymous'}:${currentCompany.value?.id ?? 'none'}`)
  const previewStorageKey = computed(() => `${storageKey.value}:xml-preview`)

  function allowed(action: PurchaseAction) {
    return !authorityNeedsRefresh.value && !deniedActions.value.has(action) && erpPurchaseAllowed(grants.value, role.value, action)
  }
  function current(token: ReturnType<typeof context.capture>, generation: number) {
    return requestGeneration === generation && context.isCurrent(token)
  }
  function operationIdentity(operation: string) {
    return `${user.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${operation}`
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
    listSequence++
    returnListSequence++
    detailSequence++
    selectionIntentSequence++
    returnSequence++
    previewSequence++
    authorityGeneration.value++
    purchases.value = []
    returns.value = []
    meta.value = null
    returnMeta.value = null
    suppliers.value = []
    establishments.value = []
    variants.value = []
    units.value = []
    selected.value = null
    selectedReturn.value = null
    previewState.value = null
    listLoading.value = false
    returnsLoading.value = false
    referencesLoading.value = false
    loadingDetail.value = false
    pageError.value = ''
    referenceError.value = ''
    mutationError.value = ''
    detailError.value = ''
    fieldErrors.value = {}
    mutating.value = false
    selectedOperation.value = null
    needsReconciliation.value = false
    canRetryExact.value = false
    staleRevisionRefreshed.value = null
    resolvedOperation.value = null
    confirmedPostedPurchases.value = new Set()
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
  function restorePending() {
    const pending = readPending()
    if (!pending) return
    selectedOperation.value = pending
    needsReconciliation.value = true
    mutationError.value = 'Existe uma operação de compra sem resultado confirmado. Consulte ou repita a mesma operação antes de iniciar outra.'
  }
  function storePending(operation: PurchaseOperation | null) {
    if (typeof sessionStorage === 'undefined') {
      mutationError.value = 'O navegador não permite guardar a identificação da operação. Ative o armazenamento da sessão e tente novamente.'
      return false
    }
    try {
      writePendingOperation(sessionStorage, storageKey.value, operation)
      selectedOperation.value = operation
      return true
    } catch {
      mutationError.value = 'Não foi possível guardar a identificação e os dados da operação nesta sessão.'
      return false
    }
  }
  function persistPreview() {
    if (typeof sessionStorage === 'undefined') return
    try {
      if (previewState.value) {
        if (previewState.value.preview.det.length > 500 || previewState.value.mappings.length > 500) {
          sessionStorage.removeItem(previewStorageKey.value)
          return
        }
        const serialized = JSON.stringify(previewState.value)
        if (serialized.length > 262144) {
          sessionStorage.removeItem(previewStorageKey.value)
          mutationError.value = 'O mapeamento XML excede o limite de armazenamento da sessão; mantenha esta aba aberta.'
          return
        }
        sessionStorage.setItem(previewStorageKey.value, serialized)
      } else sessionStorage.removeItem(previewStorageKey.value)
    } catch {
      mutationError.value = 'Não foi possível guardar a prévia XML nesta sessão. Mantenha esta aba aberta para preservar o mapeamento.'
    }
  }
  watch(previewState, persistPreview, { deep: true })
  function restorePreview() {
    if (typeof sessionStorage === 'undefined') return
    if (!allowed('erp.purchases.xml-preview.read')) {
      sessionStorage.removeItem(previewStorageKey.value)
      return
    }
    try {
      const value = sessionStorage.getItem(previewStorageKey.value)
      if (!value) return
      const state = JSON.parse(value) as ErpPurchasePreviewState
      const preview = state.preview
      const plainRecord = (record: unknown): record is Record<string, unknown> => typeof record === 'object' && record !== null && !Array.isArray(record)
      const validLines = Array.isArray(preview?.det) && preview.det.every((line, index) =>
        Number.isSafeInteger(line.line) && line.line === index + 1
        && ['codigo', 'xProd', 'qCom', 'uCom', 'vUnCom'].every(key => typeof line[key as keyof typeof line] === 'string')
        && (line.amounts === undefined || (plainRecord(line.amounts) && typeof line.amounts.line_total === 'string')))
      const validMappings = Array.isArray(state.mappings) && validLines && state.mappings.length === preview.det.length
        && state.mappings.every((mapping, index) => Number.isSafeInteger(mapping.line) && mapping.line === index + 1
          && Number.isSafeInteger(mapping.erp_product_variant_id) && typeof mapping.unit_conversion_factor === 'string'
          && /^\d{1,10}(?:\.\d{1,8})?$/.test(mapping.unit_conversion_factor)
          && (mapping.erp_unit_id === undefined || (Number.isSafeInteger(mapping.erp_unit_id) && mapping.erp_unit_id > 0)))
      const validTotals = plainRecord(preview?.total) && Object.values(preview.total).every(value => value === null || typeof value === 'string')
      const validErrors = Array.isArray(preview?.errors) && preview.errors.every(issue => plainRecord(issue)
        && (issue.code === undefined || typeof issue.code === 'string')
        && (issue.message === undefined || typeof issue.message === 'string')
        && (issue.line === undefined || Number.isSafeInteger(issue.line)))
      if (typeof preview?.token === 'string' && /^[0-9a-f-]{36}$/i.test(preview.token)
        && validLines && validMappings && validTotals && validErrors
        && typeof preview.chave === 'string' && plainRecord(preview.emit) && plainRecord(preview.dest)
        && typeof preview.emit.name === 'string' && typeof preview.emit.cpf_cnpj === 'string'
        && typeof preview.dest.name === 'string' && typeof preview.dest.cpf_cnpj === 'string'
        && typeof preview.duplicate === 'boolean'
        && (preview.duplicate_purchase_id === null || Number.isSafeInteger(preview.duplicate_purchase_id))
        && typeof preview.expires_at === 'string'
        && Number.isSafeInteger(state.erp_establishment_id) && state.erp_establishment_id > 0
        && (state.erp_contact_id === null || Number.isSafeInteger(state.erp_contact_id))
        && currentCompany.value && ['pending', 'blocked', 'expired', 'completed'].includes(preview.state)) {
        previewState.value = {
          preview: {
            token: preview.token,
            chave: String(preview.chave ?? ''),
            emit: { name: String(preview.emit?.name ?? ''), cpf_cnpj: String(preview.emit?.cpf_cnpj ?? '') },
            dest: { name: String(preview.dest?.name ?? ''), cpf_cnpj: String(preview.dest?.cpf_cnpj ?? '') },
            det: preview.det.map(line => ({ line: line.line, codigo: line.codigo, xProd: line.xProd, qCom: line.qCom, uCom: line.uCom, vUnCom: line.vUnCom, amounts: line.amounts })),
            total: { ...preview.total },
            duplicatas: [],
            errors: preview.errors.map(issue => ({ code: issue.code, message: issue.message, line: issue.line })),
            duplicate: preview.duplicate,
            duplicate_purchase_id: preview.duplicate_purchase_id,
            expires_at: preview.expires_at,
            state: preview.state
          },
          erp_establishment_id: state.erp_establishment_id,
          erp_contact_id: state.erp_contact_id,
          mappings: state.mappings.map(mapping => ({ ...mapping }))
        }
      } else sessionStorage.removeItem(previewStorageKey.value)
    } catch {
      sessionStorage.removeItem(previewStorageKey.value)
    }
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
    previewSequence++
    if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(previewStorageKey.value)
    if (status === 401 || status === 409 || (status === 403 && refresh)) authorityNeedsRefresh.value = true
    clearPrivateState()
    restorePending()
    mutationError.value = errorMessage(error, 'Sua autorização mudou. Os dados privados de compras foram removidos.')
    if (status === 401 || status === 409 || (status === 403 && refresh)) await refreshAuth(token, epoch)
    return true
  }
  function makeQuery() {
    const query = new URLSearchParams({ page: String(filters.page), per_page: String(filters.per_page) })
    if (filters.status) query.set('status', filters.status)
    if (filters.search.trim()) query.set('search', filters.search.trim())
    return query.toString()
  }
  async function load() {
    if (!allowed('erp.purchases.index') || !currentCompany.value) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++listSequence
    listLoading.value = true
    pageError.value = ''
    try {
      const result = await api<ErpPurchasePage>(`/erp/purchases?${makeQuery()}`)
      if (!current(token, generation) || sequence !== listSequence) return
      purchases.value = result.data
      meta.value = result.meta
    } catch (error) {
      if (!current(token, generation) || sequence !== listSequence) return
      pageError.value = errorMessage(error, 'Não foi possível carregar as compras.')
      await handleDenial('erp.purchases.index', error, token)
    } finally {
      if (current(token, generation) && sequence === listSequence) listLoading.value = false
    }
  }
  async function loadReturns() {
    if (!allowed('erp.purchase-returns.index') || !currentCompany.value) return
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++returnListSequence
    const query = new URLSearchParams({ page: String(returnFilters.page), per_page: String(returnFilters.per_page) })
    if (returnFilters.status) query.set('status', returnFilters.status)
    returnsLoading.value = true
    pageError.value = ''
    try {
      const result = await api<ErpPurchasePage>(`/erp/purchase-returns?${query}`)
      if (!current(token, generation) || sequence !== returnListSequence) return
      returns.value = result.data as unknown as ErpPurchaseReturn[]
      returnMeta.value = result.meta
    } catch (error) {
      if (!current(token, generation) || sequence !== returnListSequence) return
      pageError.value = errorMessage(error, 'Não foi possível carregar as devoluções de fornecedor.')
      await handleDenial('erp.purchase-returns.index', error, token)
    } finally {
      if (current(token, generation) && sequence === returnListSequence) returnsLoading.value = false
    }
  }
  async function loadAllPages<T>(path: string, token: ReturnType<typeof context.capture>, generation: number): Promise<T[]> {
    const rows: T[] = []
    let page = 1
    let lastPage = 1
    while (page <= lastPage) {
      const query = new URLSearchParams({ status: 'active', per_page: '100', page: String(page) })
      const result = await api<PageResult<T>>(`${path}?${query}`)
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
    referencesLoading.value = true
    referenceError.value = ''
    const settled = await Promise.allSettled([
      allowed('erp.contacts.index') ? loadAllPages<Record<string, unknown>>('/erp/contacts', token, generation) : Promise.resolve([]),
      allowed('erp.establishments.index') ? loadAllPages<ErpPurchaseEstablishment>('/erp/establishments', token, generation) : Promise.resolve([]),
      allowed('erp.products.index') ? loadAllPages<Record<string, unknown>>('/erp/products', token, generation) : Promise.resolve([]),
      allowed('erp.units.index') ? loadAllPages<ErpPurchaseUnit>('/erp/units', token, generation) : Promise.resolve([])
    ])
    if (!current(token, generation)) return
    const readActions = ['erp.contacts.index', 'erp.establishments.index', 'erp.products.index', 'erp.units.index'] as const
    const failures = settled.flatMap((result, index) => result.status === 'rejected' ? [{ error: result.reason, action: readActions[index] ?? readActions[0] }] : [])
    if (settled[0].status === 'fulfilled') suppliers.value = (settled[0].value as Record<string, unknown>[]).filter(row => row.is_supplier === true && row.status === 'active') as unknown as ErpPurchaseSupplier[]
    if (settled[1].status === 'fulfilled') establishments.value = settled[1].value as ErpPurchaseEstablishment[]
    if (settled[2].status === 'fulfilled') variants.value = (settled[2].value as Record<string, unknown>[]).flatMap((product) => {
      if (product.status !== 'active' || product.kind !== 'goods' || product.track_stock !== true || !Array.isArray(product.variants)) return []
      const productId = Number(product.id)
      if (!Number.isSafeInteger(productId) || productId < 1) return []
      return product.variants.filter((variant: Record<string, unknown>) => variant.status === 'active' && Number.isSafeInteger(Number(variant.id)) && Number(variant.id) > 0).map((variant: Record<string, unknown>) => ({
        id: Number(variant.id),
        name: String(variant.name ?? product.name),
        sku: typeof variant.sku === 'string' ? variant.sku : null,
        status: 'active' as const,
        product: { id: productId, name: String(product.name ?? ''), kind: String(product.kind), track_stock: true, erp_unit_id: Number(product.erp_unit_id) }
      }))
    })
    if (settled[3].status === 'fulfilled') units.value = settled[3].value as ErpPurchaseUnit[]
    referencesLoading.value = false
    if (failures.length > 0) {
      const denied = failures.find(failure => isAuthorityDenial(failure.error))
      if (denied) await handleDenial(denied.action, denied.error, token)
      referenceError.value = errorMessage(failures[0]?.error, 'Algumas referências não puderam ser carregadas. Atualize-as antes de salvar.')
    }
  }
  async function inspect(id: number, showError = true) {
    if (!Number.isSafeInteger(id) || !allowed('erp.purchases.show')) return null
    const token = context.capture()
    const generation = requestGeneration
    const selectionIntent = ++selectionIntentSequence
    const sequence = ++detailSequence
    loadingDetail.value = true
    detailError.value = ''
    try {
      const result = await api<{ data: ErpPurchase }>(`/erp/purchases/${id}`)
      if (!current(token, generation) || sequence !== detailSequence || selectionIntent !== selectionIntentSequence) return null
      selected.value = result.data
      return result.data
    } catch (error) {
      if (!current(token, generation) || sequence !== detailSequence || selectionIntent !== selectionIntentSequence) return null
      detailError.value = errorMessage(error, 'Não foi possível carregar o detalhe atual da compra.')
      if (showError) mutationError.value = detailError.value
      await handleDenial('erp.purchases.show', error, token)
      return null
    } finally {
      if (current(token, generation) && sequence === detailSequence) loadingDetail.value = false
    }
  }
  function closeDetail() {
    detailSequence++
    selectionIntentSequence++
    returnSequence++
    selected.value = null
    selectedReturn.value = null
    detailError.value = ''
    loadingDetail.value = false
  }
  async function refreshCurrent(sourceId?: number | null, expectedDetailSequence = detailSequence, expectedSelectionId = selected.value?.id ?? null, expectedSelectionIntent = selectionIntentSequence) {
    await load()
    if (detailSequence !== expectedDetailSequence || selectionIntentSequence !== expectedSelectionIntent
      || (selected.value?.id ?? null) !== expectedSelectionId) return
    const id = sourceId ?? expectedSelectionId
    if (id && allowed('erp.purchases.show')) await inspect(id, false)
  }
  function outcomePurchaseId(operation: PurchaseOperation, outcome: unknown): number | null {
    const payload = operation.payload
    if (typeof payload.erp_purchase_id === 'number') return payload.erp_purchase_id
    const record = typeof outcome === 'object' && outcome !== null ? outcome as { id?: unknown, erp_purchase_id?: unknown } : {}
    if (typeof record.erp_purchase_id === 'number') return record.erp_purchase_id
    if (operation.operation === 'erp.purchases.store' || operation.operation === 'erp.purchases.update' || operation.operation === 'erp.purchases.post' || operation.operation === 'erp.purchases.xml-confirm') {
      const routeId = operation.path.match(/^\/erp\/purchases\/(\d+)/)?.[1]
      const id = Number(record.id ?? routeId)
      return Number.isSafeInteger(id) && id > 0 ? id : null
    }
    return selected.value?.id ?? null
  }
  function rememberConfirmedPost(action: string, path: string) {
    if (action !== 'erp.purchases.post') return
    const purchaseId = Number(path.match(/^\/erp\/purchases\/(\d+)\/post$/)?.[1])
    if (Number.isSafeInteger(purchaseId) && purchaseId > 0)
      confirmedPostedPurchases.value = new Set([...confirmedPostedPurchases.value, purchaseId])
  }
  function isPurchaseConfirmedPosted(purchaseId: number) {
    return confirmedPostedPurchases.value.has(purchaseId)
  }
  async function executeOperation(action: PurchaseAction, path: string, payload: Record<string, unknown>, existing?: PurchaseOperation) {
    mutationError.value = ''
    fieldErrors.value = {}
    canRetryExact.value = false
    if (mutating.value || !currentCompany.value || !user.value) return null
    if (!allowed(action)) {
      mutationError.value = 'Seu acesso não permite esta operação.'
      return null
    }
    if (!selectedOperation.value && !existing) restorePending()
    if (selectedOperation.value && !existing) {
      needsReconciliation.value = true
      mutationError.value = 'Há uma operação sem resultado confirmado. Consulte ou repita a mesma operação antes de iniciar outra.'
      return null
    }
    const frozen = existing ?? createPendingOperation(operationIdentity(action), action, path, payload, crypto.randomUUID())
    if (frozen.identity !== operationIdentity(action)) return null
    if (!storePending(frozen)) return null
    const token = context.capture()
    const generation = requestGeneration
    const detailAtStart = detailSequence
    const selectionAtStart = selected.value?.id ?? null
    const selectionIntentAtStart = selectionIntentSequence
    mutating.value = true
    try {
      await refreshCsrf()
      if (!current(token, generation)) return null
      const result = await api<{ data: unknown }>(frozen.path, { method: action === 'erp.purchases.update' ? 'PATCH' : 'POST', body: frozen.payload, headers: { 'Idempotency-Key': frozen.key } })
      if (!current(token, generation)) return null
      const outcome = result.data
      rememberConfirmedPost(action, frozen.path)
      const cleared = storePending(null)
      needsReconciliation.value = !cleared
      mutationError.value = cleared ? 'Operação confirmada.' : 'Operação confirmada. A identificação local ainda não pôde ser removida; consulte-a antes de iniciar outra.'
      if (detailSequence === detailAtStart && (selected.value?.id ?? null) === selectionAtStart)
        await refreshCurrent(outcomePurchaseId(frozen, outcome), detailAtStart, selectionAtStart, selectionIntentAtStart)
      if (!current(token, generation)) return null
      return outcome
    } catch (error) {
      if (!current(token, generation)) return null
      fieldErrors.value = apiFieldErrors(error)
      const status = apiStatus(error)
      if (await handleDenial(action, error, token, true)) return null
      const rawFieldErrors = fieldErrors.value
      const submittedPayload = frozen.payload
      if (action === 'erp.purchase-returns.store' && Array.isArray(submittedPayload.lines)) {
        const translated = { ...rawFieldErrors }
        submittedPayload.lines.forEach((line, index) => {
          if (typeof line !== 'object' || line === null) return
          const sourceId = (line as { source_line_id?: unknown }).source_line_id
          const errorKey = `lines.${index}.quantity`
          if (Number.isSafeInteger(sourceId) && rawFieldErrors[errorKey]) translated[`lines.${sourceId}.quantity`] = rawFieldErrors[errorKey]!
        })
        fieldErrors.value = translated
      }
      if (action === 'erp.purchases.xml-confirm' && Array.isArray(submittedPayload.items)) {
        const translated = { ...rawFieldErrors }
        submittedPayload.items.forEach((item, index) => {
          if (typeof item !== 'object' || item === null) return
          const line = (item as { line?: unknown }).line
          if (!Number.isSafeInteger(line)) return
          for (const field of ['erp_product_variant_id', 'erp_unit_id', 'unit_conversion_factor']) {
            const error = rawFieldErrors[`items.${index}.${field}`]
            if (error) translated[`xml.items.${Number(line) - 1}.${field}`] = error
          }
        })
        fieldErrors.value = translated
      }
      if (status === 422) {
        const cleared = storePending(null)
        needsReconciliation.value = !cleared
        mutationError.value = errorMessage(error, 'Revise os campos indicados antes de tentar novamente.')
      } else if (status === 409) {
        const revision = payload.draft_revision
        if (!existing && (action === 'erp.purchases.update' || action === 'erp.purchases.post')
          && apiMessage(error) === 'Rascunho alterado.' && Number.isSafeInteger(revision) && Number(revision) > 0) {
          storePending({ ...frozen, stale_revision_rejection: Number(revision) })
        }
        needsReconciliation.value = true
        mutationError.value = errorMessage(error, 'O sistema exige conferência da operação e dos dados atuais antes de continuar.')
      } else {
        needsReconciliation.value = true
        mutationError.value = 'Não foi possível confirmar o resultado. Mantenha esta operação e consulte o registro do servidor antes de repetir.'
      }
      return null
    } finally {
      if (context.isCurrent(token)) mutating.value = false
    }
  }
  async function saveDraft(payload: Record<string, unknown>, id: number | null = null) {
    return executeOperation(id ? 'erp.purchases.update' : 'erp.purchases.store', id ? `/erp/purchases/${id}` : '/erp/purchases', payload)
  }
  async function postReceipt(purchase: ErpPurchase) {
    if (purchase.status !== 'draft' || isPurchaseConfirmedPosted(purchase.id)) {
      mutationError.value = 'Somente um rascunho pode ser lançado como recebimento.'
      return null
    }
    return executeOperation('erp.purchases.post', `/erp/purchases/${purchase.id}/post`, { draft_revision: purchase.draft_revision, expected_total: purchase.total_amount })
  }
  async function inspectXml(file: File, establishmentId: number) {
    mutationError.value = ''
    if (!allowed('erp.purchases.xml-preview')) {
      mutationError.value = 'Seu acesso não permite conferir XML de compra.'
      return null
    }
    if (file.size > 2_000_000) {
      mutationError.value = 'Selecione um XML de até 2 MB.'
      return null
    }
    const token = context.capture()
    const generation = requestGeneration
    const sequence = ++previewSequence
    previewState.value = null
    persistPreview()
    try {
      const xml = await file.text()
      if (!current(token, generation) || sequence !== previewSequence) return null
      const result = await api<{ data: ErpPurchaseXmlPreview }>('/erp/purchases/xml-preview', { method: 'POST', body: { erp_establishment_id: establishmentId, xml } })
      if (!current(token, generation) || sequence !== previewSequence) return null
      previewState.value = {
        preview: result.data,
        erp_establishment_id: establishmentId,
        erp_contact_id: null,
        mappings: result.data.det.map(line => ({ line: line.line, erp_product_variant_id: 0, unit_conversion_factor: '1.00000000' }))
      }
      persistPreview()
      return result.data
    } catch (error) {
      if (!current(token, generation) || sequence !== previewSequence) return null
      mutationError.value = errorMessage(error, 'Não foi possível conferir o XML. Selecione-o novamente para tentar outra prévia.')
      await handleDenial('erp.purchases.xml-preview', error, token)
      return null
    }
  }
  async function refreshXmlPreview() {
    const currentPreview = previewState.value
    if (!currentPreview || !allowed('erp.purchases.xml-preview.read')) return null
    const token = context.capture()
    const generation = requestGeneration
    const sequence = previewSequence
    try {
      const result = await api<{ data: ErpPurchaseXmlPreview }>('/erp/purchases/xml-preview/read', { method: 'POST', body: { token: currentPreview.preview.token } })
      if (!current(token, generation) || sequence !== previewSequence || previewState.value !== currentPreview) return null
      previewState.value = { ...currentPreview, preview: result.data }
      persistPreview()
      return result.data
    } catch (error) {
      if (!current(token, generation) || sequence !== previewSequence || previewState.value !== currentPreview) return null
      mutationError.value = errorMessage(error, 'Não foi possível atualizar a prévia. O token e os mapeamentos continuam guardados para nova tentativa.')
      await handleDenial('erp.purchases.xml-preview.read', error, token)
      return null
    }
  }
  function cancelXmlPreview() {
    previewSequence++
    previewState.value = null
    selectedReturn.value = null
    persistPreview()
    mutationError.value = ''
    fieldErrors.value = {}
  }
  async function confirmXml() {
    const currentPreview = previewState.value
    if (!currentPreview) return null
    const sequence = previewSequence
    try {
      const items = buildXmlConfirmationItems(currentPreview.preview.det.length, currentPreview.mappings)
      if (!currentPreview.erp_contact_id) throw new Error('Selecione o fornecedor correspondente ao emitente do XML.')
      if (currentPreview.preview.state !== 'pending' || currentPreview.preview.duplicate || currentPreview.preview.errors.length > 0)
        throw new Error('A prévia está bloqueada ou expirou. Confira o estado e os erros antes de confirmar.')
      const result = await executeOperation('erp.purchases.xml-confirm', '/erp/purchases/xml-confirm', {
        token: currentPreview.preview.token,
        erp_establishment_id: currentPreview.erp_establishment_id,
        erp_contact_id: currentPreview.erp_contact_id,
        items
      })
      if (result !== null && previewSequence === sequence && previewState.value === currentPreview) cancelXmlPreview()
      return result
    } catch (error) {
      mutationError.value = error instanceof Error ? error.message : 'Revise o mapeamento do XML.'
      return null
    }
  }
  async function createReturn(source: ErpPurchase, selectedQuantities: Record<string, string>, reason: string) {
    const token = context.capture()
    const generation = requestGeneration
    try {
      const payload = buildPurchaseReturnPayload({
        erp_purchase_id: source.id,
        currency: source.currency,
        returnability: source.returnability,
        returnable_lines: source.returnable_lines ?? []
      } as ErpPurchaseReturnSource, selectedQuantities, reason)
      const result = await executeOperation('erp.purchase-returns.store', '/erp/purchase-returns', payload)
      if (result && current(token, generation) && selected.value?.id === source.id) await inspectReturn(Number((result as ErpPurchaseReturn).id), source.id)
      return result
    } catch (error) {
      mutationError.value = error instanceof Error ? error.message : 'Revise as quantidades e o motivo da devolução.'
      return null
    }
  }
  async function inspectReturn(id: number, expectedSourceId = selected.value?.id ?? null) {
    if (!allowed('erp.purchase-returns.show')) return null
    const token = context.capture()
    const generation = requestGeneration
    const selectionIntent = ++selectionIntentSequence
    const sequence = ++returnSequence
    const currentReturn = () => current(token, generation) && returnSequence === sequence && selectionIntentSequence === selectionIntent
    try {
      const result = await api<{ data: ErpPurchaseReturn }>(`/erp/purchase-returns/${id}`)
      if (!currentReturn() || (selected.value?.id ?? null) !== expectedSourceId) return null
      const sourceId = result.data.erp_purchase_id
      if (selected.value?.id !== sourceId) {
        if (allowed('erp.purchases.show')) {
          const sourceDetailSequence = ++detailSequence
          loadingDetail.value = true
          detailError.value = ''
          try {
            let source: { data: ErpPurchase }
            try {
              source = await api<{ data: ErpPurchase }>(`/erp/purchases/${sourceId}`)
            } catch (error) {
              if (!currentReturn() || detailSequence !== sourceDetailSequence
                || (selected.value?.id ?? null) !== expectedSourceId) return null
              detailError.value = errorMessage(error, 'Não foi possível carregar a compra de origem da devolução.')
              mutationError.value = detailError.value
              await handleDenial('erp.purchases.show', error, token)
              return null
            }
            if (!currentReturn() || detailSequence !== sourceDetailSequence
              || (selected.value?.id ?? null) !== expectedSourceId || source.data.id !== sourceId) return null
            selected.value = source.data
          } finally {
            if (current(token, generation) && detailSequence === sourceDetailSequence)
              loadingDetail.value = false
          }
        } else if (!currentReturn()) {
          return null
        }
      }
      if (!currentReturn() || (selected.value && selected.value.id !== sourceId)) return null
      selectedReturn.value = result.data
      return result.data
    } catch (error) {
      if (!currentReturn()) return null
      mutationError.value = errorMessage(error, 'Não foi possível carregar a devolução atual.')
      await handleDenial('erp.purchase-returns.show', error, token)
      return null
    }
  }
  async function postReturn(returnDraft: ErpPurchaseReturn) {
    const sourceId = selected.value?.id ?? null
    const token = context.capture()
    const generation = requestGeneration
    const result = await executeOperation('erp.purchase-returns.post', `/erp/purchase-returns/${returnDraft.id}/post`, { expected_total: returnDraft.total_amount })
    if (result !== null && current(token, generation) && (selected.value?.id ?? null) === sourceId) {
      await inspectReturn(returnDraft.id, sourceId)
      await loadReturns()
    }
    return result
  }
  async function reconcile() {
    const pending = (selectedOperation.value ?? readPending()) as PurchaseOperation | null
    if (!pending || pending.identity !== operationIdentity(pending.operation) || mutating.value) return
    selectedOperation.value = pending
    resolvedOperation.value = null
    const token = context.capture()
    const generation = requestGeneration
    const detailAtStart = detailSequence
    const selectionAtStart = selected.value?.id ?? null
    const selectionIntentAtStart = selectionIntentSequence
    mutating.value = true
    canRetryExact.value = false
    try {
      const lookup = pendingOperationLookup(pending)
      const result = await api<{ data: { state: string, result?: unknown } }>(lookup.path, { headers: lookup.headers })
      if (!current(token, generation)) return
      if (result.data.state !== 'completed') {
        needsReconciliation.value = true
        mutationError.value = 'A consulta ainda não confirmou o resultado. Mantenha a mesma identificação e consulte novamente.'
        return
      }
      const outcome = result.data.result ?? null
      const sourceId = outcomePurchaseId(pending, outcome)
      rememberConfirmedPost(pending.operation, pending.path)
      const cleared = storePending(null)
      needsReconciliation.value = !cleared
      mutationError.value = cleared ? 'Operação confirmada pelo registro do servidor.' : 'Operação confirmada; a cópia local continua guardada.'
      if (detailSequence === detailAtStart && (selected.value?.id ?? null) === selectionAtStart)
        await refreshCurrent(sourceId, detailAtStart, selectionAtStart, selectionIntentAtStart)
      if (!current(token, generation)) return
      const outcomeId = typeof outcome === 'object' && outcome !== null && Number.isSafeInteger((outcome as { id?: unknown }).id)
        ? Number((outcome as { id: number }).id)
        : null
      if (pending.operation === 'erp.purchases.xml-confirm' && previewState.value?.preview.token === pending.payload.token)
        cancelXmlPreview()
      if ((pending.operation === 'erp.purchase-returns.store' || pending.operation === 'erp.purchase-returns.post')
        && outcomeId !== null && sourceId !== null && selected.value?.id === sourceId) {
        await inspectReturn(outcomeId, sourceId)
        if (current(token, generation)) await loadReturns()
      }
      if (current(token, generation)) resolvedOperation.value = { key: pending.key, operation: pending.operation, outcome }
    } catch (error) {
      if (!current(token, generation)) return
      if (apiStatus(error) === 404) {
        const record = error as { data?: { code?: unknown }, response?: { _data?: { code?: unknown } } }
        const lookupCode = record.data?.code ?? record.response?._data?.code
        if (lookupCode === 'operation_not_recorded' && Number.isSafeInteger(pending.stale_revision_rejection)
          && (pending.operation === 'erp.purchases.update' || pending.operation === 'erp.purchases.post')
          && allowed('erp.purchases.show')) {
          const purchaseId = Number(pending.path.match(/^\/erp\/purchases\/(\d+)(?:\/post)?$/)?.[1])
          if (Number.isSafeInteger(purchaseId) && purchaseId > 0) {
            try {
              const latest = await api<{ data: ErpPurchase }>('/erp/purchases/' + purchaseId)
              if (!current(token, generation) || selectedOperation.value?.key !== pending.key) return
              const rejectedWithoutEffect = latest.data.id === purchaseId && (
                latest.data.status === 'posted'
                || (latest.data.status === 'draft' && Number.isSafeInteger(latest.data.draft_revision)
                  && latest.data.draft_revision !== pending.stale_revision_rejection)
              )
              if (rejectedWithoutEffect) {
                const cleared = storePending(null)
                needsReconciliation.value = !cleared
                canRetryExact.value = false
                mutationError.value = cleared
                  ? 'O servidor rejeitou a revisão desatualizada; a compra atual foi recarregada. Revise os dados antes de tentar novamente.'
                  : 'A revisão desatualizada foi rejeitada e a compra atual foi recarregada, mas a identificação local não pôde ser removida.'
                if (detailSequence === detailAtStart && (selected.value?.id ?? null) === selectionAtStart && selected.value?.id === purchaseId)
                  selected.value = latest.data
                staleRevisionRefreshed.value = { id: purchaseId, revision: latest.data.draft_revision ?? null, status: latest.data.status }
                return
              }
              mutationError.value = 'A compra atual não confirmou uma nova revisão. Mantenha a operação guardada e consulte novamente.'
              return
            } catch (refreshError) {
              if (!current(token, generation)) return
              const denied = await handleDenial('erp.purchases.show', refreshError, token)
              if (denied || !current(token, generation)) return
              mutationError.value = errorMessage(refreshError, 'Não foi possível confirmar a revisão atual. A operação original continua guardada.')
              return
            }
          }
        }
        canRetryExact.value = true
        needsReconciliation.value = true
        mutationError.value = 'A consulta não encontrou um registro concluído. Repita apenas o mesmo conteúdo com a mesma identificação.'
      } else {
        mutationError.value = errorMessage(error, 'Não foi possível consultar a operação.')
        await handleDenial(pending.operation, error, token)
      }
    } finally {
      if (context.isCurrent(token)) mutating.value = false
    }
  }
  async function retryExact() {
    const pending = selectedOperation.value ?? readPending()
    if (!pending || !canRetryExact.value) return null
    resolvedOperation.value = null
    const token = context.capture()
    const generation = requestGeneration
    const result = await executeOperation(pending.operation as PurchaseAction, pending.path, pending.payload, pending)
    if (result === null || !current(token, generation)) return result
    const outcomeId = typeof result === 'object' && result !== null && Number.isSafeInteger((result as { id?: unknown }).id)
      ? Number((result as { id: number }).id)
      : null
    const sourceId = outcomePurchaseId(pending, result)
    if (pending.operation === 'erp.purchases.xml-confirm' && previewState.value?.preview.token === pending.payload.token)
      cancelXmlPreview()
    if ((pending.operation === 'erp.purchase-returns.store' || pending.operation === 'erp.purchase-returns.post')
      && outcomeId !== null && sourceId !== null && selected.value?.id === sourceId) {
      await inspectReturn(outcomeId, sourceId)
      if (current(token, generation)) await loadReturns()
    }
    if (current(token, generation)) resolvedOperation.value = { key: pending.key, operation: pending.operation, outcome: result }
    return result
  }
  function clearOperationNotice() {
    if (selectedOperation.value) return
    mutationError.value = ''
  }
  watch(context.key, () => {
    clearPrivateState()
    authorityRefreshEpoch++
    deniedActions.value = new Set()
    authorityNeedsRefresh.value = false
    if (currentCompany.value) {
      restorePending()
      restorePreview()
      void load()
      void loadReturns()
      void loadReferences()
    }
  }, { flush: 'sync' })
  onMounted(() => {
    restorePending()
    restorePreview()
    void load()
    void loadReturns()
    void loadReferences()
  })
  onScopeDispose(() => {
    requestGeneration++
    listSequence++
    detailSequence++
    selectionIntentSequence++
    returnSequence++
    previewSequence++
  })

  return {
    purchases, returns, meta, returnMeta, returnFilters, suppliers, establishments, variants, units, selected, selectedReturn, previewState,
    listLoading, returnsLoading, referencesLoading, mutating, loadingDetail, pageError, referenceError, mutationError, detailError, fieldErrors,
    selectedOperation, needsReconciliation, canRetryExact, staleRevisionRefreshed, resolvedOperation, isPurchaseConfirmedPosted,
    authorityGeneration, filters, allowed,
    load, loadReturns, loadReferences, inspect, closeDetail, saveDraft, postReceipt, inspectXml, refreshXmlPreview, cancelXmlPreview, confirmXml,
    createReturn, inspectReturn, postReturn, reconcile, retryExact, clearOperationNotice, restorePreview
  }
}
