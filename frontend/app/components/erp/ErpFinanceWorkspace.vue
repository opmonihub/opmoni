<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ErpFinancialAccount, ErpObligation, ErpPayment, ErpPaymentAllocation } from '#shared/types/erpBusiness'
import { formatErpMoney, decimalTextIsValid } from '#shared/utils/erpFinance'
import { useErpFinanceWorkspace } from '~/composables/useErpFinanceWorkspace'

type FinanceRow = ErpFinancialAccount | ErpObligation | ErpPayment
type FinanceTab = 'accounts' | 'obligations' | 'payments'
type CreateEditor = FinanceTab
const financeTabs: { id: FinanceTab, label: string }[] = [
  { id: 'accounts', label: 'Contas' },
  { id: 'obligations', label: 'Obrigações' },
  { id: 'payments', label: 'Pagamentos' }
]
const createActions: Record<CreateEditor, string> = {
  accounts: 'erp.financial-accounts.store',
  obligations: 'erp.obligations.store',
  payments: 'erp.payments.store'
}
const createPaths: Record<CreateEditor, string> = {
  accounts: '/erp/financial-accounts',
  obligations: '/erp/obligations',
  payments: '/erp/payments'
}
const createAreas: Record<CreateEditor, FinanceTab> = { accounts: 'accounts', obligations: 'obligations', payments: 'payments' }
const route = useRoute()
const router = useRouter()
function areaFromHash(hash: string): FinanceTab {
  const candidate = hash.replace(/^#/, '')
  return financeTabs.some(tab => tab.id === candidate) ? candidate as FinanceTab : 'obligations'
}
const finance = useErpFinanceWorkspace(areaFromHash(route.hash))
const createEditor = ref<CreateEditor | null>(null)
const createEditorOpener = ref<HTMLElement | null>(null)
const dialogOpen = ref(false)
const dialogAuthority = ref('')
const dialogOpenModel = computed({
  get: () => dialogOpen.value,
  set: (open: boolean) => {
    dialogOpen.value = open
    if (!open) clearActionTarget()
  }
})
const receiptOpen = computed({
  get: () => finance.receipt.value !== null,
  set: (open: boolean) => {
    if (!open)
      finance.closeReceipt()
  }
})
const action = ref<'allocate' | 'reverse-allocation' | 'reverse-payment' | null>(null)
const targetPayment = ref<ErpPayment | null>(null)
const targetAllocation = ref<ErpPaymentAllocation | null>(null)
const actionState = reactive({ erp_obligation_id: 'none', amount: '', reason: '', erp_cash_session_id: 'none' })
const actionError = ref('')
const actionFeedback = computed(() => actionError.value || Object.values(finance.fieldErrors.value).join(' '))
const selectedPayment = computed(() => finance.selected.value && 'effective_status' in finance.selected.value ? finance.selected.value : null)
const selectedObligation = computed(() => finance.selected.value && 'open_amount' in finance.selected.value ? finance.selected.value : null)
const selectedAccount = computed(() => finance.selected.value && 'account_type' in finance.selected.value ? finance.selected.value : null)
const titles = { accounts: 'Contas financeiras', obligations: 'Obrigações', payments: 'Pagamentos' }
const createTitles: Record<CreateEditor, string> = { accounts: 'Nova conta financeira', obligations: 'Nova obrigação', payments: 'Novo pagamento' }
const createButtonLabels: Record<CreateEditor, string> = { accounts: 'Nova conta', obligations: 'Nova obrigação', payments: 'Novo pagamento' }
const accountTypeLabels: Record<string, string> = { saving_current: 'Conta corrente ou poupança', cash: 'Caixa', investment: 'Investimento', other: 'Outro' }
const financeStatusLabels: Record<string, string> = { active: 'Ativa', archived: 'Arquivada', open: 'Em aberto', settled: 'Liquidada', reversed: 'Estornada', posted: 'Postado', compensation: 'Compensação' }
const paymentMethodLabels: Record<string, string> = { bank_transfer: 'Transferência', card: 'Cartão', cash: 'Dinheiro', other: 'Outro' }
const directionFilter = computed({ get: () => finance.filters.direction || 'all', set: (value: string) => finance.filters.direction = value === 'all' ? '' : value })
const statusFilter = computed({ get: () => finance.filters.status || 'all', set: (value: string) => finance.filters.status = value === 'all' ? '' : value })
const establishmentFilter = computed({ get: () => finance.filters.erp_establishment_id || 'all', set: (value: string) => finance.filters.erp_establishment_id = value === 'all' ? '' : value })
const accountFilter = computed({ get: () => finance.filters.erp_financial_account_id || 'all', set: (value: string) => finance.filters.erp_financial_account_id = value === 'all' ? '' : value })
const filtersForTab = computed(() => finance.tab.value === 'accounts'
  ? [{ label: 'Todas', value: 'all' }, { label: 'Ativas', value: 'active' }, { label: 'Arquivadas', value: 'archived' }]
  : finance.tab.value === 'obligations'
    ? [{ label: 'Todas', value: 'all' }, { label: 'Em aberto', value: 'open' }, { label: 'Liquidadas', value: 'settled' }, { label: 'Estornadas', value: 'reversed' }]
    : [{ label: 'Todas', value: 'all' }, { label: 'Postados', value: 'posted' }, { label: 'Estornados', value: 'reversed' }, { label: 'Compensações', value: 'compensation' }])
const directionOptions = computed(() => finance.tab.value === 'obligations'
  ? [{ label: 'Todas', value: 'all' }, { label: 'A receber', value: 'receivable' }, { label: 'A pagar', value: 'payable' }]
  : [{ label: 'Todas', value: 'all' }, { label: 'Entradas', value: 'in' }, { label: 'Saídas', value: 'out' }, ...(finance.tab.value === 'payments' ? [{ label: 'Direção não conciliada', value: 'unknown' }] : [])])
const columns = computed<TableColumn<FinanceRow>[]>(() => finance.tab.value === 'accounts'
  ? [{ accessorKey: 'name', header: 'Conta' }, { accessorKey: 'account_type', header: 'Tipo' }, { accessorKey: 'currency', header: 'Moeda' }, { accessorKey: 'status', header: 'Situação' }, { id: 'actions' }]
  : finance.tab.value === 'obligations'
    ? [{ accessorKey: 'id', header: 'Registro' }, { accessorKey: 'direction', header: 'Natureza' }, { accessorKey: 'category', header: 'Categoria' }, { accessorKey: 'amount', header: 'Valor original' }, { accessorKey: 'open_amount', header: 'Em aberto' }, { accessorKey: 'due_on', header: 'Vencimento' }, { accessorKey: 'status', header: 'Situação' }, { id: 'actions' }]
    : [{ accessorKey: 'id', header: 'Registro' }, { accessorKey: 'direction', header: 'Direção' }, { accessorKey: 'amount', header: 'Valor' }, { accessorKey: 'allocated_amount', header: 'Alocado' }, { accessorKey: 'available_amount', header: 'Disponível' }, { accessorKey: 'effective_status', header: 'Situação efetiva' }, { id: 'actions' }])
const pageTitle = computed(() => titles[finance.tab.value])
const newActionLabel = computed(() => createButtonLabels[finance.tab.value])
function labelFor(value: string, labels: Record<string, string>) {
  return labels[value] ?? `Desconhecido (${value || 'não informado'})`
}
function selectArea(next: FinanceTab) {
  if (finance.tab.value === next) return
  void router.push({ path: route.path, query: route.query, hash: `#${next}` })
}
watch(() => route.hash, (hash) => {
  const next = areaFromHash(hash)
  const canonicalHash = `#${next}`
  if (hash !== canonicalHash) {
    void router.replace({ path: route.path, query: route.query, hash: canonicalHash })
    return
  }
  if (finance.tab.value !== next)
    void finance.selectTab(next)
}, { flush: 'sync' })
onMounted(() => {
  const canonicalHash = `#${finance.tab.value}`
  if (route.hash !== canonicalHash)
    void router.replace({ path: route.path, query: route.query, hash: canonicalHash })
})
function openCreateEditor(kind: CreateEditor, event?: MouseEvent) {
  if (!finance.allowed(createActions[kind])) return
  createEditorOpener.value = (event?.currentTarget as HTMLElement | null) ?? null
  createEditor.value = kind
}
function closeCreateEditor(kind?: CreateEditor) {
  if (kind && createEditor.value !== kind) return
  createEditor.value = null
}
function setCreateEditorOpen(kind: CreateEditor, open: boolean) {
  if (open) openCreateEditor(kind)
  else closeCreateEditor(kind)
}
function focusCreateEditor() {
  const kind = createEditor.value
  if (!kind) return
  document.querySelector<HTMLElement>(`[data-finance-form="${kind === 'accounts' ? 'account' : kind === 'obligations' ? 'obligation' : 'payment'}"] input`)?.focus()
}
function restoreCreateEditorFocus() {
  const opener = createEditorOpener.value
  if (opener?.isConnected) opener.focus()
  createEditorOpener.value = null
}
async function submitCreate(kind: CreateEditor, payload: Record<string, unknown>) {
  const result = await finance.post(createPaths[kind], payload)
  if (result !== null) closeCreateEditor(kind)
}
watch(finance.tab, (tab) => {
  if (createEditor.value && createAreas[createEditor.value] !== tab)
    closeCreateEditor()
})
watch(finance.authorityGeneration, () => {
  closeCreateEditor()
  createEditorOpener.value = null
})
watch(() => createEditor.value && finance.allowed(createActions[createEditor.value]), (allowed) => {
  if (createEditor.value && !allowed) {
    closeCreateEditor()
    createEditorOpener.value = null
  }
}, { flush: 'sync' })
function money(value: string | undefined, currency: string | undefined) {
  return value && currency ? formatErpMoney(value, currency) : '—'
}
function rowDirection(row: FinanceRow) {
  if (!('direction' in row)) return '—'
  if (row.direction === 'receivable') return 'A receber'
  if (row.direction === 'payable') return 'A pagar'
  if (row.direction === 'in') return 'Entrada'
  if (row.direction === 'out') return 'Saída'
  return 'Não conciliada'
}
function rowAmount(row: FinanceRow) {
  return 'amount' in row ? row.amount : undefined
}
function rowOpenAmount(row: FinanceRow) {
  return 'open_amount' in row ? row.open_amount : undefined
}
function rowAllocatedAmount(row: FinanceRow) {
  return 'allocated_amount' in row ? row.allocated_amount : undefined
}
function rowAvailableAmount(row: FinanceRow) {
  return 'available_amount' in row ? row.available_amount : undefined
}
function clearActionTarget() {
  if (action.value === 'allocate') finance.closeCandidates()
  action.value = null
  targetPayment.value = null
  targetAllocation.value = null
  actionState.erp_obligation_id = 'none'
  actionState.amount = ''
  actionState.reason = ''
  actionState.erp_cash_session_id = 'none'
  actionError.value = ''
  finance.fieldErrors.value = {}
  dialogAuthority.value = ''
}
watch(finance.authorityGeneration, () => {
  dialogOpen.value = false
  clearActionTarget()
}, { flush: 'sync' })
watch(() => {
  if (action.value === 'allocate') return finance.allowed('erp.payments.allocate')
  if (action.value === 'reverse-allocation') return finance.allowed('erp.payment-allocations.reverse')
  if (action.value === 'reverse-payment') return finance.allowed('erp.payments.reverse')
  return true
}, (allowed) => {
  if (!allowed && dialogOpen.value) {
    dialogOpen.value = false
    clearActionTarget()
  }
}, { flush: 'sync' })
function select(row: FinanceRow) {
  if ('effective_status' in row) void finance.inspectPayment(row)
  else void finance.inspect(row)
}
function loadAllocationPage(page: number) {
  const payment = selectedPayment.value
  if (payment) void finance.loadAllocations(payment.id, page)
}
function startAllocation(payment: ErpPayment) {
  if (!finance.allowed('erp.payments.allocate')) return
  targetPayment.value = payment
  action.value = 'allocate'
  actionState.erp_obligation_id = 'none'
  actionState.amount = ''
  actionState.reason = ''
  actionError.value = ''
  dialogOpen.value = true
  dialogAuthority.value = finance.authorityGeneration.value.toString()
  void finance.loadCandidates(payment)
}
function startAllocationReversal(allocation: ErpPaymentAllocation) {
  if (!finance.allowed('erp.payment-allocations.reverse')) return
  targetPayment.value = selectedPayment.value
  action.value = 'reverse-allocation'
  targetAllocation.value = allocation
  actionState.amount = allocation.remaining_reversible
  actionState.reason = ''
  actionError.value = ''
  dialogOpen.value = true
  dialogAuthority.value = finance.authorityGeneration.value.toString()
}
function startPaymentReversal(payment: ErpPayment) {
  if (!finance.allowed('erp.payments.reverse') || payment.correction.workflow !== 'payment_reversal') return
  targetPayment.value = payment
  action.value = 'reverse-payment'
  actionState.reason = ''
  actionState.erp_cash_session_id = 'none'
  actionError.value = ''
  dialogOpen.value = true
  dialogAuthority.value = finance.authorityGeneration.value.toString()
}
async function confirmAction() {
  actionError.value = ''
  if (!dialogOpen.value || dialogAuthority.value !== finance.authorityGeneration.value.toString()) {
    dialogOpen.value = false
    clearActionTarget()
    return
  }
  const requiredAction = action.value === 'allocate' ? 'erp.payments.allocate' : action.value === 'reverse-allocation' ? 'erp.payment-allocations.reverse' : 'erp.payments.reverse'
  if (!finance.allowed(requiredAction)) {
    dialogOpen.value = false
    clearActionTarget()
    actionError.value = 'Sua autorização mudou. Reabra a operação após atualizar os dados.'
    return
  }
  if (action.value === 'allocate') {
    if (!targetPayment.value || actionState.erp_obligation_id === 'none' || !decimalTextIsValid(actionState.amount)) {
      actionError.value = 'Selecione uma obrigação e informe um valor decimal positivo.'
      return
    }
    const result = await finance.post(`/erp/payments/${targetPayment.value.id}/allocate`, { erp_obligation_id: Number(actionState.erp_obligation_id), amount: actionState.amount }, 'erp.payments.allocate')
    if (result !== null) dialogOpenModel.value = false
  } else if (action.value === 'reverse-allocation') {
    if (!actionState.reason.trim()) {
      actionError.value = 'Informe o motivo da correção.'
      return
    }
    if (!targetAllocation.value || !decimalTextIsValid(actionState.amount)) {
      actionError.value = 'Informe um valor decimal positivo para desfazer.'
      return
    }
    const result = await finance.post(`/erp/payment-allocations/${targetAllocation.value.id}/reverse`, { amount: actionState.amount, reason: actionState.reason.trim() }, 'erp.payment-allocations.reverse')
    if (result !== null) dialogOpenModel.value = false
  } else if (action.value === 'reverse-payment' && targetPayment.value) {
    if (!actionState.reason.trim()) {
      actionError.value = 'Informe o motivo da correção.'
      return
    }
    if (targetPayment.value.correction.requires_cash_target && actionState.erp_cash_session_id === 'none') {
      actionError.value = 'Selecione um caixa aberto para registrar a compensação.'
      return
    }
    const body: Record<string, unknown> = { reason: actionState.reason.trim() }
    if (actionState.erp_cash_session_id !== 'none') body.erp_cash_session_id = Number(actionState.erp_cash_session_id)
    const result = await finance.post(`/erp/payments/${targetPayment.value.id}/reverse`, body, 'erp.payments.reverse')
    if (result !== null) dialogOpenModel.value = false
  }
}
async function changeFilter() {
  finance.filters.page = 1
  await finance.load()
}
async function changePage(page: number) {
  finance.filters.page = page
  await finance.load()
}
function isObligation(row: FinanceRow): row is ErpObligation {
  return 'direction' in row && 'open_amount' in row
}
function isPayment(row: FinanceRow): row is ErpPayment {
  return 'effective_status' in row
}
function downloadReceipt() {
  if (!finance.receipt.value) return
  const blob = new Blob([JSON.stringify(finance.receipt.value, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `recibo-${finance.receipt.value.id}.json`
  anchor.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <ErpCatalogPageShell title="Financeiro" description="Obrigações, pagamentos e contas da empresa, com histórico e saldos conciliáveis.">
    <template #actions>
      <UButton
        v-if="finance.allowed(createActions[finance.tab.value])"
        icon="i-lucide-plus"
        :aria-label="newActionLabel"
        class="shrink-0"
        @click="openCreateEditor(finance.tab.value, $event)"
      >
        <span class="hidden sm:inline">{{ newActionLabel }}</span>
      </UButton>
    </template>
    <template #toolbar>
      <nav class="flex flex-wrap gap-2" aria-label="Áreas financeiras" role="tablist">
        <UButton
          v-for="item in financeTabs"
          :key="item.id"
          :label="item.label"
          :color="finance.tab.value === item.id ? 'primary' : 'neutral'"
          :variant="finance.tab.value === item.id ? 'soft' : 'ghost'"
          role="tab"
          :aria-selected="finance.tab.value === item.id"
          @click="selectArea(item.id)"
        />
      </nav>
    </template>
    <div class="space-y-5" data-erp-finance-workspace>
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-highlighted">
            {{ pageTitle }}
          </h2><p class="text-sm text-muted">
            <template v-if="finance.meta.value">
              {{ finance.meta.value.total }} registros no resultado filtrado
            </template>
            <template v-else-if="finance.loading.value">
              Carregando registros…
            </template>
            <template v-else-if="finance.pageError.value">
              Resultado indisponível
            </template>
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            label="Atualizar"
            :loading="finance.loading.value"
            @click="finance.load"
          />
        </div>
      </div>

      <section class="flex flex-wrap items-end gap-3" aria-label="Filtros dos lançamentos">
        <UFormField label="Situação" name="status">
          <USelect v-model="statusFilter" :items="filtersForTab" @update:model-value="changeFilter" />
        </UFormField>
        <UFormField v-if="finance.tab.value !== 'accounts'" label="Direção" name="direction">
          <USelect v-model="directionFilter" :items="directionOptions" @update:model-value="changeFilter" />
        </UFormField>
        <UFormField label="Estabelecimento" name="erp_establishment_id">
          <USelect v-model="establishmentFilter" :items="[{ label: 'Todos', value: 'all' }, ...finance.establishments.value.map(row => ({ label: row.name, value: String(row.id) }))]" @update:model-value="changeFilter" />
        </UFormField>
        <UFormField v-if="finance.tab.value !== 'accounts'" label="Conta financeira" name="erp_financial_account_id">
          <USelect v-model="accountFilter" :items="[{ label: 'Todas', value: 'all' }, ...finance.accountOptions.value.map(account => ({ label: account.name, value: String(account.id) }))]" @update:model-value="changeFilter" />
        </UFormField>
        <UFormField label="Moeda" name="currency">
          <UInput
            v-model="finance.filters.currency"
            maxlength="3"
            class="w-24"
            @change="changeFilter"
          />
        </UFormField>
        <UFormField label="Lançado de" name="date_from">
          <UInput v-model="finance.filters.date_from" type="date" @change="changeFilter" />
        </UFormField>
        <UFormField label="Até" name="date_to">
          <UInput v-model="finance.filters.date_to" type="date" @change="changeFilter" />
        </UFormField>
      </section>

      <div v-if="finance.referenceMeta.establishments && finance.referenceMeta.establishments.current_page < finance.referenceMeta.establishments.last_page" class="flex flex-wrap gap-2 text-xs">
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Carregar mais estabelecimentos"
          :loading="finance.referenceLoading.establishments"
          @click="finance.loadMoreReferences('establishments')"
        />
      </div>
      <div v-if="finance.referenceMeta.accounts && finance.referenceMeta.accounts.current_page < finance.referenceMeta.accounts.last_page" class="flex flex-wrap gap-2 text-xs">
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Carregar mais contas financeiras"
          :loading="finance.referenceLoading.accounts"
          @click="finance.loadMoreReferences('accounts')"
        />
      </div>
      <div v-if="finance.referenceMeta.cashSessions && finance.referenceMeta.cashSessions.current_page < finance.referenceMeta.cashSessions.last_page" class="flex flex-wrap gap-2 text-xs">
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          label="Carregar mais caixas abertos"
          :loading="finance.referenceLoading.cashSessions"
          @click="finance.loadMoreReferences('cashSessions')"
        />
      </div>

      <UAlert
        v-if="finance.pageError.value"
        color="error"
        variant="subtle"
        :title="finance.pageError.value"
        :actions="[{ label: 'Tentar novamente', onClick: finance.load }]"
      />
      <UAlert
        v-if="finance.referenceError.value"
        color="warning"
        variant="subtle"
        :title="finance.referenceError.value"
        :actions="[{ label: 'Atualizar referências', onClick: finance.loadReferences }]"
      />
      <UAlert
        v-if="!finance.canRead.value"
        color="warning"
        variant="subtle"
        title="Seu acesso não permite consultar o financeiro desta empresa."
      />
      <UAlert
        v-if="finance.mutationError.value"
        :color="finance.needsReconciliation.value ? 'warning' : 'neutral'"
        variant="subtle"
        :title="finance.mutationError.value"
      >
        <template v-if="finance.needsReconciliation.value" #actions>
          <UButton
            size="sm"
            color="neutral"
            variant="outline"
            label="Consultar operação"
            :loading="finance.mutating.value"
            @click="finance.reconcile"
          />
          <UButton
            v-if="finance.canRetryExact.value"
            size="sm"
            label="Repetir mesmo conteúdo"
            :loading="finance.mutating.value"
            @click="finance.retryExact"
          />
        </template>
      </UAlert>

      <div v-if="finance.tab.value !== 'accounts' && finance.meta.value?.totals?.length" class="flex flex-wrap gap-x-6 gap-y-2 border-y border-muted py-3 text-sm tabular-nums">
        <template v-for="total in finance.meta.value.totals" :key="total.currency">
          <span><strong class="font-medium text-highlighted">{{ total.currency }}</strong> · <span v-if="finance.tab.value === 'obligations'">A receber {{ money(total.receivable_open, total.currency) }} · A pagar {{ money(total.payable_open, total.currency) }} · Exposição líquida {{ money(total.net_exposure, total.currency) }} · Vencido {{ money(total.overdue_open, total.currency) }}</span><span v-else>Valor {{ money(total.amount, total.currency) }} · Disponível {{ money(total.available_amount, total.currency) }}</span></span>
        </template>
        <p class="w-full text-xs text-muted">
          Totais do resultado filtrado, agrupados por moeda; páginas diferentes não são somadas no navegador.
        </p>
      </div>

      <div class="overflow-x-auto rounded-lg border border-muted">
        <UTable
          :data="finance.rows.value as FinanceRow[]"
          :columns="columns"
          :loading="finance.loading.value"
          :get-row-id="row => String(row.id)"
          class="min-w-[48rem]"
        >
          <template #id-cell="{ row }">
            <button class="font-medium text-primary underline-offset-4 hover:underline" @click="select(row.original)">
              #{{ row.original.id }}
            </button><button v-if="isObligation(row.original) && row.original.return_of_id" class="block text-left text-xs text-primary underline-offset-4 hover:underline" @click="finance.inspectObligation(row.original.return_of_id)">
              Crédito de devolução da obrigação #{{ row.original.return_of_id }}
            </button><span v-else-if="isObligation(row.original) && row.original.return_credit_ids.length" class="block text-xs text-muted">Créditos vinculados: <button
              v-for="id in row.original.return_credit_ids"
              :key="id"
              class="text-primary underline-offset-4 hover:underline"
              @click="finance.inspectObligation(id)"
            >#{{ id }} </button></span>
          </template>
          <template #direction-cell="{ row }">
            <span>{{ rowDirection(row.original) }}</span>
          </template>
          <template #account_type-cell="{ row }">
            <span>{{ labelFor(row.original.account_type, accountTypeLabels) }}</span>
          </template>
          <template #status-cell="{ row }">
            <span>{{ labelFor(row.original.status, financeStatusLabels) }}</span>
          </template>
          <template #effective_status-cell="{ row }">
            <span>{{ labelFor(row.original.effective_status, financeStatusLabels) }}</span>
          </template>
          <template #amount-cell="{ row }">
            {{ money(rowAmount(row.original), row.original.currency) }}
          </template>
          <template #open_amount-cell="{ row }">
            {{ money(rowOpenAmount(row.original), row.original.currency) }}
          </template>
          <template #allocated_amount-cell="{ row }">
            {{ money(rowAllocatedAmount(row.original), row.original.currency) }}
          </template>
          <template #available_amount-cell="{ row }">
            {{ money(rowAvailableAmount(row.original), row.original.currency) }}
          </template>
          <template #actions-cell="{ row }">
            <div class="flex flex-wrap gap-1">
              <UButton
                v-if="finance.allowed(finance.tab.value === 'accounts' ? 'erp.financial-accounts.show' : finance.tab.value === 'obligations' ? 'erp.obligations.show' : 'erp.payments.show')"
                size="xs"
                color="neutral"
                variant="ghost"
                label="Detalhes"
                @click="select(row.original)"
              />
              <UButton
                v-if="finance.tab.value === 'payments' && isPayment(row.original) && finance.allowed('erp.payments.receipt')"
                size="xs"
                color="neutral"
                variant="ghost"
                label="Recibo privado"
                @click="finance.loadReceipt(row.original)"
              />
              <UButton
                v-if="finance.tab.value === 'payments' && isPayment(row.original) && row.original.effective_status === 'posted' && !row.original.needs_reconciliation && finance.allowed('erp.payments.allocate')"
                size="xs"
                color="neutral"
                variant="outline"
                label="Alocar"
                @click="startAllocation(row.original)"
              />
              <UButton
                v-if="finance.tab.value === 'payments' && isPayment(row.original) && row.original.correction.workflow === 'payment_reversal' && finance.allowed('erp.payments.reverse')"
                size="xs"
                color="error"
                variant="ghost"
                label="Estornar pagamento"
                @click="startPaymentReversal(row.original)"
              />
            </div>
          </template>
          <template #empty>
            <div v-if="finance.meta.value" class="p-8 text-center text-sm text-muted">
              Nenhum registro corresponde aos filtros. Ajuste a busca ou crie um lançamento.
            </div>
          </template>
        </UTable>
      </div>
      <div v-if="finance.meta.value && finance.meta.value.last_page > 1" class="flex justify-end">
        <UPagination
          :page="finance.meta.value.current_page"
          :total="finance.meta.value.total"
          :items-per-page="finance.meta.value.per_page"
          show-edges
          @update:page="changePage"
        />
      </div>

      <section v-if="selectedPayment" class="space-y-3 border-t border-muted pt-5" aria-label="Detalhes do pagamento">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-highlighted">
              Pagamento #{{ selectedPayment.id }}
            </h2><p class="text-sm text-muted">
              {{ selectedPayment.direction ? (selectedPayment.direction === 'in' ? 'Entrada' : 'Saída') : 'Direção desconhecida · exige conciliação' }} · {{ labelFor(selectedPayment.effective_status, financeStatusLabels) }} · {{ money(selectedPayment.amount, selectedPayment.currency) }}
            </p>
          </div><UButton
            color="neutral"
            variant="ghost"
            label="Fechar detalhes"
            @click="finance.closeSelected()"
          />
        </div>
        <p class="text-sm text-muted">
          Alocado {{ money(selectedPayment.allocated_amount, selectedPayment.currency) }} · Disponível {{ money(selectedPayment.available_amount, selectedPayment.currency) }} · Caixa {{ selectedPayment.erp_cash_session_id ?? 'sem vínculo' }}
        </p>
        <UAlert
          v-if="selectedPayment.needs_reconciliation"
          color="warning"
          variant="subtle"
          title="Este pagamento legado não tem direção conciliada e não pode ser alocado ou estornado."
        />
        <div v-if="finance.allocations.value.length" class="space-y-2">
          <h3 class="font-medium">
            Alocações vinculadas
          </h3><div v-for="allocation in finance.allocations.value" :key="allocation.id" class="flex flex-wrap items-center justify-between gap-2 border-b border-muted py-2 text-sm">
            <span>Alocação #{{ allocation.id }} → obrigação #{{ allocation.erp_obligation_id }} · {{ money(allocation.amount, selectedPayment.currency) }} <span v-if="allocation.reversal_of_id">· reversão vinculada a #{{ allocation.reversal_of_id }}</span></span><UButton
              v-if="!allocation.reversal_of_id && allocation.remaining_reversible !== '0.0000' && finance.allowed('erp.payment-allocations.reverse')"
              size="xs"
              color="error"
              variant="ghost"
              label="Desfazer alocação"
              @click="startAllocationReversal(allocation)"
            />
          </div>
        </div>
        <div v-if="finance.allocationMeta.value && finance.allocationMeta.value.last_page > 1" class="flex justify-end">
          <UPagination
            :page="finance.allocationMeta.value.current_page"
            :total="finance.allocationMeta.value.total"
            :items-per-page="finance.allocationMeta.value.per_page"
            show-edges
            @update:page="loadAllocationPage"
          />
        </div>
      </section>

      <section v-if="selectedObligation" class="space-y-3 border-t border-muted pt-5" aria-label="Detalhes da obrigação">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-highlighted">
              Obrigação #{{ selectedObligation.id }}
            </h2><p class="text-sm text-muted">
              {{ selectedObligation.direction === 'receivable' ? 'A receber' : 'A pagar' }} · {{ labelFor(selectedObligation.status, financeStatusLabels) }} · {{ money(selectedObligation.amount, selectedObligation.currency) }}
            </p>
          </div><UButton
            color="neutral"
            variant="ghost"
            label="Fechar detalhes"
            @click="finance.closeSelected()"
          />
        </div>
        <p class="text-sm text-muted">
          Saldo original em aberto {{ money(selectedObligation.open_amount, selectedObligation.currency) }} · Vencimento {{ selectedObligation.due_on ?? 'sem data' }} · Origem {{ selectedObligation.source_type }} #{{ selectedObligation.source_id }}
        </p>
        <p v-if="selectedObligation.return_of_id" class="text-sm">
          Crédito de devolução vinculado à obrigação original <button class="text-primary underline-offset-4 hover:underline" @click="finance.inspectObligation(selectedObligation.return_of_id)">
            #{{ selectedObligation.return_of_id }}
          </button>. O pagamento e as alocações originais permanecem no histórico.
        </p>
        <p v-if="selectedObligation.return_credit_ids.length" class="text-sm">
          Créditos de devolução vinculados a esta obrigação: <button
            v-for="id in selectedObligation.return_credit_ids"
            :key="id"
            class="text-primary underline-offset-4 hover:underline"
            @click="finance.inspectObligation(id)"
          >
            #{{ id }}
          </button>. Os créditos são exibidos separadamente do valor original.
        </p>
      </section>

      <section v-if="selectedAccount" class="space-y-3 border-t border-muted pt-5" aria-label="Detalhes da conta financeira">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-highlighted">
              {{ selectedAccount.name }}
            </h2>
            <p class="text-sm text-muted">
              Conta financeira #{{ selectedAccount.id }} · {{ labelFor(selectedAccount.account_type, accountTypeLabels) }} · {{ labelFor(selectedAccount.status, financeStatusLabels) }}
            </p>
          </div>
          <UButton
            color="neutral"
            variant="ghost"
            label="Fechar detalhes"
            @click="finance.closeSelected()"
          />
        </div>
        <p class="text-sm text-muted">
          Moeda {{ selectedAccount.currency }} · Estabelecimento {{ selectedAccount.erp_establishment_id ?? 'toda a empresa' }}
        </p>
      </section>

      <details v-if="finance.operationOutcome.value !== null" class="border-t border-muted pt-3 text-sm">
        <summary class="cursor-pointer font-medium">
          Resultado original da operação
        </summary>
        <pre class="mt-2 overflow-x-auto rounded bg-elevated p-3 text-xs">{{ JSON.stringify(finance.operationOutcome.value, null, 2) }}</pre>
      </details>

      <UModal
        v-model:open="dialogOpenModel"
        :title="action === 'allocate' ? 'Alocar pagamento' : action === 'reverse-allocation' ? 'Desfazer alocação' : 'Estornar pagamento'"
        :description="action === 'reverse-payment' ? 'A ação cria uma compensação vinculada e preserva o pagamento original.' : 'A correção será registrada com uma operação identificável.'"
        :ui="{ footer: 'justify-end' }"
      >
        <template #body>
          <div class="space-y-4">
            <p v-if="action === 'reverse-payment'" class="text-sm text-muted">
              Confirme o estorno do pagamento #{{ targetPayment?.id }} de {{ targetPayment && money(targetPayment.amount, targetPayment.currency) }}. Alocações serão desfeitas em registros vinculados.
            </p>
            <UFormField
              v-if="action === 'allocate'"
              label="Obrigação compatível"
              name="erp_obligation_id"
              required
              :error="finance.fieldErrors.value.erp_obligation_id"
            >
              <USelect v-model="actionState.erp_obligation_id" :items="[{ label: 'Selecione uma obrigação', value: 'none' }, ...finance.candidates.value.map(row => ({ label: `#${row.id} · ${row.direction === 'receivable' ? 'A receber' : 'A pagar'} · ${money(row.open_amount, row.currency)}`, value: String(row.id) }))]" />
              <UPagination
                v-if="finance.candidateMeta.value && finance.candidateMeta.value.last_page > 1"
                :page="finance.candidateMeta.value.current_page"
                :total="finance.candidateMeta.value.total"
                :items-per-page="finance.candidateMeta.value.per_page"
                show-edges
                @update:page="page => targetPayment && finance.loadCandidates(targetPayment, page)"
              />
            </UFormField>
            <UFormField
              v-if="action === 'allocate' || action === 'reverse-allocation'"
              label="Valor"
              name="amount"
              required
              :error="finance.fieldErrors.value.amount"
            >
              <UInput
                v-model="actionState.amount"
                type="text"
                inputmode="decimal"
                autocomplete="off"
              />
            </UFormField>
            <UFormField
              v-if="action === 'reverse-payment' && targetPayment?.correction.requires_cash_target"
              label="Caixa aberto para a compensação"
              name="erp_cash_session_id"
              required
              :error="finance.fieldErrors.value.erp_cash_session_id"
            >
              <USelect v-model="actionState.erp_cash_session_id" :items="[{ label: 'Selecione um caixa aberto', value: 'none' }, ...finance.cashSessions.value.filter(session => session.currency === targetPayment?.currency).map(session => ({ label: `Caixa #${session.id} · ${session.currency}`, value: String(session.id) }))]" />
              <UButton
                v-if="finance.referenceMeta.cashSessions && finance.referenceMeta.cashSessions.current_page < finance.referenceMeta.cashSessions.last_page"
                size="xs"
                color="neutral"
                variant="ghost"
                label="Carregar mais caixas abertos"
                :loading="finance.referenceLoading.cashSessions"
                @click="finance.loadMoreReferences('cashSessions')"
              />
            </UFormField>
            <UFormField
              v-if="action !== 'allocate'"
              label="Motivo"
              name="reason"
              required
              :error="finance.fieldErrors.value.reason"
            >
              <UTextarea v-model="actionState.reason" :rows="3" maxlength="255" />
            </UFormField>
            <UAlert
              v-if="actionFeedback"
              color="error"
              variant="subtle"
              :title="actionFeedback"
            />
          </div>
        </template>
        <template #footer="{ close }">
          <UButton
            color="neutral"
            variant="outline"
            label="Cancelar"
            :disabled="finance.mutating.value"
            @click="close(); clearActionTarget()"
          />
          <UButton
            :color="action === 'allocate' ? 'primary' : 'error'"
            :label="action === 'allocate' ? 'Confirmar alocação' : 'Confirmar correção'"
            :loading="finance.mutating.value"
            @click="confirmAction"
          />
        </template>
      </UModal>

      <UModal
        v-model:open="receiptOpen"
        title="Recibo privado"
        description="Documento JSON protegido pela sessão da empresa."
        :ui="{ footer: 'justify-end' }"
      >
        <template #body>
          <dl v-if="finance.receipt.value" class="grid gap-2 text-sm sm:grid-cols-2">
            <dt class="text-muted">
              Referência
            </dt><dd>{{ finance.receipt.value.reference }}</dd><dt class="text-muted">
              Empresa
            </dt><dd>{{ finance.receipt.value.company.name }}</dd><dt class="text-muted">
              Valor
            </dt><dd>{{ money(finance.receipt.value.amount, finance.receipt.value.currency) }}</dd><dt class="text-muted">
              Método
            </dt><dd>{{ labelFor(finance.receipt.value.method, paymentMethodLabels) }}</dd><dt class="text-muted">
              Postado em
            </dt><dd>{{ finance.receipt.value.posted_at ?? '—' }}</dd>
          </dl>
        </template>
        <template #footer>
          <UButton
            color="neutral"
            variant="outline"
            label="Fechar"
            @click="receiptOpen = false"
          /><UButton icon="i-lucide-download" label="Baixar JSON privado" @click="downloadReceipt" />
        </template>
      </UModal>

      <UModal
        :open="createEditor === 'accounts'"
        :title="createTitles.accounts"
        description="A conta pertence à empresa ERP selecionada."
        :unmount-on-hide="false"
        :ui="{ footer: 'justify-end' }"
        @update:open="open => setCreateEditorOpen('accounts', open)"
        @after:enter="focusCreateEditor"
        @after:leave="restoreCreateEditorFocus"
      >
        <template #body>
          <ErpFinancialAccountForm
            v-if="finance.allowed('erp.financial-accounts.store')"
            :key="`account-${finance.authorityGeneration.value}`"
            :busy="finance.mutating.value"
            :errors="finance.fieldErrors.value"
            :establishments="finance.establishments.value"
            @submit="payload => submitCreate('accounts', payload)"
          />
        </template>
        <template #footer="{ close }">
          <UButton
            color="neutral"
            variant="outline"
            label="Cancelar"
            :disabled="finance.mutating.value"
            @click="close"
          />
        </template>
      </UModal>

      <UModal
        :open="createEditor === 'obligations'"
        :title="createTitles.obligations"
        description="Lançamento manual sem cobrança automática ou movimento bancário."
        :unmount-on-hide="false"
        :ui="{ footer: 'justify-end' }"
        @update:open="open => setCreateEditorOpen('obligations', open)"
        @after:enter="focusCreateEditor"
        @after:leave="restoreCreateEditorFocus"
      >
        <template #body>
          <ErpObligationForm
            v-if="finance.allowed('erp.obligations.store')"
            :key="`obligation-${finance.authorityGeneration.value}`"
            :busy="finance.mutating.value"
            :errors="finance.fieldErrors.value"
            :establishments="finance.establishments.value"
            :accounts="finance.accountOptions.value"
            @submit="payload => submitCreate('obligations', payload)"
          />
        </template>
        <template #footer="{ close }">
          <UButton
            color="neutral"
            variant="outline"
            label="Cancelar"
            :disabled="finance.mutating.value"
            @click="close"
          />
        </template>
      </UModal>

      <UModal
        :open="createEditor === 'payments'"
        :title="createTitles.payments"
        description="Este registro não envia cobrança nem inicia transação bancária."
        :unmount-on-hide="false"
        :ui="{ footer: 'justify-end' }"
        @update:open="open => setCreateEditorOpen('payments', open)"
        @after:enter="focusCreateEditor"
        @after:leave="restoreCreateEditorFocus"
      >
        <template #body>
          <ErpPaymentForm
            v-if="finance.allowed('erp.payments.store')"
            :key="`payment-${finance.authorityGeneration.value}`"
            :busy="finance.mutating.value"
            :errors="finance.fieldErrors.value"
            :establishments="finance.establishments.value"
            :accounts="finance.accountOptions.value"
            :sessions="finance.cashSessions.value"
            @submit="payload => submitCreate('payments', payload)"
          />
        </template>
        <template #footer="{ close }">
          <UButton
            color="neutral"
            variant="outline"
            label="Cancelar"
            :disabled="finance.mutating.value"
            @click="close"
          />
        </template>
      </UModal>
    </div>
  </ErpCatalogPageShell>
</template>
