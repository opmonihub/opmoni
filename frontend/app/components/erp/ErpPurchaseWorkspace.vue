<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ErpPurchase, ErpPurchaseDraftPayload, ErpPurchaseReturn } from '#shared/types/erpBusiness'
import { formatPurchaseDecimal } from '#shared/utils/erpPurchase'
import { useErpPurchaseWorkspace } from '~/composables/useErpPurchaseWorkspace'

const purchase = useErpPurchaseWorkspace()
type PurchaseArea = 'purchases' | 'xml-preview' | 'returns'
const route = useRoute()
const router = useRouter()
const selectedArea = ref<PurchaseArea>('purchases')
const createOpen = ref(false)
const editOpen = ref(false)
const detailOpen = ref(false)
const returnOpen = ref(false)
const postOpen = ref(false)
const modalAttempt = reactive({ create: 0, edit: 0, detail: 0, return: 0, xml: 0, post: 0 })
type RecoveryOwner = { kind: 'create' | 'edit' | 'post', authority: number, identity: string, fingerprint: string, sourceId: number | null }
const recoveryOwners = new Map<string, RecoveryOwner>()
const createDraft = ref<ErpPurchaseDraftPayload>(emptyDraft())
const editDraft = ref<ErpPurchaseDraftPayload>(emptyDraft())
const draftAuthority = ref(purchase.authorityGeneration.value)
let createDraftIdentity = 1
const returnEntry = ref({ quantities: {} as Record<string, string>, reason: '' })
const returnEntryIdentity = ref('')
let pendingReturnSourceId: number | null = null
let pendingEditSourceId: number | null = null
const xmlEstablishmentId = ref<number | null>(null)
const editPurchaseId = ref<number | null>(null)
const editAuthority = ref<number | null>(null)
const editDraftIdentity = ref('')
const xmlRefreshing = ref(false)
const xmlUploading = ref(false)
const allowed = purchase.allowed
const areas: Array<{ id: PurchaseArea, label: string }> = [
  { id: 'purchases', label: 'Compras' },
  { id: 'xml-preview', label: 'Prévia XML' },
  { id: 'returns', label: 'Devoluções' }
]
const areaLinks = computed(() => [areas.filter(area => areaAllowed(area.id)).map(area => ({
  label: area.label,
  to: { path: route.path, query: route.query, hash: `#${area.id}` },
  active: selectedArea.value === area.id
}))])

function emptyDraft(): ErpPurchaseDraftPayload {
  return { erp_establishment_id: null, erp_contact_id: null, currency: 'BRL', document_no: '', lines: [{ erp_product_variant_id: null, erp_unit_id: null, description: '', quantity: '1.0000', unit_cost: '0.0000', unit_conversion_factor: '1.00000000', discount_amount: '0.0000', tax_amount: '0.0000' }] }
}
function areaAllowed(area: PurchaseArea) {
  if (area === 'purchases') return allowed('erp.purchases.index')
  if (area === 'xml-preview') return allowed('erp.purchases.xml-preview') || allowed('erp.purchases.xml-preview.read')
  return allowed('erp.purchase-returns.index')
}
function areaFromHash(hash: string): PurchaseArea | null {
  return areas.some(area => `#${area.id}` === hash) ? hash.slice(1) as PurchaseArea : null
}
async function setArea(area: PurchaseArea, updateHash = true) {
  if (!areaAllowed(area)) return
  selectedArea.value = area
  if (updateHash && route.hash !== `#${area}`) await router.replace({ path: route.path, query: route.query, hash: `#${area}` })
  if (area === 'purchases') void purchase.load()
  else if (area === 'returns') void purchase.loadReturns()
}
function errorColor(): 'success' | 'error' | 'warning' {
  if (!purchase.mutationError.value && purchase.selectedOperation.value) return 'warning'
  return purchase.mutationError.value.startsWith('Operação confirmada') ? 'success' : 'error'
}
function money(value: string | null | undefined, currency: string) {
  if (value === null || value === undefined) return 'Indisponível'
  try {
    return `${currency} ${formatPurchaseDecimal(value)}`
  } catch {
    return `${currency} ${value}`
  }
}
const columns: TableColumn<ErpPurchase>[] = [
  { accessorKey: 'document_no', header: 'Documento' },
  { id: 'supplier', header: 'Fornecedor' },
  { accessorKey: 'status', header: 'Situação' },
  { id: 'amount', header: 'Total' },
  { id: 'actions' }
]
const statusOptions = [
  { label: 'Todas as situações', value: 'all' },
  { label: 'Rascunho', value: 'draft' },
  { label: 'Lançada', value: 'posted' }
]
const emptyLabel = computed(() => !allowed('erp.purchases.index')
  ? 'Seu acesso não permite consultar compras.'
  : purchase.listLoading.value ? 'Carregando compras…' : purchase.meta.value ? 'Nenhuma compra encontrada.' : 'A lista ainda não foi carregada.')
