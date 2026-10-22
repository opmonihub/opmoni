<script setup lang="ts">
import type { ErpCommercialDocument, ErpCommercialDraftPayload, ErpCommercialKind, ErpSaleReturn } from '#shared/types/erpBusiness'
import type { ErpSalesArea } from '~/composables/useErpSalesWorkspace'
import { useErpSalesWorkspace } from '~/composables/useErpSalesWorkspace'
import { erpCommercialDocumentToDraft, erpSalePostPayload } from '#shared/utils/erpSales'

type ModalKind = 'create' | 'edit' | 'detail' | 'convert' | 'post-sale' | 'return' | 'post-return' | null
type ErpSaleReturnDraft = { reason: string, quantities: Record<number, string> }
type RecoveryOwner = { key: string, authority: number, modal: ModalKind, identity: number, kind: ErpCommercialKind | null, sourceId: number | null, fingerprint: string, returnDraftKey?: string, returnDraftFingerprint?: string }
const sales = useErpSalesWorkspace()
const route = useRoute()
const router = useRouter()
const modalKind = ref<ModalKind>(null)
const modalGeneration = ref(0)
const detailArea = ref<ErpSalesArea>('quotes')
const createKind = ref<ErpCommercialKind>('quote')
const createDrafts = reactive<Record<ErpCommercialKind, ErpCommercialDraftPayload>>({ quote: emptyDraft(), order: emptyDraft(), sale: emptyDraft() })
const editDraft = ref<ErpCommercialDraftPayload>(emptyDraft())
const editKind = ref<ErpCommercialKind>('quote')
const editId = ref<number | null>(null)
const draftAuthority = ref(sales.authorityGeneration.value)
const editIdentity = ref('')
const returnDrafts = reactive<Record<string, ErpSaleReturnDraft>>({})
let createIdentity = 1
const recoveryOwners = new Map<string, RecoveryOwner>()
const includeInlinePayment = ref(false)
const inlinePaymentAmount = ref('')
const paymentConfirmationError = ref('')
const convertNumber = ref('')
const modalOpen = computed({
  get: () => modalKind.value !== null,
  set: (open: boolean) => {
    if (!open) closeModal()
  }
})

const areas: Array<{ id: ErpSalesArea, label: string }> = [
  { id: 'quotes', label: 'Orçamentos' },
  { id: 'orders', label: 'Pedidos' },
  { id: 'sales', label: 'Vendas / balcão' },
  { id: 'returns', label: 'Devoluções' }
]
const visibleAreas = computed(() => areas.filter(item => sales.allowed(({ quotes: 'erp.quotes.index', orders: 'erp.orders.index', sales: 'erp.sales.index', returns: 'erp.sale-returns.index' } as const)[item.id])))
const areaLabel = computed(() => areas.find(item => item.id === sales.area.value)?.label ?? 'Vendas')
const modalTitle = computed(() => {
  if (modalKind.value === 'create') return createKind.value === 'quote' ? 'Novo orçamento' : createKind.value === 'order' ? 'Novo pedido' : 'Nova venda de balcão'
  if (modalKind.value === 'edit') return `Editar ${editKind.value === 'quote' ? 'orçamento' : editKind.value === 'order' ? 'pedido' : 'venda'}`
  if (modalKind.value === 'convert') return detailArea.value === 'quotes' ? 'Converter orçamento em pedido' : 'Converter pedido em venda'
  if (modalKind.value === 'post-sale') return 'Confirmar lançamento da venda'
  if (modalKind.value === 'return') return 'Nova devolução'
  if (modalKind.value === 'post-return') return 'Confirmar lançamento da devolução'
  return 'Detalhes do documento'
})
const statusItems = computed(() => {
  if (sales.area.value === 'quotes') return [{ label: 'Todas', value: 'all' }, { label: 'Rascunhos', value: 'draft' }, { label: 'Convertidos', value: 'converted' }]
  if (sales.area.value === 'orders') return [{ label: 'Todas', value: 'all' }, { label: 'Confirmados', value: 'confirmed' }, { label: 'Convertidos', value: 'converted' }]
  if (sales.area.value === 'returns') return [{ label: 'Todas', value: 'all' }, { label: 'Rascunhos', value: 'draft' }, { label: 'Lançadas', value: 'posted' }]
  return [{ label: 'Todas', value: 'all' }, { label: 'Rascunhos', value: 'draft' }, { label: 'Lançadas', value: 'posted' }]
})
const newAllowed = computed(() => {
  const actions: Record<ErpSalesArea, string> = { quotes: 'erp.quotes.store', orders: 'erp.orders.store', sales: 'erp.sales.store', returns: 'erp.sale-returns.store' }
  return sales.area.value !== 'returns' && sales.allowed(actions[sales.area.value])
})
const recoveryLabel = computed(() => sales.needsReconciliation.value ? 'Operação sem resultado confirmado' : '')
const hasCreatePermission = computed(() => sales.allowed('erp.quotes.store') || sales.allowed('erp.orders.store') || sales.allowed('erp.sales.store'))
const detailPaymentIds = computed(() => {
  const selected = sales.selected.value as ErpCommercialDocument | null
  return detailArea.value === 'sales' && selected ? selected.finance?.payment_ids ?? [] : []
})
const activeReturnDraft = computed(() => {
  const source = sales.selected.value as ErpCommercialDocument | null
  return source ? returnDrafts[returnDraftKey(source.id)] ?? emptyReturnDraft() : emptyReturnDraft()
})
const activeReturnDraftKey = computed(() => {
  const source = sales.selected.value as ErpCommercialDocument | null
  return source ? returnDraftKey(source.id) : ''
})
const selectedNeedsRefresh = computed(() => {
  const selected = sales.selected.value
  return Boolean(selected && sales.requiresFreshDetail(detailArea.value, selected.id))
})

