<script setup lang="ts">
import { erpOperationalTableUi } from '~/components/erp/erpPresentation'
import type { NavigationMenuItem, TableColumn } from '@nuxt/ui'
import type { ErpCashMovement, ErpCashRegister, ErpCashSession } from '#shared/types/erpBusiness'
import { formatErpMoney, type ErpFinanceAction } from '#shared/utils/erpFinance'
import { useErpCashWorkspace } from '~/composables/useErpCashWorkspace'

type CashView = 'registers' | 'sessions'
const viewFromHash = (hash: string): CashView => hash === '#sessions' ? 'sessions' : 'registers'
const route = useRoute()
const router = useRouter()
const cash = useErpCashWorkspace(() => viewFromHash(route.hash))
const views: { id: CashView, label: string }[] = [{ id: 'registers', label: 'Caixas' }, { id: 'sessions', label: 'Sessões' }]
const viewLinks = computed<NavigationMenuItem[][]>(() => [views.map(view => ({
  label: view.label,
  to: { path: route.path, query: route.query, hash: `#${view.id}` },
  active: cash.selectedPage.value === view.id
}))])
watch(() => route.hash, (hash) => {
  const view = viewFromHash(hash)
  if (hash !== `#${view}`) {
    void router.replace({ path: route.path, query: route.query, hash: `#${view}` })
    return
  }
  if (cash.selectedPage.value !== view) void cash.setPage(view)
}, { flush: 'sync' })
onMounted(() => {
  const hash = `#${cash.selectedPage.value}`
  if (route.hash !== hash) void router.replace({ path: route.path, query: route.query, hash })
})

// Presentation guard only; the composable retains session, query and authority ownership.
const movementPagePending = ref(false)
async function changeMovementPage(page: number) {
  const session = cash.selectedSession.value
  if (!session || movementPagePending.value) return
  movementPagePending.value = true
  try {
    await cash.loadMovementPage(session.id, page)
  } finally {
    movementPagePending.value = false
  }
}