function supplierName(row: ErpPurchase) {
  const snapshot = row.supplier_snapshot as { trade_name?: unknown, name?: unknown } | null
  return String(snapshot?.trade_name ?? snapshot?.name ?? `Fornecedor #${row.erp_contact_id}`)
}
function begin(kind: keyof typeof modalAttempt, expectedSourceId?: number | null, allowMissingSource = false) {
  modalAttempt[kind]++
  return { kind, attempt: modalAttempt[kind], authority: purchase.authorityGeneration.value, sourceId: expectedSourceId === undefined ? purchase.selected.value?.id ?? null : expectedSourceId, allowMissingSource }
}
function currentModal(token: ReturnType<typeof begin>) {
  return token.attempt === modalAttempt[token.kind]
    && token.authority === purchase.authorityGeneration.value
    && (token.sourceId === null || token.sourceId === purchase.selected.value?.id || (token.allowMissingSource && purchase.selected.value === null))
}
function pendingOperationKey() {
  const pending = purchase.selectedOperation.value as { key?: unknown } | null
  return typeof pending?.key === 'string' ? pending.key : null
}
function trackRecoveryOwner(owner: RecoveryOwner, previousKey: string | null, expectedOperation: string, expectedPayload: unknown) {
  const pending = purchase.selectedOperation.value as { key?: unknown } | null
  const operation = pending as { key?: unknown, operation?: unknown, payload?: unknown } | null
  if (typeof operation?.key === 'string' && operation.key !== previousKey
    && operation.operation === expectedOperation && JSON.stringify(operation.payload) === JSON.stringify(expectedPayload)) {
    recoveryOwners.set(operation.key, owner)
    return operation.key
  }
  return null
}
function completeRecoveredOperation(key: string | undefined) {
  if (!key) return
  const owner = recoveryOwners.get(key)
  recoveryOwners.delete(key)
  if (!owner || owner.authority !== purchase.authorityGeneration.value) return
  if (owner.kind === 'create' && owner.identity === String(createDraftIdentity) && JSON.stringify(createDraft.value) === owner.fingerprint) {
    if (createOpen.value) {
      begin('create')
      createOpen.value = false
    }
    createDraft.value = emptyDraft()
    createDraftIdentity++
  } else if (owner.kind === 'edit' && editDraftIdentity.value === owner.identity && editPurchaseId.value === owner.sourceId
    && purchase.selected.value?.id === owner.sourceId && JSON.stringify(editDraft.value) === owner.fingerprint) {
    if (editOpen.value) {
      begin('edit')
      editOpen.value = false
    }
    editAuthority.value = null
    editDraftIdentity.value = ''
  } else if (owner.kind === 'post' && postOpen.value && purchase.selected.value?.id === owner.sourceId
    && (purchase.selected.value?.status === 'posted' || purchase.isPurchaseConfirmedPosted(owner.sourceId!))) {
    begin('post')
    postOpen.value = false
  }
}
async function reconcileOperation() {
  const pending = purchase.selectedOperation.value as { key?: unknown } | null
  const key = typeof pending?.key === 'string' ? pending.key : undefined
  await purchase.reconcile()
  const resolved = purchase.resolvedOperation.value as { key?: unknown } | null
  if (resolved?.key === key) completeRecoveredOperation(key)
}
async function retryOperation() {
  const pending = purchase.selectedOperation.value as { key?: unknown } | null
  const key = typeof pending?.key === 'string' ? pending.key : undefined
  await purchase.retryExact()
  const resolved = purchase.resolvedOperation.value as { key?: unknown } | null
  if (resolved?.key === key) completeRecoveredOperation(key)
}
function startCreate() {
  if (!allowed('erp.purchases.store')) return
  begin('create')
  purchase.clearOperationNotice()
  if (draftAuthority.value !== purchase.authorityGeneration.value) {
    createDraft.value = emptyDraft()
    createDraftIdentity++
    draftAuthority.value = purchase.authorityGeneration.value
  }
  createOpen.value = true
}
function startEdit(row: ErpPurchase) {
  if (!allowed('erp.purchases.update') || row.status !== 'draft' || purchase.isPurchaseConfirmedPosted(row.id)) return
  begin('edit')
  editPurchaseId.value = row.id
  editAuthority.value = purchase.authorityGeneration.value
  purchase.clearOperationNotice()
  const identity = `${purchase.authorityGeneration.value}:${row.id}:${row.draft_revision ?? 'none'}`
  if (editDraftIdentity.value !== identity) {
    editDraftIdentity.value = identity
    editDraft.value = {
      erp_establishment_id: row.erp_establishment_id,
      erp_contact_id: row.erp_contact_id,
      currency: row.currency,
      document_no: row.document_no ?? '',
      draft_revision: row.draft_revision ?? undefined,
      lines: row.lines.map(line => ({
        id: line.id,
        erp_product_variant_id: line.erp_product_variant_id,
        erp_unit_id: line.erp_unit_id,
        description: line.description,
        quantity: line.quantity,
        unit_cost: line.unit_cost,
        unit_conversion_factor: line.unit_conversion_factor,
        discount_amount: line.product_snapshot?.amounts?.discount_amount ?? line.discount_amount ?? '0.0000',
        tax_amount: line.product_snapshot?.amounts?.tax_amount ?? line.tax_amount ?? '0.0000',
        erp_tax_rate_id: line.product_snapshot?.tax_rate?.id ?? null,
        erp_operation_nature_id: line.product_snapshot?.operation_nature?.id ?? null,
        accepted_amounts: line.product_snapshot?.amounts?.line_total
          ? {
              quantity: line.product_snapshot.amounts.quantity ?? line.quantity,
              unit_cost: line.product_snapshot.amounts.unit_cost ?? line.unit_cost,
              discount_amount: line.product_snapshot.amounts.discount_amount ?? line.discount_amount ?? '0.0000',
              tax_amount: line.product_snapshot.amounts.tax_amount ?? line.tax_amount ?? '0.0000',
              line_total: line.product_snapshot.amounts.line_total
            }
          : undefined
      }))
    }
  }
  editOpen.value = true
}
async function saveDraft(payload: ErpPurchaseDraftPayload, editing: boolean) {
  const token = begin(editing ? 'edit' : 'create')
  const draft = editing ? editDraft.value : createDraft.value
  const previousKey = pendingOperationKey()
  const request = purchase.saveDraft(payload as unknown as Record<string, unknown>, editing ? editPurchaseId.value : null)
  const ownerKey = trackRecoveryOwner({
    kind: editing ? 'edit' : 'create', authority: token.authority, identity: editing ? editDraftIdentity.value : String(createDraftIdentity),
    fingerprint: JSON.stringify(draft), sourceId: editing ? editPurchaseId.value : null
  }, previousKey, editing ? 'erp.purchases.update' : 'erp.purchases.store', payload)
  const result = await request
  if (ownerKey && (result !== null || pendingOperationKey() !== ownerKey)) recoveryOwners.delete(ownerKey)
  if (result !== null && currentModal(token)) {
    createOpen.value = false
    editOpen.value = false
    if (!editing) {
      createDraft.value = emptyDraft()
      createDraftIdentity++
    }
    if (editing) editDraftIdentity.value = ''
  }
}
async function openEdit(row: ErpPurchase) {
  if (purchase.isPurchaseConfirmedPosted(row.id)) return
  pendingEditSourceId = row.id
  try {
    const detail = await purchase.inspect(row.id)
    if (!detail || detail.status !== 'draft' || !allowed('erp.purchases.update')) return
    startEdit(detail)
    await nextTick()
  } finally {
    if (pendingEditSourceId === row.id) pendingEditSourceId = null
  }
}
async function openDetail(row: ErpPurchase) {
  if (!allowed('erp.purchases.show')) return
  begin('detail')
  const result = await purchase.inspect(row.id)
  if (result) detailOpen.value = true
}
function closeDetail() {
  begin('detail')
  detailOpen.value = false
  returnOpen.value = false
  purchase.closeDetail()
}
function startReceipt() {
  const selected = purchase.selected.value
  if (!selected || selected.status !== 'draft' || purchase.isPurchaseConfirmedPosted(selected.id) || !allowed('erp.purchases.post')) return
  begin('post')
  postOpen.value = true
}
async function confirmReceipt() {
  const token = begin('post')
  const selected = purchase.selected.value
  if (!selected || selected.status !== 'draft') return
  const payload = { draft_revision: selected.draft_revision, expected_total: selected.total_amount }
  const previousKey = pendingOperationKey()
  const request = purchase.postReceipt(selected)
  const ownerKey = trackRecoveryOwner({ kind: 'post', authority: token.authority, identity: `${selected.id}:${selected.draft_revision ?? ''}`, fingerprint: '', sourceId: selected.id }, previousKey, 'erp.purchases.post', payload)
  const result = await request
  if (ownerKey && (result !== null || pendingOperationKey() !== ownerKey)) recoveryOwners.delete(ownerKey)
  if (result !== null && currentModal(token)) postOpen.value = false
}
function startReturn() {
  if (!purchase.selected.value || !allowed('erp.purchase-returns.store')) return
  begin('return')
  purchase.selectedReturn.value = null
  const identity = `${purchase.authorityGeneration.value}:${purchase.selected.value.id}`
  if (returnEntryIdentity.value !== identity || returnEntryIdentity.value.endsWith(':posted')) {
    returnEntryIdentity.value = identity
    returnEntry.value = { quantities: {}, reason: '' }
  }
  returnOpen.value = true
}
async function createReturn(quantities: Record<string, string>, reason: string) {
  const token = begin('return')
  const selected = purchase.selected.value
  if (!selected) return
  const result = await purchase.createReturn(selected, quantities, reason)
  if (result !== null && currentModal(token)) {
    returnEntry.value = { quantities: {}, reason: '' }
    returnEntryIdentity.value = `${purchase.authorityGeneration.value}:${selected.id}:draft`
    returnOpen.value = true
  }
}
async function postReturn() {
  const token = begin('return')
  const selectedReturn = purchase.selectedReturn.value
  if (!selectedReturn) return
  const result = await purchase.postReturn(selectedReturn)
  if (result !== null && currentModal(token)) {
    returnEntry.value = { quantities: {}, reason: '' }
    returnEntryIdentity.value = `${purchase.authorityGeneration.value}:${selectedReturn.erp_purchase_id}:posted`
    returnOpen.value = true
  }
}
function startXml() {
  if (!allowed('erp.purchases.xml-preview')) return
  begin('xml')
  xmlEstablishmentId.value = purchase.previewState.value?.erp_establishment_id ?? null
  purchase.clearOperationNotice()
  void setArea('xml-preview')
}
async function uploadXml(file: File, establishmentId: number) {
  xmlUploading.value = true
  try {
    await purchase.inspectXml(file, establishmentId)
  } finally {
    xmlUploading.value = false
  }
}
async function refreshXml() {
  xmlRefreshing.value = true
  try {
    await purchase.refreshXmlPreview()
  } finally {
    xmlRefreshing.value = false
  }
}
async function confirmXml() {
  const token = begin('xml')
  const result = await purchase.confirmXml()
  if (result !== null && currentModal(token)) {
    if (purchase.selected.value) detailOpen.value = true
  }
}
function cancelXml() {
  begin('xml')
  purchase.cancelXmlPreview()
}
async function inspectReturnFromHistory(id: number) {
  const sourceId = purchase.selected.value?.id ?? null
  const token = begin('return', sourceId)
  const result = await purchase.inspectReturn(id, sourceId)
  await nextTick()
  if (result && currentModal(token)) returnOpen.value = true
}
async function inspectReturnFromIndex(row: ErpPurchaseReturn) {
  const sourceId = row.erp_purchase_id
  const token = begin('return', sourceId, !allowed('erp.purchases.show'))
  pendingReturnSourceId = sourceId
  try {
    const result = await purchase.inspectReturn(row.id, purchase.selected.value?.id ?? null)
    await nextTick()
    if (result && currentModal(token)) returnOpen.value = true
  } finally {
    if (pendingReturnSourceId === sourceId) pendingReturnSourceId = null
  }
}
watch([createOpen, editOpen, returnOpen, postOpen], ([create, edit, returns, post], previous) => {
  const kinds = ['create', 'edit', 'return', 'post'] as const
  const current = [create, edit, returns, post]
  current.forEach((isOpen, index) => {
    if (previous[index] && !isOpen) begin(kinds[index]!)
  })
})
watch(purchase.authorityGeneration, () => {
  recoveryOwners.clear()
  for (const key of Object.keys(modalAttempt) as Array<keyof typeof modalAttempt>) begin(key)
  createOpen.value = false
  editOpen.value = false
  detailOpen.value = false
  returnOpen.value = false
  postOpen.value = false
  xmlEstablishmentId.value = null
  createDraft.value = emptyDraft()
  createDraftIdentity++
  editDraft.value = emptyDraft()
  draftAuthority.value = purchase.authorityGeneration.value
  editPurchaseId.value = null
  editAuthority.value = null
  editDraftIdentity.value = ''
  returnEntry.value = { quantities: {}, reason: '' }
  returnEntryIdentity.value = ''
  if (!areaAllowed(selectedArea.value)) {
    const fallback: PurchaseArea = areaAllowed('purchases') ? 'purchases' : areaAllowed('returns') ? 'returns' : 'xml-preview'
    void setArea(fallback)
  }
})
watch(() => purchase.selected.value?.id, (id, previous) => {
  if (previous !== undefined && id !== previous) {
    const expectedReturnTransition = id !== undefined && id === pendingReturnSourceId
    const expectedEditTransition = id !== undefined && id === pendingEditSourceId
    for (const key of ['detail', 'post'] as const) begin(key)
    if (!expectedEditTransition) begin('edit')
    if (!expectedReturnTransition) begin('return')
    if (!expectedEditTransition) editOpen.value = false
    detailOpen.value = false
    if (!expectedReturnTransition) returnOpen.value = false
    postOpen.value = false
    editDraftIdentity.value = ''
    returnEntry.value = { quantities: {}, reason: '' }
    returnEntryIdentity.value = ''
  }
})
watch(purchase.staleRevisionRefreshed, (result) => {
  if (!result || purchase.selected.value?.id !== result.id) return
  if (editOpen.value && editPurchaseId.value === result.id && editAuthority.value === purchase.authorityGeneration.value) {
    editOpen.value = false
    editAuthority.value = null
    editDraftIdentity.value = ''
    purchase.mutationError.value = 'A revisão desatualizada foi rejeitada. Reabra o rascunho para conferir a versão atual antes de editar novamente.'
    begin('edit')
  }
  if (result.status === 'posted' && postOpen.value && purchase.selected.value.id === result.id) {
    postOpen.value = false
    begin('post')
  }
})
watch(() => route.hash, (hash) => {
  const area = areaFromHash(hash)
  if (!area || !areaAllowed(area)) {
    const fallback: PurchaseArea = areaAllowed('purchases') ? 'purchases' : areaAllowed('returns') ? 'returns' : 'xml-preview'
    void setArea(fallback)
    return
  }
  if (selectedArea.value !== area) void setArea(area, false)
}, { flush: 'sync' })
onMounted(() => {
  const requested = areaFromHash(route.hash)
  const initial = requested && areaAllowed(requested) ? requested : areaAllowed('purchases') ? 'purchases' : areaAllowed('returns') ? 'returns' : 'xml-preview'
  void setArea(initial)
})
</script>