function emptyDraft(establishmentId: number | null = null): ErpCommercialDraftPayload {
  return {
    erp_establishment_id: establishmentId,
    erp_contact_id: null,
    currency: 'BRL',
    lines: [{ erp_product_variant_id: null, erp_unit_id: null, description: '', quantity: '1.0000', discount_amount: '0.0000', tax_amount: '0.0000' }]
  }
}
function emptyReturnDraft(): ErpSaleReturnDraft {
  return { reason: '', quantities: {} }
}
function returnDraftKey(sourceId: number) {
  return `${sales.authorityGeneration.value}:${sourceId}`
}
function getReturnDraft(sourceId: number) {
  const key = returnDraftKey(sourceId)
  return returnDrafts[key] ??= emptyReturnDraft()
}
function updateReturnDraft(sourceId: number, draft: ErpSaleReturnDraft) {
  returnDrafts[returnDraftKey(sourceId)] = { reason: draft.reason, quantities: { ...draft.quantities } }
}
function clearReturnDraft(key: string, fingerprint: string) {
  const current = returnDrafts[key]
  if (current && JSON.stringify(current) === fingerprint) returnDrafts[key] = emptyReturnDraft()
}
function updateCreateDraft(kind: ErpCommercialKind, payload: ErpCommercialDraftPayload) {
  createDrafts[kind] = payload
}
function updateEditDraft(payload: ErpCommercialDraftPayload) {
  editDraft.value = payload
}
function actionForNew(area: ErpSalesArea): ErpCommercialKind | null {
  if (area === 'quotes' && sales.allowed('erp.quotes.store')) return 'quote'
  if (area === 'orders' && sales.allowed('erp.orders.store')) return 'order'
  if (area === 'sales' && sales.allowed('erp.sales.store')) return 'sale'
  return null
}
function documentKind(area: ErpSalesArea): ErpCommercialKind | null {
  return area === 'returns' ? null : area === 'quotes' ? 'quote' : area === 'orders' ? 'order' : 'sale'
}
function canCreateKind(kind: ErpCommercialKind) {
  return sales.allowed(`erp.${kind === 'quote' ? 'quotes' : kind === 'order' ? 'orders' : 'sales'}.store`)
}
function titleForRow(row: Record<string, unknown>, area: ErpSalesArea) {
  const number = area === 'quotes' ? row.quote_no : area === 'orders' ? row.order_no : area === 'sales' ? row.invoice_no : undefined
  const label = ({ quotes: 'Orçamento', orders: 'Pedido', sales: 'Venda', returns: 'Devolução' } as const)[area]
  return typeof number === 'string' && number ? number : `${label} #${String(row.id)}`
}
function resultDocumentId(result: unknown, key?: 'order' | 'sale') {
  if (typeof result !== 'object' || result === null) return null
  const record = result as Record<string, unknown>
  const value = key ? record[key] : record
  return typeof value === 'object' && value !== null && typeof (value as { id?: unknown }).id === 'number' ? (value as { id: number }).id : null
}
function reapplyDraftToCurrentRevision() {
  const conflict = sales.revisionConflict.value
  if (!conflict || conflict.id !== editId.value || conflict.area !== detailArea.value || !sales.selected.value || !('draft_revision' in sales.selected.value)) return
  editDraft.value.draft_revision = conflict.latestRevision
  sales.revisionConflict.value = null
  modalGeneration.value++
  modalKind.value = 'edit'
}
function replaceDraftWithCurrentServerVersion() {
  const conflict = sales.revisionConflict.value
  const document = sales.selected.value as ErpCommercialDocument | null
  if (!conflict || !document || document.id !== conflict.id || conflict.area !== detailArea.value || document.draft_revision !== conflict.latestRevision) return
  editKind.value = documentKind(conflict.area) ?? editKind.value
  editId.value = document.id
  editIdentity.value = `${sales.authorityGeneration.value}:${editKind.value}:${document.id}:${document.draft_revision}`
  editDraft.value = erpCommercialDocumentToDraft(document)
  sales.revisionConflict.value = null
  modalGeneration.value++
  modalKind.value = 'edit'
}
function statusForRow(row: Record<string, unknown>) {
  const status = String(row.status ?? '')
  return status === 'draft' ? 'Rascunho' : status === 'confirmed' ? 'Confirmado' : status === 'converted' ? 'Convertido' : status === 'posted' ? 'Lançado' : status || 'Indisponível'
}
function totalForRow(row: Record<string, unknown>) {
  const currency = typeof row.currency === 'string' ? row.currency : 'BRL'
  const total = typeof row.total_amount === 'string' ? row.total_amount : 'Indisponível'
  return `${currency} ${total}`
}
function areaFromHash(hash: string): ErpSalesArea | null {
  const value = hash.replace(/^#/, '')
  return areas.some(item => item.id === value) ? value as ErpSalesArea : null
}
async function setArea(next: ErpSalesArea, updateHash = true) {
  if (!visibleAreas.value.some(item => item.id === next)) return
  await sales.selectArea(next)
  if (updateHash && sales.area.value === next && route.hash !== `#${next}`) await router.replace({ path: route.path, query: route.query, hash: `#${next}` })
}
function closeModal() {
  modalGeneration.value++
  const previous = modalKind.value
  modalKind.value = null
  if (previous === 'detail') sales.closeSelected()
  paymentConfirmationError.value = ''
}
function beginCreate(kind: ErpCommercialKind) {
  if (!canCreateKind(kind) || sales.mutating.value || sales.selectedOperation.value) return
  sales.claimIntent()
  sales.resolvedOperation.value = null
  createKind.value = kind
  if (draftAuthority.value !== sales.authorityGeneration.value) {
    createDrafts.quote = emptyDraft()
    createDrafts.order = emptyDraft()
    createDrafts.sale = emptyDraft()
    createIdentity++
    draftAuthority.value = sales.authorityGeneration.value
  }
  createDrafts[kind].erp_establishment_id ??= sales.references.establishments[0]?.id ?? null
  modalGeneration.value++
  modalKind.value = 'create'
}
function startCreateForArea() {
  const kind = actionForNew(sales.area.value)
  if (kind) beginCreate(kind)
}
function startCreateMenu(kind: ErpCommercialKind) {
  beginCreate(kind)
}
function currentModal(generation: number, identity: number) {
  return generation === modalGeneration.value && identity === createIdentity && draftAuthority.value === sales.authorityGeneration.value
}
function matchesConsumedConflict(operation: string, areaName: ErpSalesArea, id: number) {
  const conflict = sales.consumedOperationConflict.value
  return conflict?.operation === operation && conflict.area === areaName && conflict.id === id
    && Number((sales.selected.value as { id?: unknown } | null)?.id) === id
}
function pendingBefore() {
  return typeof sales.selectedOperation.value?.key === 'string' ? sales.selectedOperation.value.key : null
}
function captureRecoveryOwner(previousKey: string | null, expectedOperation: string, payload: unknown, modal: ModalKind, identity: number, sourceId: number | null, fingerprint: string, returnDraft?: { key: string, fingerprint: string }) {
  const pending = sales.selectedOperation.value
  if (pending && pending.key !== previousKey && pending.operation === expectedOperation && JSON.stringify(pending.payload) === JSON.stringify(payload)) {
    const kind = modal === 'create' ? createKind.value : modal === 'edit' ? editKind.value : documentKind(detailArea.value)
    recoveryOwners.set(pending.key, {
      key: pending.key, authority: sales.authorityGeneration.value, modal, identity, kind, sourceId, fingerprint,
      ...(returnDraft ? { returnDraftKey: returnDraft.key, returnDraftFingerprint: returnDraft.fingerprint } : {})
    })
    return pending.key
  }
  return null
}
function completeRecoveredOperation(key: string | undefined) {
  if (!key) return
  const owner = recoveryOwners.get(key)
  recoveryOwners.delete(key)
  if (!owner || owner.authority !== sales.authorityGeneration.value || owner.identity !== createIdentity) return
  const resolved = sales.resolvedOperation.value
  if (!resolved || resolved.key !== key) return
  if (owner.modal === 'create' && owner.kind && JSON.stringify(createDrafts[owner.kind]) !== owner.fingerprint) return
  if (owner.modal === 'edit' && JSON.stringify(editDraft.value) !== owner.fingerprint) return
  const selected = sales.selected.value as (ErpCommercialDocument & { erp_sale_id?: number }) | null
  let expectedSelectedId = owner.sourceId
  let targetArea: ErpSalesArea = owner.kind === 'quote' ? 'quotes' : owner.kind === 'order' ? 'orders' : 'sales'
  if (resolved.operation === 'erp.quotes.convert') {
    expectedSelectedId = resultDocumentId(resolved.outcome, 'order')
    targetArea = 'orders'
  } else if (resolved.operation === 'erp.orders.convert') {
    expectedSelectedId = resultDocumentId(resolved.outcome, 'sale')
    targetArea = 'sales'
  } else if (resolved.operation === 'erp.sale-returns.store') {
    expectedSelectedId = resultDocumentId(resolved.outcome)
    targetArea = 'returns'
  } else if ((owner.modal === 'create' || owner.modal === 'edit') && resolved.outcome) {
    expectedSelectedId = resultDocumentId(resolved.outcome)
  }
  const selectedMatches = expectedSelectedId !== null && Number(selected?.id) === expectedSelectedId
    && (resolved.operation !== 'erp.sale-returns.store' || selected?.erp_sale_id === owner.sourceId)
  if (owner.modal === 'create') {
    if (owner.kind) createDrafts[owner.kind] = emptyDraft(sales.references.establishments[0]?.id ?? null)
  }
  if (owner.modal === 'edit') {
    editIdentity.value = ''
    editDraft.value = emptyDraft()
  }
  if (resolved.operation === 'erp.sale-returns.store' && owner.returnDraftKey && owner.returnDraftFingerprint) {
    clearReturnDraft(owner.returnDraftKey, owner.returnDraftFingerprint)
  }
  if (modalKind.value === owner.modal) {
    if (owner.modal === 'create' || owner.modal === 'edit' || resolved.operation.includes('.convert') || resolved.operation === 'erp.sale-returns.store') detailArea.value = targetArea
    if (selectedMatches) modalKind.value = 'detail'
    else closeModal()
  } else if (modalKind.value === null && (resolved.operation.includes('.convert') || resolved.operation === 'erp.sale-returns.store')) {
    detailArea.value = targetArea
  }
}
async function recover() {
  const key = sales.selectedOperation.value?.key
  await sales.reconcile()
  if (sales.resolvedOperation.value?.key === key) completeRecoveredOperation(key)
}
async function retryOriginal() {
  const key = sales.selectedOperation.value?.key
  await sales.retryExact()
  if (sales.resolvedOperation.value?.key === key) completeRecoveredOperation(key)
}
async function saveDraft(payload: ErpCommercialDraftPayload) {
  if (modalKind.value !== 'create' && modalKind.value !== 'edit') return
  const generation = modalGeneration.value
  const identity = createIdentity
  const kind = modalKind.value === 'create' ? createKind.value : editKind.value
  const id = modalKind.value === 'edit' ? editId.value : null
  const fingerprint = JSON.stringify(modalKind.value === 'create' ? createDrafts[kind] : editDraft.value)
  const action = `erp.${kind === 'quote' ? 'quotes' : kind === 'order' ? 'orders' : 'sales'}.${id ? 'update' : 'store'}`
  const previousKey = pendingBefore()
  const request = sales.saveDraft(kind, payload, id)
  const ownerKey = captureRecoveryOwner(previousKey, action, payload, modalKind.value, identity, id, fingerprint)
  const result = await request
  if (ownerKey && (result !== null || sales.currentOperationKey() !== ownerKey)) recoveryOwners.delete(ownerKey)
  if (result === null && editId.value !== null && matchesConsumedConflict(action, detailArea.value, editId.value) && currentModal(generation, identity)) {
    editIdentity.value = ''
    editId.value = null
    editDraft.value = emptyDraft()
    modalKind.value = 'detail'
    return
  }
  if (result === null && sales.revisionConflict.value && currentModal(generation, identity)) {
    closeModal()
    return
  }
  if (result !== null && currentModal(generation, identity)) {
    detailArea.value = kind === 'quote' ? 'quotes' : kind === 'order' ? 'orders' : 'sales'
    const createdId = resultDocumentId(result)
    if (createdId !== null && Number((sales.selected.value as { id?: unknown } | null)?.id) === createdId) modalKind.value = 'detail'
    else closeModal()
    if (!id) {
      createDrafts[kind] = emptyDraft(sales.references.establishments[0]?.id ?? null)
      createIdentity++
    } else {
      editIdentity.value = ''
      editDraft.value = emptyDraft()
    }
  }
}
async function openDetail(areaName: ErpSalesArea, row: Record<string, unknown>) {
  const id = Number(row.id)
  if (!Number.isSafeInteger(id) || id < 1) return
  const intent = sales.claimIntent()
  const generation = modalGeneration.value
  const result = await sales.inspect(areaName, id, intent)
  if (result && generation === modalGeneration.value && sales.operationIntentSequence.value === intent) {
    detailArea.value = areaName
    modalKind.value = 'detail'
  }
}
async function openEdit() {
  if (!sales.selected.value || selectedNeedsRefresh.value || !documentKind(detailArea.value) || sales.mutating.value || sales.selectedOperation.value) return
  const kind = documentKind(detailArea.value)!
  const action = `erp.${kind === 'quote' ? 'quotes' : kind === 'order' ? 'orders' : 'sales'}.update`
  const document = sales.selected.value as ErpCommercialDocument
  if (!sales.allowed(action) || !isEditableDocument(kind, document.status)) return
  const sourceId = document.id
  const intent = sales.operationIntentSequence.value
  const detail = await sales.inspect(detailArea.value, sourceId, intent)
  if (!detail || sales.operationIntentSequence.value !== intent || detail.id !== sourceId) return
  const accepted = detail as ErpCommercialDocument
  if (!isEditableDocument(kind, accepted.status)) return
  const nextIdentity = `${sales.authorityGeneration.value}:${kind}:${sourceId}:${accepted.draft_revision ?? 'none'}`
  if (editIdentity.value === nextIdentity) {
    modalGeneration.value++
    modalKind.value = 'edit'
    return
  }
  editKind.value = kind
  editId.value = sourceId
  editIdentity.value = nextIdentity
  editDraft.value = erpCommercialDocumentToDraft(accepted)
  modalGeneration.value++
  modalKind.value = 'edit'
}
async function convertDocument() {
  if (!canConvertDetail()) return
  const document = sales.selected.value as ErpCommercialDocument | null
  if (!document || (detailArea.value !== 'quotes' && detailArea.value !== 'orders')) return
  const generation = modalGeneration.value
  const modal = modalKind.value
  const action = detailArea.value === 'quotes' ? 'erp.quotes.convert' : 'erp.orders.convert'
  const payload = { draft_revision: document.draft_revision, ...(convertNumber.value ? detailArea.value === 'quotes' ? { order_no: convertNumber.value } : { invoice_no: convertNumber.value } : {}) }
  const previousKey = pendingBefore()
  const request = detailArea.value === 'quotes' ? sales.convertQuote(document, convertNumber.value || undefined) : sales.convertOrder(document, convertNumber.value || undefined)
  const ownerKey = captureRecoveryOwner(previousKey, action, payload, modal, createIdentity, document.id, JSON.stringify(document))
  const result = await request
  if (ownerKey && (result !== null || sales.currentOperationKey() !== ownerKey)) recoveryOwners.delete(ownerKey)
  if (result === null && matchesConsumedConflict(action, detailArea.value, document.id) && generation === modalGeneration.value && modalKind.value === modal) {
    modalKind.value = 'detail'
    convertNumber.value = ''
    return
  }
  if (result !== null && generation === modalGeneration.value && modalKind.value === modal) {
    detailArea.value = detailArea.value === 'quotes' ? 'orders' : 'sales'
    const targetId = detailArea.value === 'orders' ? resultDocumentId(result, 'order') : resultDocumentId(result, 'sale')
    if (targetId !== null && Number((sales.selected.value as { id?: unknown } | null)?.id) === targetId) modalKind.value = 'detail'
    else closeModal()
    convertNumber.value = ''
  }
}
function startConvert() {
  if (!canConvertDetail()) return
  convertNumber.value = ''
  modalGeneration.value++
  modalKind.value = 'convert'
}
function startPostSale() {
  const row = sales.selected.value as ErpCommercialDocument | null
  if (!row || selectedNeedsRefresh.value || row.status !== 'draft' || !sales.allowed('erp.sales.post') || sales.selectedOperation.value || sales.mutating.value) return
  includeInlinePayment.value = false
  inlinePaymentAmount.value = ''
  paymentConfirmationError.value = ''
  modalGeneration.value++
  modalKind.value = 'post-sale'
}
async function confirmPostSale() {
  const row = sales.selected.value as ErpCommercialDocument | null
  if (!row || selectedNeedsRefresh.value) return
  if (includeInlinePayment.value && (!sales.allowed('erp.payments.store') || !sales.allowed('erp.payments.allocate') || !inlinePaymentAmount.value.trim())) {
    paymentConfirmationError.value = 'Informe um valor e confirme as permissões independentes de pagamento e alocação.'
    return
  }
  const generation = modalGeneration.value
  const modal = modalKind.value
  const sourceId = row.id
  const payment = includeInlinePayment.value ? { direction: 'in', amount: inlinePaymentAmount.value.trim(), method: 'manual', erp_establishment_id: row.erp_establishment_id } : undefined
  const payload = erpSalePostPayload(row, payment)
  const previousKey = pendingBefore()
  const request = sales.postSale(row, payment)
  const ownerKey = captureRecoveryOwner(previousKey, 'erp.sales.post', payload, modal, createIdentity, sourceId, JSON.stringify(row))
  const result = await request
  if (ownerKey && (result !== null || sales.currentOperationKey() !== ownerKey)) recoveryOwners.delete(ownerKey)
  if (result === null && matchesConsumedConflict('erp.sales.post', 'sales', sourceId) && generation === modalGeneration.value && modalKind.value === modal) {
    includeInlinePayment.value = false
    inlinePaymentAmount.value = ''
    modalKind.value = 'detail'
    return
  }
  if (result !== null && generation === modalGeneration.value && modalKind.value === modal && (sales.selected.value as ErpCommercialDocument | null)?.id === sourceId) modalKind.value = 'detail'
}
function startReturn() {
  const source = sales.selected.value as ErpCommercialDocument | null
  if (!source || selectedNeedsRefresh.value || source.status !== 'posted' || !sales.allowed('erp.sale-returns.store') || source.returnability?.needs_reconciliation || source.finance?.needs_reconciliation || sales.selectedOperation.value || sales.mutating.value) return
  getReturnDraft(source.id)
  sales.fieldErrors.value = {}
  modalGeneration.value++
  modalKind.value = 'return'
}
async function createReturn(reason: string, lines: Array<{ source_line_id: number, quantity: string }>) {
  const source = sales.selected.value as ErpCommercialDocument | null
  if (!source || selectedNeedsRefresh.value) return
  const generation = modalGeneration.value
  const sourceId = source.id
  const draftKey = returnDraftKey(sourceId)
  const returnDraftFingerprint = JSON.stringify(returnDrafts[draftKey] ?? emptyReturnDraft())
  const payload = { erp_sale_id: sourceId, reason, lines }
  const previousKey = pendingBefore()
  const request = sales.storeReturn(sourceId, reason, lines)
  const ownerKey = captureRecoveryOwner(previousKey, 'erp.sale-returns.store', payload, 'return', createIdentity, sourceId, JSON.stringify(payload), { key: draftKey, fingerprint: returnDraftFingerprint })
  const result = await request
  if (ownerKey && (result !== null || sales.currentOperationKey() !== ownerKey)) recoveryOwners.delete(ownerKey)
  if (result !== null) clearReturnDraft(draftKey, returnDraftFingerprint)
  if (result !== null && generation === modalGeneration.value && modalKind.value === 'return') {
    detailArea.value = 'returns'
    const returnId = resultDocumentId(result)
    if (returnId !== null && Number((sales.selected.value as { id?: unknown } | null)?.id) === returnId) modalKind.value = 'detail'
    else closeModal()
  }
}
async function postReturn() {
  const row = sales.selected.value as ErpSaleReturn | null
  if (!row || selectedNeedsRefresh.value || row.status !== 'draft' || sales.selectedOperation.value || sales.mutating.value) return
  const generation = modalGeneration.value
  const modal = modalKind.value
  const sourceId = row.id
  const payload = {}
  const previousKey = pendingBefore()
  const request = sales.postReturn(row)
  const ownerKey = captureRecoveryOwner(previousKey, 'erp.sale-returns.post', payload, modal, createIdentity, sourceId, JSON.stringify(row))
  const result = await request
  if (ownerKey && (result !== null || sales.currentOperationKey() !== ownerKey)) recoveryOwners.delete(ownerKey)
  if (result === null && matchesConsumedConflict('erp.sale-returns.post', 'returns', sourceId) && generation === modalGeneration.value && modalKind.value === modal) {
    modalKind.value = 'detail'
    return
  }
  if (result !== null && generation === modalGeneration.value && modalKind.value === modal && (sales.selected.value as ErpSaleReturn | null)?.id === sourceId) modalKind.value = 'detail'
}
function startPostReturn() {
  const row = sales.selected.value as ErpSaleReturn | null
  if (!row || row.status !== 'draft' || !sales.allowed('erp.sale-returns.post') || row.needs_reconciliation === true || sales.selectedOperation.value || sales.mutating.value) return
  modalGeneration.value++
  modalKind.value = 'post-return'
}
async function openReceipt(paymentId: number) {
  await sales.loadReceipt(paymentId)
}
function listEmptyMessage() {
  if (!sales.allowed(({ quotes: 'erp.quotes.index', orders: 'erp.orders.index', sales: 'erp.sales.index', returns: 'erp.sale-returns.index' } as const)[sales.area.value])) return 'Seu acesso não permite consultar esta lista.'
  if (sales.currentLoading.value) return 'Carregando registros…'
  return sales.currentMeta.value ? 'Nenhum registro encontrado com estes filtros.' : 'A lista ainda não foi carregada.'
}
function financeAuthority() {
  return sales.allowed('erp.payments.store') && sales.allowed('erp.payments.allocate')
}
function canEditDetail() {
  const kind = documentKind(detailArea.value)
  const document = sales.selected.value as ErpCommercialDocument | null
  return !!kind && !!document && !selectedNeedsRefresh.value && !sales.selectedOperation.value && !sales.mutating.value && isEditableDocument(kind, document.status) && sales.allowed(`erp.${kind === 'quote' ? 'quotes' : kind === 'order' ? 'orders' : 'sales'}.update`)
}
function isEditableDocument(kind: ErpCommercialKind, status: ErpCommercialDocument['status']) {
  return kind === 'quote' ? status === 'draft' : kind === 'order' ? status === 'confirmed' : status === 'draft'
}
function canConvertDetail() {
  if (selectedNeedsRefresh.value || sales.selectedOperation.value || sales.mutating.value) return false
  if (detailArea.value === 'quotes') return sales.allowed('erp.quotes.convert') && (sales.selected.value as ErpCommercialDocument | null)?.status === 'draft'
  if (detailArea.value === 'orders') return sales.allowed('erp.orders.convert') && (sales.selected.value as ErpCommercialDocument | null)?.status === 'confirmed'
  return false
}
function canPostSaleDetail() {
  return !selectedNeedsRefresh.value && !sales.selectedOperation.value && !sales.mutating.value && detailArea.value === 'sales' && (sales.selected.value as ErpCommercialDocument | null)?.status === 'draft' && sales.allowed('erp.sales.post')
}
function canReturnDetail() {
  return !selectedNeedsRefresh.value && !sales.selectedOperation.value && !sales.mutating.value && detailArea.value === 'sales' && (sales.selected.value as ErpCommercialDocument | null)?.status === 'posted' && sales.allowed('erp.sale-returns.store')
}
function canPostReturnDetail() {
  return !selectedNeedsRefresh.value && !sales.selectedOperation.value && !sales.mutating.value && detailArea.value === 'returns' && (sales.selected.value as ErpSaleReturn | null)?.status === 'draft' && sales.allowed('erp.sale-returns.post')
}
async function refreshCompletedDetail(areaName: ErpSalesArea, id: number) {
  await sales.refreshCompletedDetail(areaName, id)
}

watch(() => route.hash, (hash) => {
  const next = areaFromHash(hash)
  if (next && next !== sales.area.value) void setArea(next, false)
})
watch(sales.authorityGeneration, () => {
  createDrafts.quote = emptyDraft()
  createDrafts.order = emptyDraft()
  createDrafts.sale = emptyDraft()
  editDraft.value = emptyDraft()
  for (const key of Object.keys(returnDrafts)) returnDrafts[key] = emptyReturnDraft()
  editIdentity.value = ''
  createIdentity++
  closeModal()
})
onMounted(() => {
  const hashArea = areaFromHash(route.hash)
  if (hashArea && visibleAreas.value.some(item => item.id === hashArea)) void setArea(hashArea, false)
})
</script>

<template>
  <ErpCatalogPageShell title="Vendas" description="Orçamentos, pedidos, vendas de balcão e devoluções com valores confirmados pelo servidor.">
    <template #actions>
      <UButton
        v-if="newAllowed"
        color="primary"
        icon="i-lucide-plus"
        label="Novo registro"
        :disabled="sales.mutating.value || Boolean(sales.selectedOperation.value)"
        @click="startCreateForArea"
      />
      <UDropdownMenu
        v-else-if="hasCreatePermission"
        :items="[
          [{ label: 'Novo orçamento', disabled: !sales.allowed('erp.quotes.store') || sales.mutating.value || Boolean(sales.selectedOperation.value), onSelect: () => startCreateMenu('quote') },
           { label: 'Novo pedido', disabled: !sales.allowed('erp.orders.store') || sales.mutating.value || Boolean(sales.selectedOperation.value), onSelect: () => startCreateMenu('order') },
           { label: 'Nova venda de balcão', disabled: !sales.allowed('erp.sales.store') || sales.mutating.value || Boolean(sales.selectedOperation.value), onSelect: () => startCreateMenu('sale') }]
        ]"
      >
        <UButton
          color="primary"
          icon="i-lucide-plus"
          label="Novo registro"
          :disabled="sales.mutating.value || Boolean(sales.selectedOperation.value)"
        />
      </UDropdownMenu>
      <CompanyMenu class="hidden max-w-52 lg:block" />
      <UserMenu collapsed class="hidden lg:block" />
    </template>

    <template #toolbar>
      <div class="flex w-full min-w-0 flex-col gap-3">
        <nav class="w-full min-w-0 overflow-x-auto" aria-label="Áreas de vendas">
          <div class="flex min-w-max gap-2">
            <UButton
              v-for="item in visibleAreas"
              :key="item.id"
              type="button"
              size="sm"
              :color="sales.area.value === item.id ? 'primary' : 'neutral'"
              :variant="sales.area.value === item.id ? 'solid' : 'outline'"
              :aria-pressed="sales.area.value === item.id"
              :label="item.label"
              @click="setArea(item.id)"
            />
          </div>
        </nav>
        <div class="flex w-full min-w-0 flex-wrap items-end gap-3">
          <UFormField label="Situação" class="w-full min-w-0 sm:w-52">
            <USelect
              :model-value="sales.filters.status || 'all'"
              :items="statusItems"
              class="w-full"
              @update:model-value="sales.setStatus($event === 'all' ? '' : String($event ?? ''))"
            />
          </UFormField>
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            label="Atualizar lista"
            :loading="sales.currentLoading.value"
            :disabled="!sales.canRead.value"
            @click="sales.refreshArea"
          />
          <span v-if="sales.currentStale.value" class="text-xs text-warning">Exibindo dados anteriores; a atualização falhou.</span>
        </div>
      </div>
    </template>

    <section class="min-w-0 space-y-4" aria-label="Registros comerciais">
      <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 class="text-lg font-semibold text-highlighted">
            {{ areaLabel }}
          </h1><p v-if="sales.currentMeta.value" class="text-sm text-muted">
            {{ sales.currentMeta.value.total }} registros · página {{ sales.currentMeta.value.current_page }} de {{ sales.currentMeta.value.last_page }}
          </p>
        </div>
      </div>
      <UAlert
        v-if="sales.currentError.value"
        color="error"
        title="Não foi possível atualizar"
        :description="sales.currentError.value"
      />
      <div v-if="sales.currentLoading.value && !sales.currentMeta.value" class="rounded-lg border border-muted p-5 text-sm text-muted" role="status">
        Carregando {{ areaLabel.toLocaleLowerCase('pt-BR') }}…
      </div>
      <div v-else-if="sales.currentRows.value.length === 0" class="rounded-lg border border-dashed border-muted p-8 text-center text-sm text-muted">
        {{ listEmptyMessage() }}
      </div>
      <div v-else class="min-w-0 divide-y divide-muted overflow-hidden rounded-lg border border-muted">
        <button
          v-for="row in sales.currentRows.value"
          :key="`${sales.area.value}-${row.id}`"
          type="button"
          class="grid w-full min-w-0 gap-3 p-4 text-left transition-colors hover:bg-elevated focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1fr)_8rem_12rem_auto] sm:items-center"
          :disabled="!sales.allowed(({ quotes: 'erp.quotes.show', orders: 'erp.orders.show', sales: 'erp.sales.show', returns: 'erp.sale-returns.show' } as const)[sales.area.value])"
          @click="openDetail(sales.area.value, row as unknown as Record<string, unknown>)"
        >
          <span class="min-w-0"><span class="block truncate font-medium text-highlighted">{{ titleForRow(row as unknown as Record<string, unknown>, sales.area.value) }}</span><span class="block text-xs text-muted">Registro #{{ row.id }} · Estabelecimento #{{ row.erp_establishment_id }}</span></span>
          <UBadge class="w-fit" color="neutral" variant="subtle">
            {{ statusForRow(row as unknown as Record<string, unknown>) }}
          </UBadge>
          <span class="text-sm text-highlighted">{{ totalForRow(row as unknown as Record<string, unknown>) }}</span>
          <UIcon name="i-lucide-chevron-right" class="size-4 text-muted" aria-hidden="true" />
        </button>
      </div>
      <div v-if="sales.currentMeta.value && sales.currentMeta.value.last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          label="Anterior"
          :disabled="sales.filters.page <= 1 || sales.currentLoading.value"
          @click="sales.setPage(sales.filters.page - 1)"
        />
        <span class="text-sm text-muted">Página {{ sales.filters.page }} / {{ sales.currentMeta.value.last_page }}</span>
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          label="Próxima"
          :disabled="sales.filters.page >= sales.currentMeta.value.last_page || sales.currentLoading.value"
          @click="sales.setPage(sales.filters.page + 1)"
        />
      </div>
    </section>

    <UAlert
      v-if="sales.referenceError.value"
      color="warning"
      title="Referências incompletas"
      :description="sales.referenceError.value"
    />
    <UAlert
      v-if="sales.resolvedOperation.value && !sales.needsReconciliation.value && sales.mutationError.value && modalKind === null"
      color="success"
      title="Operação confirmada"
      :description="sales.mutationError.value"
    />
    <UAlert
      v-if="sales.needsReconciliation.value"
      color="warning"
      title="Operação aguardando conferência"
      :description="recoveryLabel"
    >
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <UButton
            type="button"
            size="sm"
            color="neutral"
            variant="outline"
            label="Consultar operação original"
            :loading="sales.mutating.value"
            @click="recover"
          />
          <UButton
            v-if="sales.canRetryExact.value"
            type="button"
            size="sm"
            color="primary"
            label="Repetir exatamente a mesma operação"
            :loading="sales.mutating.value"
            @click="retryOriginal"
          />
        </div>
      </template>
    </UAlert>
    <UAlert
      v-if="sales.revisionConflict.value"
      color="warning"
      title="O rascunho mudou no servidor"
      description="Suas alterações locais foram mantidas. Escolha aplicar essas alterações sobre a revisão atual ou carregar a versão mais recente."
    >
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <UButton
            type="button"
            size="sm"
            color="primary"
            label="Reaplicar minhas alterações"
            @click="reapplyDraftToCurrentRevision"
          />
          <UButton
            type="button"
            size="sm"
            color="neutral"
            variant="outline"
            label="Usar versão atual do servidor"
            @click="replaceDraftWithCurrentServerVersion"
          />
        </div>
      </template>
    </UAlert>
    <UAlert
      v-for="pendingDetail in sales.completedDetailRefresh.value"
      :key="`${pendingDetail.area}-${pendingDetail.id}`"
      color="warning"
      title="Operação confirmada; detalhe desatualizado"
      :description="`O servidor confirmou a operação, mas o detalhe ${pendingDetail.area === 'sales' ? 'da venda' : pendingDetail.area === 'quotes' ? 'do orçamento' : pendingDetail.area === 'orders' ? 'do pedido' : 'da devolução'} #${pendingDetail.id} ainda não foi atualizado. Consulte a versão autorizada antes de continuar.`"
    >
      <template #actions>
        <UButton
          type="button"
          color="primary"
          size="sm"
          label="Atualizar detalhe"
          :loading="sales.detailLoading.value"
          @click="refreshCompletedDetail(pendingDetail.area, pendingDetail.id)"
        />
      </template>
    </UAlert>

    <UModal
      v-model:open="modalOpen"
      :title="modalTitle"
      :dismissible="!sales.mutating.value && !sales.needsReconciliation.value"
      :close="!sales.mutating.value"
      :ui="{ content: 'w-full max-w-3xl', body: 'max-h-[80vh] overflow-y-auto' }"
    >
      <template #body>
        <div class="min-w-0 space-y-4">
          <UAlert
            v-if="selectedNeedsRefresh && sales.selected.value"
            color="warning"
            title="Operação confirmada; detalhe desatualizado"
            description="O servidor confirmou esta operação, mas a nova versão autorizada ainda não foi carregada. Continue depois de atualizar o detalhe."
          >
            <template #actions>
              <UButton
                type="button"
                color="primary"
                size="sm"
                label="Atualizar detalhe"
                :loading="sales.detailLoading.value"
                @click="refreshCompletedDetail(detailArea, sales.selected.value!.id)"
              />
            </template>
          </UAlert>
          <UAlert
            v-if="sales.mutationError.value"
            :color="sales.resolvedOperation.value ? 'success' : 'error'"
            :title="sales.resolvedOperation.value ? 'Operação confirmada' : 'Atenção à operação'"
            :description="sales.mutationError.value"
          >
            <template v-if="sales.needsReconciliation.value" #actions>
              <div class="flex flex-wrap gap-2">
                <UButton
                  type="button"
                  size="sm"
                  color="neutral"
                  variant="outline"
                  label="Consultar operação original"
                  :loading="sales.mutating.value"
                  @click="recover"
                />
                <UButton
                  v-if="sales.canRetryExact.value"
                  type="button"
                  size="sm"
                  color="primary"
                  label="Repetir exatamente"
                  :loading="sales.mutating.value"
                  @click="retryOriginal"
                />
              </div>
            </template>
          </UAlert>
          <ErpCommercialDraftForm
            v-if="modalKind === 'create'"
            :key="`create-${createKind}-${createIdentity}`"
            :kind="createKind"
            :draft="createDrafts[createKind]"
            :references="sales.references"
            :errors="sales.fieldErrors.value"
            :saving="sales.mutating.value"
            :can-override-price="sales.allowed('erp.sales.price.override')"
            :can-override-discount="sales.allowed('erp.sales.discount.override')"
            :disabled="sales.needsReconciliation.value"
            @update:draft="updateCreateDraft(createKind, $event)"
            @save="saveDraft"
            @cancel="closeModal"
          />
          <ErpCommercialDraftForm
            v-else-if="modalKind === 'edit'"
            :key="editIdentity"
            :kind="editKind"
            :draft="editDraft"
            :references="sales.references"
            :errors="sales.fieldErrors.value"
            :saving="sales.mutating.value"
            :can-override-price="sales.allowed('erp.sales.price.override')"
            :can-override-discount="sales.allowed('erp.sales.discount.override')"
            :disabled="sales.needsReconciliation.value"
            :editing="true"
            @update:draft="updateEditDraft"
            @save="saveDraft"
            @cancel="closeModal"
          />
          <div v-else-if="modalKind === 'detail' && sales.selected.value" class="min-w-0 space-y-4">
            <UAlert
              v-if="sales.detailError.value"
              color="warning"
              title="Alguns detalhes financeiros não estão disponíveis"
              :description="sales.detailError.value"
            />
            <ErpSaleDetail
              :area="detailArea"
              :document="sales.selected.value"
              :payments="sales.paymentDetails.value"
              :payment-ids="detailPaymentIds"
              :obligation="sales.selectedObligation.value"
              :receipt="sales.receipt.value"
              :can-edit="Boolean(canEditDetail())"
              :can-convert="canConvertDetail()"
              :can-post-sale="canPostSaleDetail()"
              :can-return="canReturnDetail()"
              :can-post-return="canPostReturnDetail()"
              :can-receipt="sales.allowed('erp.payments.receipt')"
              :can-read-payments="sales.allowed('erp.payments.show')"
              :can-payments-index="sales.allowed('erp.payments.index')"
              :can-obligations-index="sales.allowed('erp.obligations.index')"
              :can-fiscal="sales.allowed('erp.fiscal.documents.index')"
              :busy="sales.mutating.value || selectedNeedsRefresh"
              @edit="openEdit"
              @convert="startConvert"
              @post-sale="startPostSale"
              @return-sale="startReturn"
              @post-return="startPostReturn"
              @receipt="openReceipt"
            />
          </div>
          <div v-else-if="modalKind === 'convert'" class="space-y-4">
            <UAlert color="info" title="Conversão comercial" :description="detailArea === 'quotes' ? 'O orçamento será convertido em um pedido confirmado. Nenhum estoque será reservado.' : 'O pedido será convertido em uma venda em rascunho. Estoque e financeiro só mudam no lançamento da venda.'" />
            <UFormField :label="detailArea === 'quotes' ? 'Número do pedido (opcional)' : 'Documento de balcão (opcional)'">
              <UInput v-model="convertNumber" class="w-full" :disabled="sales.mutating.value" />
            </UFormField>
            <div class="flex flex-wrap justify-end gap-2 border-t border-muted pt-4">
              <UButton
                type="button"
                color="neutral"
                variant="ghost"
                label="Voltar"
                :disabled="sales.mutating.value || selectedNeedsRefresh"
                @click="modalKind = 'detail'"
              /><UButton
                type="button"
                color="primary"
                :label="detailArea === 'quotes' ? 'Confirmar conversão para pedido' : 'Confirmar conversão para venda'"
                :loading="sales.mutating.value"
                :disabled="sales.needsReconciliation.value || selectedNeedsRefresh"
                @click="convertDocument"
              />
            </div>
          </div>
          <div v-else-if="modalKind === 'post-sale'" class="space-y-4">
            <UAlert color="warning" title="Lançamento irreversível" description="O servidor conferirá estoque e criará a obrigação financeira. A situação fiscal permanece independente." />
            <p class="text-sm text-muted">
              Venda #{{ (sales.selected.value as ErpCommercialDocument | null)?.id }} · total do detalhe autorizado {{ (sales.selected.value as ErpCommercialDocument | null)?.currency }} {{ (sales.selected.value as ErpCommercialDocument | null)?.total_amount }}
            </p>
            <UCheckbox v-model="includeInlinePayment" label="Registrar um pagamento interno agora, junto com o lançamento" :disabled="sales.mutating.value || !financeAuthority()" />
            <UAlert v-if="!financeAuthority()" color="info" description="Pagamento imediato exige permissões independentes de criação e alocação. A venda pode ser lançada como recebível." />
            <UFormField
              v-if="includeInlinePayment"
              label="Valor recebido (R$)"
              required
              :error="sales.fieldErrors.value['payment.amount']"
            >
              <UInput
                v-model="inlinePaymentAmount"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                class="w-full"
                :disabled="sales.mutating.value"
              />
            </UFormField>
            <p v-if="includeInlinePayment" class="text-xs text-muted">
              Pagamento manual interno. Nenhuma cobrança externa será enviada.
            </p>
            <UAlert v-if="paymentConfirmationError" color="error" :description="paymentConfirmationError" />
            <div class="flex flex-wrap justify-end gap-2 border-t border-muted pt-4">
              <UButton
                type="button"
                color="neutral"
                variant="ghost"
                label="Voltar"
                :disabled="sales.mutating.value"
                @click="modalKind = 'detail'"
              /><UButton
                type="button"
                color="primary"
                label="Confirmar lançamento da venda"
                :loading="sales.mutating.value"
                :disabled="sales.needsReconciliation.value"
                @click="confirmPostSale"
              />
            </div>
          </div>
          <div v-else-if="modalKind === 'post-return'" class="space-y-4">
            <UAlert color="warning" title="Lançamento de devolução" description="Esta ação lança o crédito oposto calculado pelo servidor. O recebimento original e qualquer caixa permanecem inalterados; nenhum reembolso será feito automaticamente." />
            <p class="text-sm text-muted">
              Devolução #{{ (sales.selected.value as ErpSaleReturn | null)?.id }} · venda original #{{ (sales.selected.value as ErpSaleReturn | null)?.erp_sale_id }}
            </p>
            <div class="flex flex-wrap justify-end gap-2 border-t border-muted pt-4">
              <UButton
                type="button"
                color="neutral"
                variant="ghost"
                label="Voltar"
                :disabled="sales.mutating.value"
                @click="modalKind = 'detail'"
              /><UButton
                type="button"
                color="primary"
                label="Confirmar lançamento da devolução"
                :loading="sales.mutating.value"
                :disabled="sales.needsReconciliation.value"
                @click="postReturn"
              />
            </div>
          </div>
          <ErpSaleReturnForm
            v-else-if="modalKind === 'return' && sales.selected.value"
            :key="activeReturnDraftKey"
            :sale="sales.selected.value as ErpCommercialDocument"
            :draft="activeReturnDraft"
            :saving="sales.mutating.value"
            :errors="sales.fieldErrors.value"
            :disabled="sales.needsReconciliation.value"
            @update:draft="updateReturnDraft((sales.selected.value as ErpCommercialDocument).id, $event)"
            @submit="createReturn"
            @cancel="modalKind = 'detail'"
          />
          <div v-else-if="sales.detailLoading.value" class="p-5 text-sm text-muted" role="status">
            Carregando detalhe autorizado…
          </div>
          <UAlert
            v-else-if="sales.detailError.value"
            color="error"
            title="Detalhe indisponível"
            :description="sales.detailError.value"
          />
        </div>
      </template>
    </UModal>
  </ErpCatalogPageShell>
</template>