const registerOpen = ref(false)
const openSessionOpen = ref(false)
const movementOpen = ref(false)
const paymentOpen = ref(false)
const closeOpen = ref(false)
const modalAttempts = reactive({ register: 0, open: 0, movement: 0, payment: 0, close: 0 })
const registerErrors = computed(() => cash.fieldErrors.value)
const sessionErrors = computed(() => cash.fieldErrors.value)
const movementErrors = computed(() => cash.fieldErrors.value)
const paymentErrors = computed(() => cash.fieldErrors.value)
const closeErrors = computed(() => cash.fieldErrors.value)
const allowed = (action: ErpFinanceAction) => cash.allowed(action)
const currentTitle = computed(() => cash.selectedPage.value === 'registers' ? 'Caixas' : 'Sessões de caixa')
const mutationColor = computed(() => cash.mutationError.value === 'Operação confirmada.' || cash.mutationError.value.startsWith('Operação confirmada pelo registro do servidor.') ? 'success' : 'error')
const registerColumns: TableColumn<ErpCashRegister>[] = [
  { accessorKey: 'name', header: 'Caixa' },
  { accessorKey: 'erp_establishment_id', header: 'Estabelecimento' },
  { accessorKey: 'status', header: 'Situação' },
  { id: 'actions' }
]
const sessionColumns: TableColumn<ErpCashSession>[] = [
  { accessorKey: 'id', header: 'Sessão' },
  { id: 'operator', accessorFn: row => row.operator?.name ?? '—', header: 'Operador' },
  { accessorKey: 'currency', header: 'Moeda' },
  { accessorKey: 'expected_balance', header: 'Saldo esperado' },
  { accessorKey: 'status', header: 'Situação' },
  { id: 'actions' }
]
const movementColumns: TableColumn<ErpCashMovement>[] = [
  { accessorKey: 'posted_at', header: 'Data' },
  { accessorKey: 'kind', header: 'Movimento' },
  { accessorKey: 'direction', header: 'Direção' },
  { accessorKey: 'amount', header: 'Valor' },
  { accessorKey: 'reason', header: 'Motivo' },
  { id: 'actor', accessorFn: row => row.actor?.name ?? '—', header: 'Operador' }
]
function money(value: string | null | undefined) {
  const currency = cash.selectedSession.value?.currency
  return value !== null && value !== undefined && currency ? formatErpMoney(value, currency) : 'Indisponível'
}
function sessionBalance(session: ErpCashSession) {
  if (session.currency === null || session.needs_reconciliation || session.expected_balance === null) return 'Conferência necessária'
  return formatErpMoney(session.expected_balance, session.currency)
}
function openEditor(action: ErpFinanceAction) {
  return allowed(action)
}
function operationSummary(operation: NonNullable<typeof cash.selectedOperation.value>) {
  const sessionId = operation.path.match(/^\/erp\/cash-sessions\/(\d+)/)?.[1]
  if (operation.operation === 'erp.cash-sessions.close')
    return `Sessão #${sessionId ?? 'indisponível'} · contagem declarada ${String(operation.payload.declared_closing_amount ?? 'indisponível')}`
  if (operation.operation === 'erp.cash-sessions.movements.store')
    return `Sessão #${sessionId ?? 'indisponível'} · ${String(operation.payload.direction ?? '')} ${String(operation.payload.amount ?? 'indisponível')}`
  if (operation.operation === 'erp.payments.store')
    return `Sessão #${String(operation.payload.erp_cash_session_id ?? 'indisponível')} · pagamento ${String(operation.payload.amount ?? 'indisponível')} ${String(operation.payload.currency ?? '')}`
  if (operation.operation === 'erp.cash-sessions.store')
    return `Caixa #${String(operation.payload.erp_cash_register_id ?? 'indisponível')} · abertura ${String(operation.payload.opening_amount ?? 'indisponível')} ${String(operation.payload.currency ?? '')}`
  return `Caixa · ${String(operation.payload.name ?? 'operação original')}`
}
type ModalKind = keyof typeof modalAttempts
type ModalToken = { kind: ModalKind, attempt: number, authority: number, sessionId: number | null }
function beginModal(kind: ModalKind) {
  modalAttempts[kind]++
}
function modalToken(kind: ModalKind, sessionId: number | null = cash.selectedSession.value?.id ?? null): ModalToken {
  return { kind, attempt: modalAttempts[kind], authority: cash.authorityGeneration.value, sessionId }
}
function canCompleteModal(token: ModalToken) {
  return token.attempt === modalAttempts[token.kind]
    && token.authority === cash.authorityGeneration.value
    && (token.sessionId === null || token.sessionId === cash.selectedSession.value?.id)
}
function sessionIdFromPayload(value: unknown) {
  const id = Number(value)
  return Number.isSafeInteger(id) && id > 0 ? id : null
}
function startRegister() {
  if (openEditor('erp.cash-registers.store')) {
    beginModal('register')
    registerOpen.value = true
  }
}
function startOpening() {
  if (!openEditor('erp.cash-sessions.store')) return
  beginModal('open')
  openSessionOpen.value = true
  void cash.loadRegisterOptions()
}
function startOpeningFor(registerId: number) {
  cash.filters.erp_cash_register_id = String(registerId)
  startOpening()
}
function setSessionFilter(key: 'status' | 'erp_cash_register_id' | 'erp_establishment_id' | 'currency' | 'date_from' | 'date_to', value: unknown) {
  cash.filters[key] = String(value ?? '')
  void cash.loadList('sessions')
}
function selectRegister(register: ErpCashRegister) {
  cash.filters.erp_cash_register_id = String(register.id)
  void cash.setPage('sessions')
}
function selectSession(session: ErpCashSession) {
  void cash.inspectSession(session.id)
}
function startMovement() {
  if (!cash.selectedSession.value || !openEditor('erp.cash-sessions.movements.store')) return
  beginModal('movement')
  movementOpen.value = true
}
function startPayment() {
  if (!cash.selectedSession.value || !openEditor('erp.payments.store')) return
  beginModal('payment')
  paymentOpen.value = true
}
function startClose() {
  if (!cash.selectedSession.value || !openEditor('erp.cash-sessions.close')) return
  beginModal('close')
  closeOpen.value = true
}
async function submitRegister(payload: Record<string, unknown>) {
  const token = modalToken('register')
  const result = await cash.registerCashDrawer(payload as { name: string, erp_establishment_id: number })
  if (result !== null && canCompleteModal(token)) registerOpen.value = false
}
async function submitOpenSession(payload: Record<string, unknown>) {
  const token = modalToken('open', null)
  const result = await cash.openCashSession(payload as { erp_cash_register_id: number, opening_amount: string, currency: string })
  if (result !== null && canCompleteModal(token)) openSessionOpen.value = false
}
async function submitMovement(payload: Record<string, unknown>) {
  const token = modalToken('movement')
  const result = await cash.postMovement(payload as { direction: 'in' | 'out', kind: 'deposit' | 'withdrawal', amount: string, reason: string })
  if (result !== null && canCompleteModal(token)) movementOpen.value = false
}
async function submitPayment(payload: Record<string, unknown>) {
  const token = modalToken('payment', sessionIdFromPayload(payload.erp_cash_session_id))
  const result = await cash.payCashPayment(payload)
  if (result !== null && canCompleteModal(token)) paymentOpen.value = false
}
async function submitClose(payload: Record<string, unknown>) {
  const token = modalToken('close')
  const result = await cash.closeSession(payload as { declared_closing_amount: string })
  if (result !== null && canCompleteModal(token)) closeOpen.value = false
}
watch(cash.authorityGeneration, () => {
  for (const kind of ['register', 'open', 'movement', 'payment', 'close'] as const) beginModal(kind)
  registerOpen.value = false
  openSessionOpen.value = false
  movementOpen.value = false
  paymentOpen.value = false
  closeOpen.value = false
}, { flush: 'sync' })
watch(() => cash.selectedSession.value?.id, (id, previousId) => {
  if (previousId !== undefined && id !== previousId) {
    beginModal('movement')
    beginModal('payment')
    beginModal('close')
    movementOpen.value = false
    paymentOpen.value = false
    closeOpen.value = false
  }
}, { flush: 'sync' })
</script>