<template>
  <ErpCatalogPageShell title="Compras" description="Entradas de fornecedor, conferência de XML e devoluções vinculadas ao histórico recebido.">
    <template #actions>
      <UButton
        v-if="selectedArea === 'purchases' && allowed('erp.purchases.xml-preview')"
        icon="i-lucide-file-code-2"
        aria-label="Conferir XML"
        @click="startXml"
      >
        <span class="hidden sm:inline">Conferir XML</span>
      </UButton>
      <UButton
        v-if="selectedArea === 'purchases' && allowed('erp.purchases.store')"
        icon="i-lucide-plus"
        aria-label="Nova compra"
        @click="startCreate"
      >
        <span class="hidden sm:inline">Nova compra</span>
      </UButton>
    </template>
    <template #toolbar>
      <UNavigationMenu
        :items="areaLinks"
        highlight
        aria-label="Áreas de compras"
        class="min-w-0 flex-1 overflow-x-auto"
      />
      <div v-if="selectedArea === 'purchases'" class="flex w-full flex-wrap items-center gap-2 pt-2">
        <UInput
          v-model="purchase.filters.search"
          aria-label="Buscar compras"
          placeholder="Documento ou fornecedor"
          class="min-w-48 flex-1"
          @keyup.enter="purchase.filters.page = 1; purchase.load()"
        />
        <USelect
          :model-value="purchase.filters.status || 'all'"
          aria-label="Filtrar por situação"
          :items="statusOptions"
          @update:model-value="purchase.filters.status = $event === 'all' ? '' : String($event); purchase.filters.page = 1; purchase.load()"
        />
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Atualizar"
          :loading="purchase.listLoading.value"
          @click="purchase.load"
        />
      </div>
      <div v-else-if="selectedArea === 'returns'" class="flex w-full flex-wrap items-center gap-2 pt-2">
        <USelect
          :model-value="purchase.returnFilters.status || 'all'"
          aria-label="Filtrar devoluções por situação"
          :items="[{ label: 'Todas as situações', value: 'all' }, { label: 'Preparada', value: 'draft' }, { label: 'Lançada', value: 'posted' }]"
          @update:model-value="purchase.returnFilters.status = $event === 'all' ? '' : String($event); purchase.returnFilters.page = 1; purchase.loadReturns()"
        />
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Atualizar devoluções"
          :loading="purchase.returnsLoading.value"
          @click="purchase.loadReturns"
        />
      </div>
    </template>
    <UAlert
      v-if="selectedArea === 'purchases' && purchase.listError.value"
      color="error"
      :title="purchase.listError.value"
      :description="purchase.meta.value ? 'A atualização falhou. Exibindo a última lista confirmada.' : 'A lista ainda não foi carregada com sucesso.'"
    />
    <UAlert v-if="purchase.referenceError.value" color="warning" :title="purchase.referenceError.value">
      <template #actions>
        <UButton
          size="xs"
          color="neutral"
          variant="outline"
          label="Atualizar referências"
          :loading="purchase.referencesLoading.value"
          @click="purchase.loadReferences"
        />
      </template>
    </UAlert>
    <UAlert v-if="purchase.mutationError.value && !createOpen && !editOpen && !returnOpen && !postOpen" :color="errorColor()" :title="purchase.mutationError.value">
      <template #actions>
        <div v-if="purchase.selectedOperation.value" class="flex flex-wrap gap-2">
          <UButton
            size="xs"
            color="neutral"
            variant="outline"
            label="Consultar operação"
            :loading="purchase.mutating.value"
            @click="reconcileOperation"
          />
          <UButton
            v-if="purchase.canRetryExact.value"
            size="xs"
            label="Repetir conteúdo original"
            :loading="purchase.mutating.value"
            @click="retryOperation"
          />
        </div>
        <UButton
          v-else
          size="xs"
          color="neutral"
          variant="ghost"
          label="Dispensar aviso"
          @click="purchase.clearOperationNotice"
        />
      </template>
    </UAlert>
    <div v-if="selectedArea === 'purchases'" class="overflow-hidden rounded-lg border border-default">
      <UTable
        :data="purchase.purchases.value"
        :columns="columns"
        :loading="purchase.listLoading.value"
        :empty="emptyLabel"
      >
        <template #supplier-cell="{ row }">
          <span>{{ supplierName(row.original) }}</span>
        </template>
        <template #amount-cell="{ row }">
          <span class="tabular-nums">{{ money(row.original.total_amount, row.original.currency) }}</span>
        </template>
        <template #status-cell="{ row }">
          <UBadge :color="row.original.status === 'posted' ? 'success' : 'neutral'" variant="subtle">
            {{ row.original.status === 'posted' ? 'Lançada' : 'Rascunho' }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex justify-end gap-1">
            <UButton
              v-if="allowed('erp.purchases.show')"
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-eye"
              :aria-label="`Abrir compra ${row.original.document_no ?? row.original.id}`"
              @click="openDetail(row.original)"
            />
            <UButton
              v-if="row.original.status === 'draft' && !purchase.isPurchaseConfirmedPosted(row.original.id) && allowed('erp.purchases.update')"
              size="xs"
              color="neutral"
              variant="outline"
              icon="i-lucide-pencil"
              :aria-label="`Editar compra ${row.original.document_no ?? row.original.id}`"
              @click="openEdit(row.original)"
            />
          </div>
        </template>
      </UTable>
    </div>
    <div v-if="selectedArea === 'purchases'" class="flex items-center justify-between gap-3 text-sm text-muted">
      <span>{{ purchase.meta.value?.total ?? purchase.purchases.value.length }} compras · página {{ purchase.meta.value?.current_page ?? 1 }} de {{ purchase.meta.value?.last_page ?? 1 }}</span>
      <div class="flex gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Anterior"
          :disabled="!purchase.meta.value || purchase.meta.value.current_page <= 1 || purchase.listLoading.value"
          @click="purchase.filters.page--; purchase.load()"
        />
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Próxima"
          :disabled="!purchase.meta.value || purchase.meta.value.current_page >= purchase.meta.value.last_page || purchase.listLoading.value"
          @click="purchase.filters.page++; purchase.load()"
        />
      </div>
    </div>

    <section v-if="selectedArea === 'xml-preview'" class="space-y-3 rounded-lg border border-default p-4">
      <h2 class="font-semibold text-highlighted">
        Conferir documento fiscal de entrada
      </h2>
      <ErpPurchaseXmlPreview
        v-model="purchase.previewState.value"
        v-model:establishment-id="xmlEstablishmentId"
        :establishments="purchase.establishments.value"
        :suppliers="purchase.suppliers.value"
        :variants="purchase.variants.value"
        :units="purchase.units.value"
        :loading="xmlUploading || purchase.mutating.value"
        :refreshing="xmlRefreshing"
        :error="purchase.mutationError.value || undefined"
        :field-errors="purchase.fieldErrors.value"
        @upload="uploadXml"
        @refresh="refreshXml"
        @confirm="confirmXml"
        @cancel="cancelXml"
      />
    </section>

    <section v-if="selectedArea === 'returns'" class="space-y-4">
      <UAlert
        v-if="purchase.returnListError.value"
        color="error"
        :title="purchase.returnListError.value"
        :description="purchase.returnMeta.value ? 'A atualização falhou. Exibindo a última lista confirmada.' : 'A lista ainda não foi carregada com sucesso.'"
      />
      <div class="overflow-hidden rounded-lg border border-default">
        <UTable
          :data="purchase.returns.value"
          :columns="[{ accessorKey: 'id', header: 'Devolução' }, { accessorKey: 'erp_purchase_id', header: 'Compra de origem' }, { accessorKey: 'reason', header: 'Motivo' }, { accessorKey: 'status', header: 'Situação' }, { id: 'amount', header: 'Total' }, { id: 'actions' }]"
          :loading="purchase.returnsLoading.value"
          :empty="purchase.returnsLoading.value ? 'Carregando devoluções…' : purchase.returnMeta.value ? 'Nenhuma devolução encontrada.' : 'A lista ainda não foi carregada.'"
        >
          <template #status-cell="{ row }">
            <UBadge :color="row.original.status === 'posted' ? 'success' : 'neutral'" variant="subtle">
              {{ row.original.status === 'posted' ? 'Lançada' : 'Preparada' }}
            </UBadge>
          </template>
          <template #amount-cell="{ row }">
            <span class="tabular-nums">{{ money(row.original.total_amount, row.original.currency) }}</span>
          </template>
          <template #actions-cell="{ row }">
            <UButton
              v-if="allowed('erp.purchase-returns.show')"
              size="xs"
              color="neutral"
              variant="outline"
              label="Abrir devolução"
              @click="inspectReturnFromIndex(row.original)"
            />
          </template>
        </UTable>
      </div>
      <div class="flex items-center justify-between gap-3 text-sm text-muted">
        <span>{{ purchase.returnMeta.value?.total ?? purchase.returns.value.length }} devoluções · página {{ purchase.returnMeta.value?.current_page ?? 1 }} de {{ purchase.returnMeta.value?.last_page ?? 1 }}</span>
        <div class="flex gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            label="Anterior"
            :disabled="!purchase.returnMeta.value || purchase.returnMeta.value.current_page <= 1 || purchase.returnsLoading.value"
            @click="purchase.returnFilters.page--; purchase.loadReturns()"
          />
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            label="Próxima"
            :disabled="!purchase.returnMeta.value || purchase.returnMeta.value.current_page >= purchase.returnMeta.value.last_page || purchase.returnsLoading.value"
            @click="purchase.returnFilters.page++; purchase.loadReturns()"
          />
        </div>
      </div>
    </section>

    <UModal v-model:open="createOpen" title="Nova compra" :ui="{ content: 'sm:max-w-5xl' }">
      <template #body>
        <ErpPurchaseForm
          :key="`create-${purchase.authorityGeneration.value}`"
          v-model="createDraft"
          :suppliers="purchase.suppliers.value"
          :establishments="purchase.establishments.value"
          :variants="purchase.variants.value"
          :units="purchase.units.value"
          :field-errors="purchase.fieldErrors.value"
          :loading="purchase.mutating.value"
          @submit="saveDraft($event, false)"
          @cancel="createOpen = false"
        />
        <ErpPurchaseOperationRecovery
          :message="purchase.mutationError.value"
          :color="errorColor()"
          :pending="Boolean(purchase.selectedOperation.value)"
          :can-retry="purchase.canRetryExact.value"
          :busy="purchase.mutating.value"
          @reconcile="reconcileOperation"
          @retry="retryOperation"
          @dismiss="purchase.clearOperationNotice"
        />
      </template>
    </UModal>
    <UModal v-model:open="editOpen" title="Editar rascunho" :ui="{ content: 'sm:max-w-5xl' }">
      <template #body>
        <ErpPurchaseForm
          v-if="purchase.selected.value"
          :key="`edit-${purchase.selected.value.id}-${purchase.selected.value.draft_revision}-${purchase.authorityGeneration.value}`"
          v-model="editDraft"
          :suppliers="purchase.suppliers.value"
          :establishments="purchase.establishments.value"
          :variants="purchase.variants.value"
          :units="purchase.units.value"
          :field-errors="purchase.fieldErrors.value"
          :loading="purchase.mutating.value"
          :xml-origin="Boolean(purchase.selected.value.source_access_key)"
          @submit="saveDraft($event, true)"
          @cancel="editOpen = false"
        />
        <ErpPurchaseOperationRecovery
          :message="purchase.mutationError.value"
          :color="errorColor()"
          :pending="Boolean(purchase.selectedOperation.value)"
          :can-retry="purchase.canRetryExact.value"
          :busy="purchase.mutating.value"
          @reconcile="reconcileOperation"
          @retry="retryOperation"
          @dismiss="purchase.clearOperationNotice"
        />
      </template>
    </UModal>
    <UModal
      v-model:open="detailOpen"
      :title="`Compra #${purchase.selected.value?.id ?? ''}`"
      :ui="{ content: 'sm:max-w-5xl' }"
      @update:open="value => !value && closeDetail()"
    >
      <template #body>
        <div v-if="purchase.selected.value" class="space-y-5">
          <UAlert v-if="purchase.detailError.value" color="error" :title="purchase.detailError.value" />
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-sm text-muted">
                {{ supplierName(purchase.selected.value) }} · {{ purchase.selected.value.document_no || 'Sem número de documento' }}
              </p><p class="mt-1 text-lg font-semibold tabular-nums">
                {{ money(purchase.selected.value.total_amount, purchase.selected.value.currency) }}
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <UButton
                v-if="purchase.selected.value.status === 'draft' && !purchase.isPurchaseConfirmedPosted(purchase.selected.value.id) && allowed('erp.purchases.update')"
                color="neutral"
                variant="outline"
                icon="i-lucide-pencil"
                label="Editar rascunho"
                @click="startEdit(purchase.selected.value)"
              />
              <UButton
                v-if="purchase.selected.value.status === 'draft' && !purchase.isPurchaseConfirmedPosted(purchase.selected.value.id) && allowed('erp.purchases.post')"
                icon="i-lucide-package-check"
                label="Lançar recebimento"
                @click="startReceipt"
              />
              <UButton
                v-if="purchase.selected.value.status === 'posted' && allowed('erp.purchase-returns.store')"
                color="neutral"
                variant="outline"
                icon="i-lucide-rotate-ccw"
                label="Preparar devolução"
                @click="startReturn"
              />
              <UButton
                color="neutral"
                variant="ghost"
                icon="i-lucide-refresh-cw"
                aria-label="Recarregar detalhe atual"
                :loading="purchase.loadingDetail.value"
                @click="purchase.inspect(purchase.selected.value!.id)"
              />
            </div>
          </div>
          <UAlert v-if="purchase.selected.value.source_access_key" color="info" title="Documento de origem XML">
            Chave {{ purchase.selected.value.source_access_key }} · confirmação de XML não lança o estoque.
          </UAlert>
          <UAlert v-if="purchase.selected.value.needs_reconciliation || purchase.selected.value.returnability?.needs_reconciliation" color="warning" title="Conferência necessária">
            {{ purchase.selected.value.returnability?.reconciliation_reason || purchase.selected.value.finance?.reconciliation_reason || 'Alguns saldos não podem ser calculados com segurança.' }}
          </UAlert>
          <UAlert v-if="purchase.isPurchaseConfirmedPosted(purchase.selected.value.id) && purchase.selected.value.status === 'draft'" color="warning" title="Lançamento confirmado">
            A compra foi lançada, mas a leitura atual não confirmou a situação. Atualize o detalhe antes de continuar.
          </UAlert>
          <section class="space-y-2">
            <h3 class="font-semibold text-highlighted">
              Itens da compra
            </h3>
            <div v-for="line in purchase.selected.value.lines" :key="line.id" class="grid gap-2 rounded-md border border-default p-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto]">
              <div>
                <p class="font-medium">
                  {{ line.description }}
                </p><p class="text-muted">
                  {{ line.quantity }} · fator {{ line.unit_conversion_factor }} · linha #{{ line.id }}
                </p>
              </div>
              <p class="tabular-nums">
                {{ money(line.line_total, purchase.selected.value.currency) }}
              </p>
            </div>
          </section>
          <section v-if="purchase.selected.value.status === 'posted'" class="space-y-3 rounded-lg bg-elevated/50 p-4">
            <h3 class="font-semibold text-highlighted">
              Obrigação e créditos vinculados
            </h3>
            <div class="grid gap-3 text-sm sm:grid-cols-3">
              <p>Aberto original<br><strong class="tabular-nums">{{ money(purchase.selected.value.finance?.gross_original_due, purchase.selected.value.finance?.currency ?? purchase.selected.value.currency) }}</strong></p>
              <p>Crédito em aberto<br><strong class="tabular-nums">{{ money(purchase.selected.value.finance?.open_return_credit, purchase.selected.value.finance?.currency ?? purchase.selected.value.currency) }}</strong></p>
              <p>Crédito oposto total<br><strong class="tabular-nums">{{ money(purchase.selected.value.finance?.opposite_return_credit, purchase.selected.value.finance?.currency ?? purchase.selected.value.currency) }}</strong></p>
              <p>Exposição líquida assinada<br><strong class="tabular-nums">{{ money(purchase.selected.value.finance?.net_exposure, purchase.selected.value.finance?.currency ?? purchase.selected.value.currency) }}</strong></p>
              <p>Vencimento original<br><strong>{{ purchase.selected.value.finance?.original_due_on ?? 'Indisponível' }}</strong></p>
              <p v-if="allowed('erp.obligations.index')">
                Obrigação #{{ purchase.selected.value.finance?.original_obligation_id ?? '—' }} ·
                <NuxtLink to="/erp/financeiro#obligations" class="text-primary underline">Ver obrigações</NuxtLink>
              </p>
            </div>
            <p class="text-xs text-muted">
              Sem compensação ou reembolso automático. Os créditos do fornecedor permanecem separados dos pagamentos originais.
            </p>
            <ul v-if="purchase.selected.value.finance?.return_credits?.length" class="space-y-2 text-sm">
              <li v-for="credit in purchase.selected.value.finance.return_credits" :key="credit.id" class="flex flex-wrap justify-between gap-2 border-t border-default pt-2">
                <span>Crédito #{{ credit.id }} · origem {{ credit.source_type }} #{{ credit.source_id }}</span><span class="tabular-nums">{{ money(credit.open_amount, purchase.selected.value.finance!.currency) }} em aberto</span>
              </li>
            </ul>
          </section>
          <section v-if="purchase.selected.value.status === 'posted' && purchase.selected.value.returnable_lines?.length" class="space-y-2">
            <h3 class="font-semibold text-highlighted">
              Saldos de quantidade para devolução
            </h3>
            <div v-for="line in purchase.selected.value.returnable_lines" :key="line.source_line_id" class="flex flex-wrap justify-between gap-2 text-sm">
              <span>Linha #{{ line.source_line_id }} · fator {{ line.unit_conversion_factor }}</span><span>Original {{ line.quantity }} · devolvido {{ line.returned_quantity ?? '—' }} · disponível {{ line.remaining_quantity ?? 'conciliação necessária' }}</span>
            </div>
          </section>
          <section v-if="purchase.selected.value.status === 'posted' && purchase.selected.value.finance?.return_credits?.length" class="space-y-2">
            <h3 class="font-semibold text-highlighted">
              Histórico de devoluções
            </h3>
            <div v-for="credit in purchase.selected.value.finance.return_credits" :key="`return-${credit.source_id}`" class="flex flex-wrap items-center justify-between gap-2 border-t border-default pt-2 text-sm">
              <span>Devolução #{{ credit.source_id }} · crédito #{{ credit.id }} · {{ money(credit.amount, purchase.selected.value.finance?.currency ?? purchase.selected.value.currency) }}</span>
              <UButton
                v-if="allowed('erp.purchase-returns.show')"
                size="xs"
                color="neutral"
                variant="outline"
                label="Abrir devolução"
                @click="inspectReturnFromHistory(credit.source_id)"
              />
            </div>
          </section>
        </div>
      </template>
    </UModal>
    <UModal v-model:open="returnOpen" title="Devolução de fornecedor" :ui="{ content: 'sm:max-w-4xl' }">
      <template #body>
        <ErpPurchaseReturnForm
          v-if="purchase.selected.value && (!purchase.selectedReturn.value || purchase.selected.value.id === purchase.selectedReturn.value.erp_purchase_id)"
          :key="`return-${purchase.selected.value.id}-${purchase.authorityGeneration.value}`"
          v-model="returnEntry"
          :purchase="purchase.selected.value"
          :return-draft="purchase.selectedReturn.value"
          :field-errors="purchase.fieldErrors.value"
          :loading="purchase.mutating.value || purchase.loadingDetail.value"
          :return-allowed="allowed('erp.purchase-returns.store')"
          :post-allowed="allowed('erp.purchase-returns.post')"
          @create="createReturn"
          @post="postReturn"
          @cancel="returnOpen = false; purchase.selectedReturn.value = null"
        />
        <ErpPurchaseOperationRecovery
          v-if="purchase.selected.value && (!purchase.selectedReturn.value || purchase.selected.value.id === purchase.selectedReturn.value.erp_purchase_id)"
          :message="purchase.mutationError.value"
          :color="errorColor()"
          :pending="Boolean(purchase.selectedOperation.value)"
          :can-retry="purchase.canRetryExact.value"
          :busy="purchase.mutating.value"
          @reconcile="reconcileOperation"
          @retry="retryOperation"
          @dismiss="purchase.clearOperationNotice"
        />
        <section v-if="purchase.selectedReturn.value && (!purchase.selected.value || purchase.selected.value.id !== purchase.selectedReturn.value.erp_purchase_id)" class="space-y-4 rounded-lg border border-default p-4" aria-label="Detalhes da devolução">
          <ErpPurchaseOperationRecovery
            :message="purchase.mutationError.value"
            :color="errorColor()"
            :pending="Boolean(purchase.selectedOperation.value)"
            :can-retry="purchase.canRetryExact.value"
            :busy="purchase.mutating.value"
            @reconcile="reconcileOperation"
            @retry="retryOperation"
            @dismiss="purchase.clearOperationNotice"
          />
          <p class="text-sm text-muted">
            Devolução #{{ purchase.selectedReturn.value.id }} · compra de origem #{{ purchase.selectedReturn.value.erp_purchase_id }}
          </p>
          <p class="font-medium">
            {{ purchase.selectedReturn.value.reason }}
          </p>
          <p class="tabular-nums">
            {{ money(purchase.selectedReturn.value.total_amount, purchase.selectedReturn.value.currency) }}
          </p>
          <div v-for="line in purchase.selectedReturn.value.lines ?? []" :key="line.id" class="flex justify-between gap-3 border-t border-default pt-2 text-sm">
            <span>Linha de origem #{{ line.source_line_id }} · quantidade {{ line.quantity }}</span>
            <span class="tabular-nums">{{ money(line.total_amount, purchase.selectedReturn.value.currency) }}</span>
          </div>
          <UAlert v-if="purchase.selectedReturn.value.status === 'posted'" color="success" title="Devolução lançada; o crédito do fornecedor permanece separado dos pagamentos originais." />
          <UButton
            v-else-if="allowed('erp.purchase-returns.post')"
            label="Lançar devolução"
            :disabled="purchase.mutating.value"
            @click="postReturn()"
          />
        </section>
      </template>
    </UModal>
    <UModal v-model:open="postOpen" title="Lançar recebimento" :ui="{ content: 'sm:max-w-xl' }">
      <template #body>
        <div v-if="purchase.selected.value" class="space-y-4">
          <UAlert color="warning" title="Confirmação de estoque e financeiro">
            O lançamento registra o recebimento e cria a obrigação correspondente em uma única operação. Esta ação não pode ser desfeita pela edição do documento.
          </UAlert>
          <ErpPurchaseOperationRecovery
            :message="purchase.mutationError.value"
            :color="errorColor()"
            :pending="Boolean(purchase.selectedOperation.value)"
            :can-retry="purchase.canRetryExact.value"
            :busy="purchase.mutating.value"
            @reconcile="reconcileOperation"
            @retry="retryOperation"
            @dismiss="purchase.clearOperationNotice"
          />
          <p class="text-sm">
            Compra #{{ purchase.selected.value.id }} · total {{ money(purchase.selected.value.total_amount, purchase.selected.value.currency) }}
          </p>
          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="outline"
              label="Voltar"
              @click="postOpen = false"
            /><UButton
              label="Confirmar recebimento"
              :loading="purchase.mutating.value"
              :disabled="purchase.mutating.value || purchase.selected.value.status !== 'draft' || purchase.isPurchaseConfirmedPosted(purchase.selected.value.id)"
              @click="confirmReceipt"
            />
          </div>
        </div>
      </template>
    </UModal>
  </ErpCatalogPageShell>
</template>