<template>
  <ErpCatalogPageShell title="Caixa" description="Aberturas, movimentos e fechamentos com saldos reconciliados pelo servidor.">
    <template #actions>
      <UButton
        v-if="cash.selectedPage.value === 'registers' && allowed('erp.cash-registers.store')"
        icon="i-lucide-plus"
        aria-label="Novo caixa"
        @click="startRegister"
      >
        <span class="hidden sm:inline">Novo caixa</span>
      </UButton>
      <UButton
        v-if="cash.selectedPage.value === 'sessions' && allowed('erp.cash-sessions.store')"
        icon="i-lucide-plus"
        aria-label="Abrir sessão"
        @click="startOpening"
      >
        <span class="hidden sm:inline">Abrir sessão</span>
      </UButton>
    </template>
    <template #toolbar>
      <UNavigationMenu
        :items="viewLinks"
        highlight
        aria-label="Áreas do caixa"
        class="-mx-1 flex-1 overflow-x-auto"
      />
    </template>

    <div class="space-y-5" data-erp-cash-workspace>
      <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-highlighted">
            {{ currentTitle }}
          </h2>
          <p class="text-sm text-muted">
            <template v-if="cash.listMeta[cash.selectedPage.value]">
              {{ cash.listMeta[cash.selectedPage.value]?.total }} registros
            </template>
            <template v-else-if="cash.loading[cash.selectedPage.value]">
              Carregando registros…
            </template>
            <template v-else-if="cash.pageErrors[cash.selectedPage.value]">
              Resultado indisponível
            </template>
          </p>
        </div>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Atualizar"
          :loading="cash.loading[cash.selectedPage.value]"
          @click="cash.loadList(cash.selectedPage.value)"
        />
      </header>

      <UAlert
        v-if="cash.mutationError.value"
        :color="mutationColor"
        variant="subtle"
        :title="cash.mutationError.value"
      >
        <template v-if="cash.selectedOperation.value" #description>
          <div class="flex flex-wrap items-center gap-2">
            <p v-if="cash.recoveryContentAllowed.value" class="basis-full text-sm text-muted">
              Conteúdo original: {{ operationSummary(cash.selectedOperation.value) }}
            </p>
            <UButton
              v-if="cash.recoveryContentAllowed.value"
              color="neutral"
              variant="outline"
              size="sm"
              label="Consultar operação"
              :loading="cash.mutating.value"
              @click="cash.reconcile"
            />
            <UButton
              v-if="cash.canRetryExact.value && cash.recoveryContentAllowed.value"
              color="neutral"
              variant="outline"
              size="sm"
              label="Repetir conteúdo original"
              :disabled="cash.mutating.value"
              @click="cash.retryExact"
            />
          </div>
        </template>
      </UAlert>

      <template v-if="cash.selectedPage.value === 'registers'">
        <UAlert
          v-if="cash.pageErrors.registers"
          color="error"
          variant="subtle"
          :title="cash.pageErrors.registers"
        />
        <UTable
          :ui="erpOperationalTableUi"
          :data="cash.registers.value"
          :columns="registerColumns"
          :loading="cash.loading.registers"
          :empty="cash.listMeta.registers ? 'Nenhum caixa cadastrado.' : 'Resultado indisponível.'"
        >
          <template #erp_establishment_id-cell="{ row }">
            <span>Unidade #{{ row.original.erp_establishment_id }}</span>
          </template>
          <template #actions-cell="{ row }">
            <UButton
              v-if="row.original.status === 'active' && allowed('erp.cash-sessions.store')"
              size="sm"
              color="neutral"
              variant="outline"
              label="Abrir sessão"
              @click="startOpeningFor(row.original.id)"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="ghost"
              label="Ver sessões"
              @click="selectRegister(row.original)"
            />
          </template>
        </UTable>
        <ErpListFooter
          v-if="cash.listMeta.registers"
          :page="cash.listMeta.registers.current_page"
          :meta="cash.listMeta.registers"
          :pending="cash.loading.registers"
          @update:page="cash.loadList('registers', $event)"
        />
      </template>

      <template v-else>
        <section class="flex flex-wrap items-end gap-3" aria-label="Filtros das sessões">
          <UFormField label="Situação" name="status">
            <USelect :model-value="cash.filters.status || 'all'" :items="[{ label: 'Todas', value: 'all' }, { label: 'Abertas', value: 'open' }, { label: 'Fechadas', value: 'closed' }]" @update:model-value="setSessionFilter('status', $event === 'all' ? '' : $event)" />
          </UFormField>
          <UFormField label="Caixa" name="erp_cash_register_id">
            <USelect :model-value="cash.filters.erp_cash_register_id || 'all'" :items="[{ label: 'Todos', value: 'all' }, ...cash.registerOptions.value.map(register => ({ label: register.name, value: String(register.id) }))]" @update:model-value="setSessionFilter('erp_cash_register_id', $event === 'all' ? '' : $event)" />
          </UFormField>
          <UButton
            v-if="cash.registerOptionsMeta.value && cash.registerOptionsMeta.value.current_page < cash.registerOptionsMeta.value.last_page"
            color="neutral"
            variant="outline"
            size="sm"
            label="Mais caixas para filtrar"
            :loading="cash.registerOptionsLoading.value"
            @click="cash.loadMoreRegisterOptions"
          />
          <UFormField label="Estabelecimento" name="erp_establishment_id">
            <USelect :model-value="cash.filters.erp_establishment_id || 'all'" :items="[{ label: 'Todos', value: 'all' }, ...cash.establishments.value.map(item => ({ label: item.name, value: String(item.id) }))]" @update:model-value="setSessionFilter('erp_establishment_id', $event === 'all' ? '' : $event)" />
          </UFormField>
          <UFormField label="Moeda" name="currency">
            <UInput v-model="cash.filters.currency" maxlength="3" @change="cash.loadList('sessions')" />
          </UFormField>
          <UFormField label="De" name="date_from">
            <UInput v-model="cash.filters.date_from" type="date" @change="cash.loadList('sessions')" />
          </UFormField>
          <UFormField label="Até" name="date_to">
            <UInput v-model="cash.filters.date_to" type="date" @change="cash.loadList('sessions')" />
          </UFormField>
        </section>
        <UAlert
          v-if="cash.pageErrors.sessions"
          color="error"
          variant="subtle"
          :title="cash.pageErrors.sessions"
        />
        <UTable
          :ui="erpOperationalTableUi"
          :data="cash.sessions.value"
          :columns="sessionColumns"
          :loading="cash.loading.sessions"
          :empty="cash.listMeta.sessions ? 'Nenhuma sessão de caixa encontrada.' : 'Resultado indisponível.'"
        >
          <template #expected_balance-cell="{ row }">
            <span>{{ sessionBalance(row.original) }}</span>
          </template>
          <template #actions-cell="{ row }">
            <UButton
              size="sm"
              color="neutral"
              variant="outline"
              label="Abrir detalhe"
              @click="selectSession(row.original)"
            />
          </template>
        </UTable>
        <ErpListFooter
          v-if="cash.listMeta.sessions"
          :page="cash.listMeta.sessions.current_page"
          :meta="cash.listMeta.sessions"
          :pending="cash.loading.sessions"
          @update:page="cash.loadList('sessions', $event)"
        />
      </template>

      <section v-if="cash.selectedSession.value" class="space-y-4 border-t border-default pt-5" aria-labelledby="cash-session-detail-title">
        <header class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 id="cash-session-detail-title" class="text-base font-semibold text-highlighted">
              Sessão #{{ cash.selectedSession.value.id }}
            </h3>
            <p class="text-sm text-muted">
              {{ cash.selectedSession.value.operator?.name ?? 'Operador indisponível' }} · {{ cash.selectedSession.value.currency ?? 'Moeda não conciliada' }} · {{ cash.selectedSession.value.status === 'open' ? 'Aberta' : 'Fechada' }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-if="cash.selectedSession.value.can_operate && cash.selectedSession.value.status === 'open' && !cash.selectedSession.value.needs_reconciliation && allowed('erp.cash-sessions.movements.store')"
              color="neutral"
              variant="outline"
              icon="i-lucide-arrow-left-right"
              label="Registrar movimento"
              @click="startMovement"
            />
            <UButton
              v-if="cash.selectedSession.value.can_operate && cash.selectedSession.value.status === 'open' && !cash.selectedSession.value.needs_reconciliation && allowed('erp.payments.store')"
              color="neutral"
              variant="outline"
              icon="i-lucide-hand-coins"
              label="Pagamento em dinheiro"
              @click="startPayment"
            />
            <UButton
              v-if="cash.selectedSession.value.can_operate && cash.selectedSession.value.status === 'open' && !cash.selectedSession.value.needs_reconciliation && allowed('erp.cash-sessions.close')"
              icon="i-lucide-lock-keyhole"
              label="Fechar sessão"
              @click="startClose"
            />
          </div>
        </header>

        <UAlert
          v-if="cash.selectedSession.value.needs_reconciliation || cash.selectedSession.value.currency === null"
          color="warning"
          variant="subtle"
          title="Conferência necessária"
          description="Esta sessão não possui moeda ou saldo conciliado. Movimentos, pagamentos e fechamento estão indisponíveis."
        />
        <dl v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-sm text-muted">
              Saldo esperado
            </dt><dd class="font-medium text-highlighted">
              {{ money(cash.ledgerTotals.value?.expected_balance) }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Entradas
            </dt><dd class="font-medium text-highlighted">
              {{ money(cash.ledgerTotals.value?.movements_in) }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Saídas
            </dt><dd class="font-medium text-highlighted">
              {{ money(cash.ledgerTotals.value?.movements_out) }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Movimento líquido
            </dt><dd class="font-medium text-highlighted">
              {{ money(cash.ledgerTotals.value?.movements_net) }}
            </dd>
          </div>
        </dl>

        <section v-if="cash.selectedSession.value.status === 'closed'" class="grid gap-3 border-y border-default py-4 sm:grid-cols-3" aria-label="Conciliação de fechamento">
          <div>
            <dt class="text-sm text-muted">
              Saldo esperado ao fechar
            </dt><dd class="font-medium text-highlighted">
              {{ money(cash.selectedSession.value.expected_closing_amount) }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Valor declarado
            </dt><dd class="font-medium text-highlighted">
              {{ money(cash.selectedSession.value.declared_closing_amount) }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Diferença
            </dt><dd class="font-medium text-highlighted">
              {{ money(cash.selectedSession.value.closing_difference) }}
            </dd>
          </div>
        </section>
        <UAlert
          v-if="cash.movementError.value"
          color="error"
          variant="subtle"
          :title="cash.movementError.value"
        />
        <div class="overflow-x-auto">
          <UTable
            :ui="erpOperationalTableUi"
            :data="cash.movements.value"
            :columns="movementColumns"
            :empty="cash.movementMeta.value ? 'Nenhum movimento registrado nesta sessão.' : 'Histórico indisponível.'"
          />
        </div>
        <ErpListFooter
          v-if="cash.movementMeta.value"
          :page="cash.movementMeta.value.current_page"
          :meta="cash.movementMeta.value"
          :pending="movementPagePending"
          :summary="`Histórico: ${cash.movementMeta.value.total.toLocaleString('pt-BR')} movimentos`"
          @update:page="changeMovementPage"
        />
      </section>
    </div>

    <UModal
      :key="`register:${cash.authorityGeneration.value}`"
      v-model:open="registerOpen"
      title="Cadastrar caixa"
      :unmount-on-hide="false"
    >
      <template #body>
        <ErpCashSessionForm
          mode="register"
          :establishments="cash.establishments.value"
          :errors="registerErrors"
          :busy="cash.mutating.value"
          @submit="submitRegister"
        />
        <div v-if="cash.listMeta.establishments && cash.listMeta.establishments.current_page < cash.listMeta.establishments.last_page" class="mt-3">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            label="Carregar mais estabelecimentos"
            :loading="cash.loading.establishments"
            @click="cash.loadList('establishments', cash.listMeta.establishments!.current_page + 1, true)"
          />
        </div>
      </template>
    </UModal>
    <UModal
      :key="`open:${cash.authorityGeneration.value}`"
      v-model:open="openSessionOpen"
      title="Abrir sessão de caixa"
      :unmount-on-hide="false"
    >
      <template #body>
        <ErpCashSessionForm
          mode="open"
          :registers="cash.registerOptions.value"
          :errors="sessionErrors"
          :busy="cash.mutating.value"
          @submit="submitOpenSession"
        />
        <div v-if="cash.registerOptionsMeta.value && cash.registerOptionsMeta.value.current_page < cash.registerOptionsMeta.value.last_page" class="mt-3">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            label="Carregar mais caixas"
            :loading="cash.registerOptionsLoading.value"
            @click="cash.loadMoreRegisterOptions"
          />
        </div>
      </template>
    </UModal>
    <UModal
      :key="`movement:${cash.selectedSession.value?.id ?? 'none'}:${cash.authorityGeneration.value}`"
      v-model:open="movementOpen"
      title="Registrar movimento"
      :unmount-on-hide="false"
    >
      <template #body>
        <ErpCashMovementForm :errors="movementErrors" :busy="cash.mutating.value" @submit="submitMovement" />
      </template>
    </UModal>
    <UModal
      :key="`payment:${cash.selectedSession.value?.id ?? 'none'}:${cash.authorityGeneration.value}`"
      v-model:open="paymentOpen"
      title="Pagamento em dinheiro"
      :unmount-on-hide="false"
    >
      <template #body>
        <ErpCashPaymentForm
          v-if="cash.selectedSession.value"
          :session="cash.selectedSession.value"
          :accounts="[]"
          :errors="paymentErrors"
          :busy="cash.mutating.value"
          @submit="submitPayment"
        />
      </template>
    </UModal>
    <UModal
      :key="`close:${cash.selectedSession.value?.id ?? 'none'}:${cash.authorityGeneration.value}`"
      v-model:open="closeOpen"
      title="Fechar sessão de caixa"
      :unmount-on-hide="false"
    >
      <template #body>
        <ErpCashCloseForm
          v-if="cash.selectedSession.value"
          :session="cash.selectedSession.value"
          :errors="closeErrors"
          :busy="cash.mutating.value"
          @submit="submitClose"
        />
      </template>
    </UModal>
  </ErpCatalogPageShell>
</template>
