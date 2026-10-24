import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as financeUtils from '../shared/utils/erpFinance.ts'
import * as operationRecovery from '../shared/utils/erpOperationRecovery.ts'

type Props = Record<string, unknown>
type ApiCall = { path: string, options?: { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> } }
type Api = (path: string, options?: ApiCall['options']) => Promise<unknown>
type Bindings = Record<string, unknown>

function source(path: string) {
  const file = new URL(path, import.meta.url)
  assert.ok(existsSync(file), `${path} should exist`)
  return readFileSync(file, 'utf8')
}

function loadRecoveryUtils() {
  return operationRecovery
}

function compileModule(source: string, imports: Record<string, unknown>, bindings: Bindings) {
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const moduleExports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', ...Object.keys(bindings), output)
  runtime((path: string) => {
    if (path === '~/components/erp/erpPresentation') return loadAppComposable('components/erp/erpPresentation.ts')
    if (path === '~/utils/erpWorkspaceNav') return loadAppComposable('utils/erpWorkspaceNav.ts')
    const value = imports[path]
    if (!value) throw new Error(`Unexpected test import ${path}`)
    return value
  }, moduleExports, ...Object.values(bindings))
  return moduleExports
}

function setupForm(name: string, props: Props = {}) {
  const { descriptor } = parse(source(`../app/components/erp/${name}.vue`))
  const script = compileScript(descriptor, { id: name })
  const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const moduleExports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', 'reactive', 'computed', 'ref', 'watch', output)
  runtime((path: string) => path === '#shared/utils/erpFinance' ? financeUtils : Vue, moduleExports, Vue.reactive, Vue.computed, Vue.ref, Vue.watch)
  const emitted: Record<string, unknown>[] = []
  let exposed: Record<string, unknown> = {}
  const scope = Vue.effectScope()
  scope.run(() => (moduleExports.default as { setup: (props: unknown, context: unknown) => unknown }).setup(props, {
    expose(value: Record<string, unknown>) { exposed = value },
    emit(event: string, payload?: Record<string, unknown>) { if (event === 'submit' && payload) emitted.push(payload) }
  }))
  return { emitted, exposed, stop: () => scope.stop() }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

function sessionStorageFixture() {
  const saved = new Map<string, string>()
  const prior = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  const storage = {
    getItem(key: string) { return saved.get(key) ?? null },
    setItem(key: string, value: string) { saved.set(key, value) },
    removeItem(key: string) { saved.delete(key) }
  }
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: storage })
  return {
    saved,
    storage,
    restore() {
      if (prior) Object.defineProperty(globalThis, 'sessionStorage', prior)
      else Reflect.deleteProperty(globalThis, 'sessionStorage')
    }
  }
}

function createCashWorkspace(api: Api, company = Vue.ref({ id: 7, permissions: ['*'], role: 'owner', access_kind: 'direct', status: 'active' }), refreshWithStatus = async () => ({ identity: { id: 42 }, committed: true }), realLifecycle = false) {
  const user = Vue.ref({ id: 42 })
  const currentCompany = company
  const key = Vue.computed(() => JSON.stringify([user.value.id, currentCompany.value.id, currentCompany.value.access_kind, ...currentCompany.value.permissions]))
  const context = { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
  const scope = Vue.effectScope()
  let mounted: (() => void) | undefined
  let contextFactory: (() => Record<string, unknown>) | null = null
  const bindings = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted(callback: () => void) {
      if (realLifecycle) Vue.onMounted(callback)
      else mounted = callback
    }, onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus }),
    useErpAdministrationContext: () => {
      if (contextFactory) return contextFactory()
      return context
    }
  }
  const moduleExports = compileModule(source('../app/composables/useErpCashWorkspace.ts'), {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpFinance': financeUtils,
    '#shared/utils/erpOperationRecovery': loadRecoveryUtils()
  }, bindings)
  const useCash = (initialPage?: unknown) => scope.run(() => (moduleExports.useErpCashWorkspace as (initialPage?: unknown) => Record<string, unknown>)(initialPage))!
  const state = realLifecycle ? {} : useCash()
  return {
    state, useCash, user, company, context,
    setContextFactory(factory: () => Record<string, unknown>) { contextFactory = factory },
    mount: () => mounted?.(),
    stop: () => scope.stop()
  }
}

function realAdministrationContext(user: Vue.Ref<{ id: number }>, currentCompany: Vue.Ref<Record<string, unknown>>) {
  const currentAccount = Vue.ref(null)
  const sessionGeneration = Vue.ref(0)
  const source = loadAppComposable('composables/useErpAdministrationContext.ts', {
    useAuth: () => ({ user, currentCompany, currentAccount }),
    useState: () => sessionGeneration,
    computed: Vue.computed, ref: Vue.ref, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose
  })
  return source.useErpAdministrationContext as () => { key: Vue.Ref<string> }
}

function mountCashWorkspace(options: { pending?: operationRecovery.ErpPendingOperation, recoveryAllowed?: boolean, paymentResult?: () => Promise<unknown>, workspaceState?: Record<string, unknown>, initialHash?: string, currentCompany?: Vue.Ref<Record<string, unknown>>, contextKey?: Vue.Ref<string>, contextFactory?: () => { key: Vue.Ref<string> }, cashFactory?: (initialPage?: unknown) => Record<string, unknown> } = {}) {
  const route = Vue.reactive({ path: '/erp/caixa', query: {}, hash: options.initialHash ?? '#sessions' })
  const replacements: Record<string, unknown>[] = []
  const selectedPage = options.workspaceState?.selectedPage as Vue.Ref<'registers' | 'sessions'> ?? Vue.ref<'registers' | 'sessions'>('registers')
  const selectedSession = options.workspaceState?.selectedSession as Vue.Ref<Record<string, unknown> | null> ?? Vue.ref<Record<string, unknown> | null>(null)
  const authorityGeneration = Vue.ref(0)
  const state = {
    registers: Vue.ref([activeRegister]), registerOptions: Vue.ref([activeRegister]), registerOptionsMeta: Vue.ref(null), registerOptionsLoading: Vue.ref(false),
    sessions: Vue.ref([openSession]), establishments: Vue.ref([{ id: 3, name: 'Matriz', status: 'active' }]),
    listMeta: Vue.reactive({ registers: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] }, sessions: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] }, establishments: null }),
    loading: Vue.reactive({ registers: false, sessions: false, establishments: false }), pageErrors: Vue.reactive({ registers: '', sessions: '', establishments: '' }),
    selectedSession, movements: Vue.ref([]), movementMeta: Vue.ref(null), ledgerTotals: Vue.ref(null), movementError: Vue.ref(''),
    selectedPage, filters: Vue.reactive({ status: '', erp_cash_register_id: '', erp_establishment_id: '', currency: '', date_from: '', date_to: '', page: 1, per_page: 15 }),
    mutationError: Vue.ref(options.pending ? 'Existe uma operação anterior aguardando conferência.' : ''), fieldErrors: Vue.ref({}), mutating: Vue.ref(false), selectedOperation: Vue.ref(options.pending ?? null), needsReconciliation: Vue.ref(false),
    recoveryContentAllowed: Vue.ref(options.recoveryAllowed ?? true),
    canRetryExact: Vue.ref(false), authorityGeneration, allowed: () => true,
    load: async () => {}, loadList: async () => {}, inspectSession: async () => {}, setPage: async (page: 'registers' | 'sessions') => { selectedPage.value = page },
    registerCashDrawer: async () => null, openCashSession: async () => null, postMovement: async () => null, closeSession: async () => null,
    payCashPayment: options.paymentResult ?? (async () => null), reconcile: async () => {}, retryExact: async () => null, activeCashSession: () => null,
    loadMovementPage: async () => {}, loadRegisterOptions: async () => {}, loadMoreRegisterOptions: async () => {}
  }
  Object.assign(state, options.workspaceState)
  let mountedCash: Record<string, unknown> = state
  let initialPageArgument: unknown
  const modalIds = new Map<string, number>()
  const tableRows: unknown[][] = []
  const { descriptor } = parse(source('../app/components/erp/ErpCashWorkspace.vue'))
  const script = compileScript(descriptor, { id: 'ErpCashWorkspace', inlineTemplate: true })
  const compiled = compileModule(script.content, {
    'vue': Vue,
    '#shared/utils/erpFinance': financeUtils,
    '~/composables/useErpCashWorkspace': {
      useErpCashWorkspace(initialPage: unknown) {
        initialPageArgument = initialPage
        if (options.cashFactory) return mountedCash = options.cashFactory(initialPage)
        Vue.onMounted(() => {
          selectedPage.value = typeof initialPage === 'function' ? (initialPage as () => 'registers' | 'sessions')() : initialPage as 'registers' | 'sessions'
        })
        return state
      }
    }
  }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted,
    useRoute: () => route,
    useErpAdministrationContext: options.contextFactory ?? (() => ({ key: options.contextKey ?? Vue.computed(() => JSON.stringify(options.currentCompany?.value ?? null)) })),
    useRouter: () => ({
      replace(location: Record<string, unknown>) {
        replacements.push(location)
        return Promise.resolve()
      }
    })
  })
  const components: Record<string, Vue.Component> = {}
  components.ErpListFooter = compileVueComponent('components/erp/ErpListFooter.vue')

  components.ErpCatalogPageShell = { setup: (_props, context) => () => Vue.h('main', {}, [context.slots.actions?.(), context.slots.toolbar?.(), context.slots.default?.()]) }
  components.UButton = { props: ['label'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, 'data-label': props.label }, context.slots.default?.()) }
  components.UNavigationMenu = { props: ['items'], setup: props => () => Vue.h('nav', {}, (props.items as Record<string, unknown>[][]).flat().map(item => Vue.h('span', { 'data-active': item.active }, String(item.label)))) }
  components.UTable = { props: ['data', 'empty'], setup: props => () => {
    tableRows.push(props.data as unknown[])
    return Vue.h('div', {}, (props.data as unknown[]).length ? '' : String(props.empty ?? ''))
  } }
  components.UModal = { props: ['title'], setup: (props) => {
    const id = Vue.getCurrentInstance()?.uid ?? -1
    modalIds.set(String(props.title), id)
    return () => Vue.h('section')
  } }
  components.UPagination = { setup: (_props, context) => () => Vue.h('nav', { ...context.attrs, 'data-pagination': true }) }
  components.ErpCashSessionForm = { setup: () => () => Vue.h('div') }
  components.ErpCashMovementForm = { setup: () => () => Vue.h('div') }
  components.ErpCashPaymentForm = { setup: () => () => Vue.h('div') }
  components.ErpCashCloseForm = { setup: () => () => Vue.h('div') }
  components.UAlert = { props: ['title', 'description'], setup: (props, context) => () => Vue.h('aside', {}, [Vue.h('span', {}, String(props.title ?? '')), Vue.h('span', {}, String(props.description ?? '')), context.slots.default?.(), context.slots.description?.()]) }
  for (const name of ['UFormField', 'UInput', 'USelect', 'UTextarea', 'UCheckbox', 'CompanyMenu', 'UserMenu'])
    components[name] = { setup: (_props, context) => () => Vue.h('div', context.attrs, context.slots.default?.()) }
  const root = { children: [] as unknown[] }
  const renderer = Vue.createRenderer({
    patchProp(node: { props: Record<string, unknown> }, key, _previous, next) { node.props[key] = next },
    insert(node: { parent?: unknown }, parent: { children: unknown[] }, anchor?: unknown) {
      node.parent = parent
      const position = anchor ? parent.children.indexOf(anchor) : -1
      if (position < 0) parent.children.push(node)
      else parent.children.splice(position, 0, node)
    },
    remove(node: { parent?: { children: unknown[] } }) {
      if (!node.parent) return
      const position = node.parent.children.indexOf(node)
      if (position >= 0) node.parent.children.splice(position, 1)
    },
    createElement(tag) { return { tag, children: [] as unknown[], props: {} as Record<string, unknown>, parent: null as unknown } },
    createText(text) { return { text, parent: null as unknown } },
    createComment(text) { return { text, parent: null as unknown } },
    setText(node: { text: string }, text) { node.text = text },
    setElementText(node: { children: unknown[], text?: string }, text) {
      node.children = []
      node.text = text
    },
    parentNode(node: { parent?: unknown }) { return node.parent ?? null },
    nextSibling(node: { parent?: { children: unknown[] } }) {
      if (!node.parent) return null
      return node.parent.children[node.parent.children.indexOf(node) + 1] ?? null
    }
  })
  const app = renderer.createApp(compiled.default as Vue.Component)
  for (const [name, component] of Object.entries(components)) app.component(name, component)
  app.mount(root)
  return { app, route, replacements, selectedPage, selectedSession, authorityGeneration, modalIds, tableRows, initialPageArgument, root, workspace: () => mountedCash }
}

function textContent(node: { text?: string, children?: unknown[] }): string {
  return `${node.text ?? ''}${(node.children ?? []).map(child => typeof child === 'object' && child !== null ? textContent(child as { text?: string, children?: unknown[] }) : '').join('')}`
}

const activeRegister = { id: 11, name: 'Balcão principal', erp_establishment_id: 3, status: 'active' }
const openSession = {
  id: 21, erp_cash_register_id: 11, erp_establishment_id: 3, operator_user_id: 42,
  operator: { id: 42, name: 'Operador' }, currency: 'BRL', status: 'open', opening_amount: '100.0000',
  expected_closing_amount: null, declared_closing_amount: null, closing_difference: null,
  opened_at: null, closed_at: null, closed_by_user_id: null, can_operate: true,
  movements_in: '0.0000', movements_out: '0.0000', movements_net: '0.0000', expected_balance: '100.0000', needs_reconciliation: false
}
const closedSession = { ...openSession, status: 'closed', can_operate: false, expected_closing_amount: '121.0000', declared_closing_amount: '119.0000', closing_difference: '-2.0000', closed_at: '2026-10-20T12:00:00Z' }

describe('cash workspace shell', () => {
  it('opens a sessions-only root on Sessions and does not expose Registers', async (t) => {
    const grants = Vue.ref(['erp.cash-sessions.index'])
    const allowed = (action: financeUtils.ErpFinanceAction) => financeUtils.erpFinanceAllowed(grants.value, 'viewer', action)
    const app = mountCashWorkspace({ initialHash: '', workspaceState: { allowed }, currentCompany: Vue.computed(() => ({ id: 7, permissions: grants.value })) })
    t.after(() => app.app.unmount())
    await Vue.nextTick()
    assert.equal(app.selectedPage.value, 'sessions')
    assert.deepEqual(app.replacements, [], 'a root remains a root across company remounts')
    assert.match(textContent(app.root), /Sessões de caixa/)
    assert.doesNotMatch(textContent(app.root), /Caixas\s+Sessões/)
    grants.value.splice(0, 1, 'erp.cash-registers.index')
    await Vue.nextTick()
    assert.equal(app.selectedPage.value, 'registers')
    assert.equal(app.route.hash, '')
  })

  it('retains a denied Registers deep link and shows denial for a sessions-only reader', async (t) => {
    const allowed = (action: financeUtils.ErpFinanceAction) => financeUtils.erpFinanceAllowed(['erp.cash-sessions.index'], 'viewer', action)
    const app = mountCashWorkspace({ initialHash: '#registers', workspaceState: { allowed } })
    t.after(() => app.app.unmount())
    await Vue.nextTick()
    assert.equal(app.selectedPage.value, 'registers')
    assert.deepEqual(app.replacements, [])
    assert.match(textContent(app.root), /Seu acesso não permite consultar/)
    assert.equal(app.tableRows.length, 0, 'a denied area does not render its cached register table')
  })

  it('restores a sessions-only root after a refused list and a real same-mount session transition', async (t) => {
    const calls: string[] = []
    let refuseSession = true
    const company = Vue.ref({ id: 7, permissions: ['erp.cash-sessions.index'], role: 'viewer', access_kind: 'direct', status: 'active' })
    const created = createCashWorkspace(async (path) => {
      calls.push(path)
      if (path.startsWith('/erp/cash-sessions?') && refuseSession) {
        refuseSession = false
        throw { statusCode: 403 }
      }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }, company, undefined, true)
    t.after(created.stop)
    const contextFactory = realAdministrationContext(created.user, company)
    created.setContextFactory(contextFactory)
    const mounted = mountCashWorkspace({ initialHash: '', currentCompany: company, contextFactory, cashFactory: created.useCash })
    t.after(() => mounted.app.unmount())
    const cash = mounted.workspace() as { selectedPage: Vue.Ref<string>, allowed: (action: financeUtils.ErpFinanceAction) => boolean }
    for (let attempt = 0; attempt < 30 && cash.allowed('erp.cash-sessions.index'); attempt++) await Vue.nextTick()
    assert.equal(cash.allowed('erp.cash-sessions.index'), false)
    assert.equal(cash.selectedPage.value, 'sessions')
    assert.equal(calls.filter(path => path.startsWith('/erp/cash-sessions?')).length, 1)
    created.user.value.id = 43
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
    assert.equal(cash.selectedPage.value, 'sessions')
    assert.equal(cash.allowed('erp.cash-sessions.index'), true)
    assert.equal(calls.filter(path => path.startsWith('/erp/cash-sessions?')).length, 2)
    assert.equal(calls.some(path => path.startsWith('/erp/cash-registers?')), false)
  })

  it('keeps #sessions on initial mount and remounts retained forms when session or authority changes', async (t) => {
    const app = mountCashWorkspace()
    t.after(() => app.app.unmount())
    await Vue.nextTick()
    assert.equal(typeof app.initialPageArgument, 'function')
    assert.equal(app.route.hash, '#sessions')
    assert.equal(app.selectedPage.value, 'sessions')
    assert.deepEqual(app.replacements, [])

    const paymentModal = 'Pagamento em dinheiro'
    const initialPaymentInstance = app.modalIds.get(paymentModal)
    app.selectedSession.value = { ...openSession }
    await Vue.nextTick()
    const firstSessionPaymentInstance = app.modalIds.get(paymentModal)
    assert.notEqual(firstSessionPaymentInstance, initialPaymentInstance)
    app.selectedSession.value = { ...openSession, id: 22 }
    await Vue.nextTick()
    const secondSessionPaymentInstance = app.modalIds.get(paymentModal)
    assert.notEqual(secondSessionPaymentInstance, firstSessionPaymentInstance)
    app.authorityGeneration.value++
    await Vue.nextTick()
    assert.notEqual(app.modalIds.get(paymentModal), secondSessionPaymentInstance)
  })

  it('shows the frozen recovery target and declared amount only while recovery authority is current', async (t) => {
    const pending = operationRecovery.createPendingOperation(
      '42:7:erp.cash-sessions.close',
      'erp.cash-sessions.close',
      '/erp/cash-sessions/21/close',
      { declared_closing_amount: '119.0000' },
      '62345678-1234-1234-1234-123456789abc'
    )
    const visible = mountCashWorkspace({ pending, recoveryAllowed: true })
    t.after(() => visible.app.unmount())
    await Vue.nextTick()
    const visibleText = textContent(visible.root)
    assert.match(visibleText, /Sessão #21/)
    assert.match(visibleText, /119\.0000/)

    const hidden = mountCashWorkspace({ pending, recoveryAllowed: false })
    t.after(() => hidden.app.unmount())
    await Vue.nextTick()
    assert.doesNotMatch(textContent(hidden.root), /119\.0000/)
  })
})

describe('cash workflow forms', () => {
  it('requires an establishment and keeps opening amount text including zero', (t) => {
    const form = setupForm('ErpCashSessionForm', { mode: 'open', registers: [activeRegister], establishments: [{ id: 3, name: 'Matriz', status: 'active' }] })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { opening_amount: '0.0000', currency: 'BRL' })
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted.length, 0)
    Object.assign(state, { erp_cash_register_id: '11', opening_amount: '0.0000', currency: 'BRL' })
    ;(form.exposed.submit as () => void)()
    assert.deepEqual(form.emitted[0], { erp_cash_register_id: 11, opening_amount: '0.0000', currency: 'BRL' })
  })

  it('requires a selected active establishment to register a cash drawer', (t) => {
    const form = setupForm('ErpCashSessionForm', { mode: 'register', establishments: [{ id: 3, name: 'Matriz', status: 'active' }] })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    state.name = 'Balcão'
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted.length, 0)
    assert.equal(state.erp_establishment_id, 'none')
    state.erp_establishment_id = '3'
    ;(form.exposed.submit as () => void)()
    assert.deepEqual(form.emitted[0], { name: 'Balcão', erp_establishment_id: 3 })
  })

  it('pairs manual movement kind and direction while preserving decimal text', (t) => {
    const form = setupForm('ErpCashMovementForm')
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { kind: 'deposit', amount: '9007199254740993.1250', reason: 'Suprimento' })
    ;(form.exposed.submit as () => void)()
    assert.deepEqual(form.emitted[0], { direction: 'in', kind: 'deposit', amount: '9007199254740993.1250', reason: 'Suprimento' })
    state.kind = 'withdrawal'
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted[1]?.direction, 'out')
  })

  it('requires explicit count confirmation and retains close amount until success', (t) => {
    const form = setupForm('ErpCashCloseForm', { session: openSession, busy: false })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string | boolean>
    state.declared_closing_amount = '98.0000'
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted.length, 0)
    state.confirmed = true
    ;(form.exposed.submit as () => void)()
    assert.deepEqual(form.emitted[0], { declared_closing_amount: '98.0000' })
    assert.equal(state.declared_closing_amount, '98.0000')
    ;(form.exposed.clear as () => void)()
    assert.equal(state.declared_closing_amount, '')
  })
})

describe('cash permissions and operation recovery', () => {
  it('treats close as a write and does not let a viewer use broad read grants', () => {
    assert.equal(financeUtils.erpFinanceAllowed(['erp.cash-sessions.close'], 'viewer', 'erp.cash-sessions.close'), false)
    assert.equal(financeUtils.erpFinanceAllowed(['erp.cash-sessions.close'], 'manager', 'erp.cash-sessions.close'), true)
    assert.equal(financeUtils.erpFinanceAllowed(['erp.cash-sessions.index'], 'manager', 'erp.cash-sessions.close'), false)
  })

  it('validates persisted operations by company actor identity and freezes payload and key', () => {
    const identity = '42:7:erp.cash-sessions.close'
    const recoveryUtils = loadRecoveryUtils() as Record<string, (...args: unknown[]) => unknown>
    const record = recoveryUtils.createPendingOperation(identity, 'erp.cash-sessions.close', '/erp/cash-sessions/21/close', { declared_closing_amount: '0.0000' }, '12345678-1234-1234-1234-123456789abc') as { payload: Record<string, unknown>, key: string }
    assert.equal(record.payload.declared_closing_amount, '0.0000')
    assert.equal(recoveryUtils.parsePendingOperation(record, identity, { 'erp.cash-sessions.close': /^\/erp\/cash-sessions\/\d+\/close$/ })?.key, record.key)
    assert.equal(recoveryUtils.parsePendingOperation(record, '42:8:erp.cash-sessions.close', { 'erp.cash-sessions.close': /^\/erp\/cash-sessions\/\d+\/close$/ }), null)
  })
})

describe('cash workspace API workflow', () => {
  it('posts a cash payment with an explicit selected session and exact amount', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: ApiCall[] = []
    const api: Api = async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/cash-sessions/21') return { data: openSession }
      if (path === '/erp/cash-sessions/21/movements?per_page=15&page=1') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openSession }
      if (path === '/erp/operations/lookup?operation=erp.payments.store') return { data: { state: 'missing' } }
      return { data: { id: 31 } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    await (app.state.payCashPayment as (body: Record<string, unknown>) => Promise<unknown>)({ direction: 'in', amount: '9007199254740993.1250', method: 'cash', currency: 'BRL', erp_cash_session_id: 21 })
    const payment = calls.find(call => call.options?.method === 'POST')
    assert.equal(payment?.path, '/erp/payments')
    assert.deepEqual(payment?.options?.body, { direction: 'in', amount: '9007199254740993.1250', method: 'cash', currency: 'BRL', erp_cash_session_id: 21, erp_establishment_id: 3, erp_financial_account_id: null })
    assert.ok(payment?.options?.headers?.['Idempotency-Key'])
  })

  it('opens a session from an active cash register on a later picker page', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const laterPageRegister = { ...activeRegister, id: 99, name: 'Caixa da página seguinte' }
    const calls: ApiCall[] = []
    const api: Api = async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/cash-sessions' && options?.method === 'POST') return { data: { id: 31 } }
      if (path === '/erp/cash-sessions/31') return { data: { ...openSession, id: 31, erp_cash_register_id: 99 } }
      if (path.startsWith('/erp/cash-sessions/31/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openSession }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    const registerOptions = app.state.registerOptions as Vue.Ref<typeof laterPageRegister[]>
    registerOptions.value = [laterPageRegister]
    const result = await (app.state.openCashSession as (body: { erp_cash_register_id: number, opening_amount: string, currency: string }) => Promise<unknown>)({ erp_cash_register_id: 99, opening_amount: '0.0000', currency: 'BRL' })
    assert.deepEqual(result, { id: 31 })
    const request = calls.find(call => call.path === '/erp/cash-sessions' && call.options?.method === 'POST')
    assert.deepEqual(request?.options?.body, { erp_cash_register_id: 99, opening_amount: '0.0000', currency: 'BRL' })
  })

  it('keeps an already selected session while a direct opening loads its new session in the background', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const openedSession = { ...openSession, id: 31 }
    const calls: string[] = []
    const api: Api = async (path, options) => {
      calls.push(path)
      if (path === '/erp/cash-sessions/21') return { data: openSession }
      if (path === '/erp/cash-sessions/31') return { data: openedSession }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openSession }
      if (path.startsWith('/erp/cash-sessions/31/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openedSession }
      if (path === '/erp/cash-sessions' && options?.method === 'POST') return { data: { id: 31 } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 0, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    ;(app.state.registerOptions as Vue.Ref<typeof activeRegister[]>).value = [activeRegister]
    const result = await (app.state.openCashSession as (body: { erp_cash_register_id: number, opening_amount: string, currency: string }) => Promise<unknown>)({ erp_cash_register_id: 11, opening_amount: '0.0000', currency: 'BRL' })
    assert.deepEqual(result, { id: 31 })
    assert.equal(calls.includes('/erp/cash-sessions/31'), true)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<{ id: number }>)?.id, 21)
  })

  it('keeps the selected session while reconciling a completed opening for another session', async (t) => {
    const sessionB = { ...openSession, id: 22, erp_cash_register_id: 12 }
    const openedSession = { ...openSession, id: 31 }
    const calls: string[] = []
    const api: Api = async (path) => {
      calls.push(path)
      if (path === '/erp/cash-sessions/22') return { data: sessionB }
      if (path === '/erp/cash-sessions/31') return { data: openedSession }
      if (path.startsWith('/erp/cash-sessions/22/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 0, total: 0, totals: [] }, totals: sessionB }
      if (path.startsWith('/erp/cash-sessions/31/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 0, total: 0, totals: [] }, totals: openedSession }
      if (path.includes('/operations/lookup')) return { data: { state: 'completed', result: { id: 31, status: 'open' } } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 0, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(22)
    const pending = operationRecovery.createPendingOperation('42:7:erp.cash-sessions.store', 'erp.cash-sessions.store', '/erp/cash-sessions', { erp_cash_register_id: 11, opening_amount: '0.0000', currency: 'BRL' }, '92345678-1234-1234-1234-123456789abc')
    ;(app.state.selectedOperation as Vue.Ref<typeof pending | null>).value = pending
    await (app.state.reconcile as () => Promise<void>)()
    assert.equal(calls.includes('/erp/cash-sessions/31'), true)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<{ id: number }>)?.id, 22)
  })

  it('recovers a close after reload with the same key and does not require an open session', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const identity = '42:7:erp.cash-sessions.close'
    const recoveryUtils = loadRecoveryUtils() as Record<string, (...args: unknown[]) => unknown>
    const pending = recoveryUtils.createPendingOperation(identity, 'erp.cash-sessions.close', '/erp/cash-sessions/21/close', { declared_closing_amount: '119.0000' }, '22345678-1234-1234-1234-123456789abc') as { identity: string, operation: string, path: string, payload: Record<string, unknown>, key: string }
    storage.saved.set('erp-cash:v1:42:7', JSON.stringify(pending))
    const calls: ApiCall[] = []
    const api: Api = async (path, options) => {
      calls.push({ path, options })
      if (path.includes('/operations/lookup')) return { data: { state: 'completed', result: closedSession } }
      if (path === '/erp/cash-sessions/21') return { data: closedSession }
      if (path === '/erp/cash-sessions/21/movements?per_page=15&page=1') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: { movements_in: '21.0000', movements_out: '0.0000', movements_net: '21.0000', expected_balance: '121.0000', needs_reconciliation: false } }
      if (path.startsWith('/erp/cash-sessions?')) return { data: [closedSession], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] } }
      if (path.startsWith('/erp/cash-registers?')) return { data: [activeRegister], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] } }
      throw new Error(`unexpected ${path}`)
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    app.mount()
    await (app.state.reconcile as () => Promise<void>)()
    assert.equal(calls.some(call => call.options?.method === 'POST'), false)
    assert.deepEqual(calls.find(call => call.path.includes('/operations/lookup'))?.options?.headers, { 'Idempotency-Key': pending.key })
    assert.equal(app.state.selectedOperation && Vue.unref(app.state.selectedOperation as Vue.Ref<unknown>), null)
    assert.equal(calls.some(call => call.path === '/erp/cash-sessions/21'), true)
  })

  it('uses server ledger totals instead of totals from the current movement page', async (t) => {
    const responses: Record<string, unknown> = {
      '/erp/cash-registers?per_page=15&page=1': { data: [activeRegister], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] } },
      '/erp/cash-sessions?per_page=15&page=1': { data: [openSession], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] } },
      '/erp/establishments?per_page=100&page=1': { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, totals: [] } },
      '/erp/cash-sessions/21': { data: openSession },
      '/erp/cash-sessions/21/movements?per_page=15&page=1': { data: [{ id: 5, amount: '5.0000', direction: 'in', kind: 'deposit' }], meta: { current_page: 1, last_page: 20, per_page: 15, total: 300, totals: [] }, totals: { movements_in: '500.0000', movements_out: '200.0000', movements_net: '300.0000', expected_balance: '400.0000', needs_reconciliation: false } }
    }
    const app = createCashWorkspace(async path => responses[path] ?? { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } })
    t.after(app.stop)
    app.mount()
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    const ledger = Vue.unref(app.state.ledgerTotals as Vue.Ref<Record<string, string>>)
    assert.equal(ledger.expected_balance, '400.0000')
    assert.equal(ledger.movements_net, '300.0000')
  })

  it('keeps cash payment values and refreshes the session after a closed-session rejection', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const requests: ApiCall[] = []
    let paymentFails = false
    const api: Api = async (path, options) => {
      requests.push({ path, options })
      if (options?.method === 'POST' && path === '/erp/payments' && paymentFails) throw Object.assign(new Error('Sessão fechada'), { statusCode: 422 })
      if (path === '/erp/cash-sessions/21') return { data: paymentFails ? closedSession : openSession }
      if (path === '/erp/cash-sessions/21/movements?per_page=15&page=1') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: paymentFails ? closedSession : openSession }
      if (path.includes('/operations/lookup')) return { data: { state: 'missing' } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    const pay = app.state.payCashPayment as (body: Record<string, unknown>) => Promise<unknown>
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    paymentFails = true
    await pay({ direction: 'in', amount: '15.1250', method: 'cash', currency: 'BRL', erp_cash_session_id: 21, erp_establishment_id: 3, erp_financial_account_id: null })
    assert.equal(Vue.unref(app.state.mutationError as Vue.Ref<string>), 'Sessão fechada')
    assert.equal(requests.some(call => call.path === '/erp/cash-sessions/21'), true)
    assert.deepEqual(requests.find(call => call.options?.method === 'POST')?.options?.body, {
      direction: 'in', amount: '15.1250', method: 'cash', currency: 'BRL',
      erp_cash_session_id: 21, erp_establishment_id: 3, erp_financial_account_id: null
    })
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<typeof closedSession>)?.status, 'closed')
  })

  it('returns stale after a completed write loses Company authority during the detail refresh', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const company = Vue.ref({ id: 7, permissions: ['*'], role: 'owner', access_kind: 'direct', status: 'active' })
    const delayedDetail = deferred<unknown>()
    let sessionGets = 0
    const api: Api = async (path, options) => {
      if (path === '/erp/cash-sessions/21' && !options?.method) {
        sessionGets++
        return sessionGets === 1 ? { data: openSession } : delayedDetail.promise
      }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openSession }
      if (path === '/erp/cash-sessions/21/close' && options?.method === 'POST') return { data: closedSession }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api, company)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    const submit = (app.state.closeSession as (body: { declared_closing_amount: string }) => Promise<unknown>)({ declared_closing_amount: '119.0000' })
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(sessionGets, 2)
    company.value = { ...company.value, id: 8 }
    delayedDetail.resolve({ data: closedSession })
    assert.equal(await submit, null)
  })

  it('does not let an earlier successful close select session A after the user chose B', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const closePost = deferred<unknown>()
    const sessionB = { ...openSession, id: 22, erp_cash_register_id: 12 }
    let firstSessionARead = true
    const api: Api = async (path, options) => {
      if (path === '/erp/cash-sessions/21' && !options?.method) {
        if (firstSessionARead) {
          firstSessionARead = false
          return { data: openSession }
        }
        return { data: closedSession }
      }
      if (path === '/erp/cash-sessions/22') return { data: sessionB }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: closedSession }
      if (path.startsWith('/erp/cash-sessions/22/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: sessionB }
      if (path === '/erp/cash-sessions/21/close' && options?.method === 'POST') return closePost.promise
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    const submit = (app.state.closeSession as (body: { declared_closing_amount: string }) => Promise<unknown>)({ declared_closing_amount: '119.0000' })
    await new Promise(resolve => setImmediate(resolve))
    await (app.state.inspectSession as (id: number) => Promise<void>)(22)
    closePost.resolve({ data: closedSession })
    const result = await submit
    assert.equal(result, null)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<{ id: number }>)?.id, 22)
  })

  it('does not let a delayed close error replace a newer selected session', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const closePost = deferred<unknown>()
    const sessionB = { ...openSession, id: 22, erp_cash_register_id: 12 }
    let firstSessionARead = true
    const api: Api = async (path, options) => {
      if (path === '/erp/cash-sessions/21' && !options?.method) {
        if (firstSessionARead) {
          firstSessionARead = false
          return { data: openSession }
        }
        return { data: closedSession }
      }
      if (path === '/erp/cash-sessions/22') return { data: sessionB }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: closedSession }
      if (path.startsWith('/erp/cash-sessions/22/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: sessionB }
      if (path === '/erp/cash-sessions/21/close' && options?.method === 'POST') return closePost.promise
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    const submit = (app.state.closeSession as (body: { declared_closing_amount: string }) => Promise<unknown>)({ declared_closing_amount: '119.0000' })
    await new Promise(resolve => setImmediate(resolve))
    await (app.state.inspectSession as (id: number) => Promise<void>)(22)
    closePost.resolve(Promise.reject(Object.assign(new Error('Confirme o saldo'), { statusCode: 422 })))
    await submit
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<{ id: number }>)?.id, 22)
  })

  it('reconciles a completed payment with a fresh GET for its frozen session without replacing a newer selection', async (t) => {
    const sessionB = { ...openSession, id: 22, erp_cash_register_id: 12 }
    const calls: string[] = []
    const api: Api = async (path) => {
      calls.push(path)
      if (path === '/erp/cash-sessions/22') return { data: sessionB }
      if (path === '/erp/cash-sessions/21') return { data: closedSession }
      if (path.startsWith('/erp/cash-sessions/22/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: sessionB }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: closedSession }
      if (path.includes('/operations/lookup')) return { data: { state: 'completed', result: { id: 900, erp_cash_session_id: 21 } } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(22)
    const pending = operationRecovery.createPendingOperation('42:7:erp.payments.store', 'erp.payments.store', '/erp/payments', { erp_cash_session_id: 21, amount: '15.0000' }, '72345678-1234-1234-1234-123456789abc')
    ;(app.state.selectedOperation as Vue.Ref<typeof pending | null>).value = pending
    await (app.state.reconcile as () => Promise<void>)()
    assert.equal(calls.includes('/erp/cash-sessions/21'), true)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<{ id: number }>)?.id, 22)
    assert.deepEqual(Vue.unref(app.state.ledgerTotals as Vue.Ref<unknown>), sessionB)
  })

  it('reconciles a completed opening from the bare lookup result id using current session detail', async (t) => {
    const openedSession = { ...openSession, id: 31 }
    const calls: string[] = []
    const api: Api = async (path) => {
      calls.push(path)
      if (path.includes('/operations/lookup')) return { data: { state: 'completed', result: { id: 31, status: 'open' } } }
      if (path === '/erp/cash-sessions/31') return { data: openedSession }
      if (path.startsWith('/erp/cash-sessions/31/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    const pending = operationRecovery.createPendingOperation('42:7:erp.cash-sessions.store', 'erp.cash-sessions.store', '/erp/cash-sessions', { erp_cash_register_id: 11, opening_amount: '0.0000', currency: 'BRL' }, '82345678-1234-1234-1234-123456789abc')
    ;(app.state.selectedOperation as Vue.Ref<typeof pending | null>).value = pending
    await (app.state.reconcile as () => Promise<void>)()
    assert.equal(calls.includes('/erp/cash-sessions/31'), true)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<{ id: number }>)?.id, 31)
    assert.deepEqual(Vue.unref(app.state.selectedSession as Vue.Ref<unknown>), openedSession)
  })

  it('loads and pages register filter options during the read lifecycle without opening the session form', async (t) => {
    const calls: string[] = []
    const firstRegisterPage = { ...activeRegister, id: 11 }
    const secondRegisterPage = { ...activeRegister, id: 99 }
    const api: Api = async (path) => {
      calls.push(path)
      if (path === '/erp/cash-registers?per_page=100&page=1&status=active') return { data: [firstRegisterPage], meta: { current_page: 1, last_page: 2, per_page: 100, total: 101, totals: [] } }
      if (path === '/erp/cash-registers?per_page=100&page=2&status=active') return { data: [secondRegisterPage], meta: { current_page: 2, last_page: 2, per_page: 100, total: 101, totals: [] } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    app.mount()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(calls.includes('/erp/cash-registers?per_page=100&page=1&status=active'), true)
    await (app.state.loadMoreRegisterOptions as () => Promise<void>)()
    assert.equal(calls.includes('/erp/cash-registers?per_page=100&page=2&status=active'), true)
    assert.deepEqual(Vue.unref(app.state.registerOptions as Vue.Ref<{ id: number }[]>).map(register => register.id), [11, 99])
    assert.equal(calls.some(path => path === '/erp/cash-sessions' && path.includes('POST')), false)
  })

  it('clears private cash data on a definitive listing denial and fences a late response', async (t) => {
    const pending = deferred<unknown>()
    let deny = false
    const company = Vue.ref({ id: 7, permissions: ['*'], role: 'owner', access_kind: 'direct', status: 'active' })
    const api: Api = async (path) => {
      if (path === '/erp/cash-sessions?per_page=15&page=1' && deny) throw Object.assign(new Error('Sem acesso'), { statusCode: 403 })
      if (path === '/erp/cash-registers?per_page=15&page=1') return { data: [activeRegister], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] } }
      if (path === '/erp/cash-sessions?per_page=15&page=1') return pending.promise
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api, company)
    t.after(app.stop)
    const loadSessions = app.state.loadList as (kind: 'sessions') => Promise<void>
    const lateLoad = loadSessions('sessions')
    deny = true
    await loadSessions('sessions')
    pending.resolve({ data: [openSession], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] } })
    await lateLoad
    assert.deepEqual(Vue.unref(app.state.sessions as Vue.Ref<unknown[]>), [])
    assert.deepEqual(Vue.unref(app.state.registers as Vue.Ref<unknown[]>), [])
    assert.equal(Vue.unref(app.state.mutationError as Vue.Ref<string>), 'Sem acesso')
  })

  it('keeps session B ledger rows and server totals when a delayed session A movement response arrives', async (t) => {
    const movementsA = deferred<unknown>()
    const sessionB = { ...openSession, id: 22, erp_cash_register_id: 12, expected_balance: '90.0000', movements_net: '-10.0000' }
    const calls: string[] = []
    const api: Api = async (path) => {
      calls.push(path)
      if (path === '/erp/cash-sessions/21') return { data: openSession }
      if (path === '/erp/cash-sessions/22') return { data: sessionB }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return movementsA.promise
      if (path.startsWith('/erp/cash-sessions/22/movements')) return { data: [{ id: 22, amount: '10.0000', direction: 'out', kind: 'withdrawal' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] }, totals: { movements_in: '0.0000', movements_out: '10.0000', movements_net: '-10.0000', expected_balance: '90.0000', needs_reconciliation: false } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    const inspect = app.state.inspectSession as (id: number) => Promise<void>
    const oldRequest = inspect(21)
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(calls.some(path => path.startsWith('/erp/cash-sessions/21/movements')), true)
    await inspect(22)
    movementsA.resolve({ data: [{ id: 21, amount: '25.0000', direction: 'in', kind: 'deposit' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, totals: [] }, totals: { movements_in: '25.0000', movements_out: '0.0000', movements_net: '25.0000', expected_balance: '125.0000', needs_reconciliation: false } })
    await oldRequest
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<typeof sessionB>)?.id, 22)
    assert.equal(Vue.unref(app.state.movements as Vue.Ref<{ id: number }[]>)[0]?.id, 22)
    assert.equal(Vue.unref(app.state.ledgerTotals as Vue.Ref<{ expected_balance: string }>)?.expected_balance, '90.0000')
  })

  it('keeps private views cleared and the operation identity after an uncommitted write-403 refresh', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    let refreshStarted = 0
    const api: Api = async (path, options) => {
      if (path === '/erp/cash-sessions/21') return { data: openSession }
      if (path === '/erp/cash-sessions/21/movements?per_page=15&page=1') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openSession }
      if (path === '/erp/cash-sessions/21/close' && options?.method === 'POST') throw Object.assign(new Error('Sem autorização'), { statusCode: 403 })
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api, undefined, async () => {
      refreshStarted++
      return { identity: null, committed: false }
    })
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    await (app.state.closeSession as (body: { declared_closing_amount: string }) => Promise<unknown>)({ declared_closing_amount: '100.0000' })
    assert.equal(refreshStarted, 1)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<unknown>), null)
    assert.equal(Vue.unref(app.state.sessions as Vue.Ref<unknown[]>).length, 0)
    assert.equal(Vue.unref(app.state.selectedOperation as Vue.Ref<{ operation: string }>)?.operation, 'erp.cash-sessions.close')
    assert.equal((Vue.unref(app.state.allowed as Vue.ComputedRef<(action: string) => boolean>))('erp.cash-sessions.close'), false)
  })

  it('fences an in-flight write-403 refresh after switching Company context', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const company = Vue.ref({ id: 7, permissions: ['*'], role: 'owner', access_kind: 'direct', status: 'active' })
    const refresh = deferred<{ identity: null, committed: false }>()
    let refreshStarted = false
    const calls: string[] = []
    const api: Api = async (path, options) => {
      calls.push(path)
      if (path === '/erp/cash-sessions/21') return { data: openSession }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openSession }
      if (path === '/erp/cash-sessions/21/close' && options?.method === 'POST') throw Object.assign(new Error('Sem autorização'), { statusCode: 403 })
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api, company, () => {
      refreshStarted = true
      return refresh.promise
    })
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    const deniedWrite = (app.state.closeSession as (body: { declared_closing_amount: string }) => Promise<unknown>)({ declared_closing_amount: '100.0000' })
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(refreshStarted, true)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<unknown>), null)

    company.value = { ...company.value, id: 8 }
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
    const callsAfterSwitch = calls.length
    refresh.resolve({ identity: null, committed: false })
    await deniedWrite
    assert.equal(calls.length, callsAfterSwitch)
    assert.equal(Vue.unref(app.state.selectedOperation as Vue.Ref<unknown>), null)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<unknown>), null)
    assert.equal(Vue.unref(app.state.sessions as Vue.Ref<unknown[]>).length, 0)
    assert.equal(Vue.unref(app.state.mutationError as Vue.Ref<string>), '')
  })

  it('handles a definitive denial from a background recovery detail read before checking the selected session', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const sessionB = { ...openSession, id: 22, erp_cash_register_id: 12 }
    const calls: string[] = []
    const api: Api = async (path) => {
      calls.push(path)
      if (path === '/erp/cash-sessions/22') return { data: sessionB }
      if (path.startsWith('/erp/cash-sessions/22/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 0, total: 0, totals: [] }, totals: sessionB }
      if (path.includes('/operations/lookup')) return { data: { state: 'completed', result: { id: 300, erp_cash_session_id: 21 } } }
      if (path === '/erp/cash-sessions/21') throw Object.assign(new Error('A sessão não está mais autorizada'), { statusCode: 403 })
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 0, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(22)
    ;(app.state.sessions as Vue.Ref<typeof sessionB[]>).value = [sessionB]
    ;(app.state.registers as Vue.Ref<typeof activeRegister[]>).value = [activeRegister]
    const pending = operationRecovery.createPendingOperation('42:7:erp.payments.store', 'erp.payments.store', '/erp/payments', { erp_cash_session_id: 21, amount: '15.0000' }, 'a2345678-1234-1234-1234-123456789abc')
    ;(app.state.selectedOperation as Vue.Ref<typeof pending | null>).value = pending
    const callsBeforeReconcile = calls.length
    const authorityGeneration = Vue.unref(app.state.authorityGeneration as Vue.Ref<number>)
    await (app.state.reconcile as () => Promise<void>)()
    assert.equal(Vue.unref(app.state.authorityGeneration as Vue.Ref<number>), authorityGeneration + 1)
    assert.equal(Vue.unref(app.state.selectedSession as Vue.Ref<unknown>), null)
    assert.deepEqual(Vue.unref(app.state.sessions as Vue.Ref<unknown[]>), [])
    assert.deepEqual(Vue.unref(app.state.registers as Vue.Ref<unknown[]>), [])
    assert.equal(calls.filter(path => path.startsWith('/erp/cash-registers?per_page=15&page=1')).length, 0)
    assert.equal(calls.length, callsBeforeReconcile + 2)
    assert.equal(Vue.unref(app.state.mutationError as Vue.Ref<string>), 'A sessão não está mais autorizada')
  })

  it('keeps a newer lookup denial authoritative over an older auth refresh success or rejection', async (t) => {
    for (const outcome of ['commit', 'reject'] as const) {
      const storage = sessionStorageFixture()
      t.after(storage.restore)
      const refresh = deferred<{ identity: { id: number }, committed: true }>()
      const api: Api = async (path, options) => {
        if (path === '/erp/cash-sessions/21') return { data: openSession }
        if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: openSession }
        if (path === '/erp/cash-sessions/21/close' && options?.method === 'POST') throw Object.assign(new Error('Fechamento negado'), { statusCode: 403 })
        if (path.includes('/operations/lookup')) throw Object.assign(new Error('Consulta negada mais recente'), { statusCode: 403 })
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
      }
      const app = createCashWorkspace(api, undefined, () => refresh.promise)
      t.after(app.stop)
      await (app.state.inspectSession as (id: number) => Promise<void>)(21)
      const deniedWrite = (app.state.closeSession as (body: { declared_closing_amount: string }) => Promise<unknown>)({ declared_closing_amount: '119.0000' })
      await new Promise(resolve => setImmediate(resolve))
      await (app.state.reconcile as () => Promise<void>)()
      assert.equal(Vue.unref(app.state.mutationError as Vue.Ref<string>), 'Consulta negada mais recente')
      if (outcome === 'commit') refresh.resolve({ identity: { id: 42 }, committed: true })
      else refresh.reject(new Error('Auth refresh failed later'))
      await deniedWrite
      assert.equal(Vue.unref(app.state.allowed as Vue.ComputedRef<(action: string) => boolean>)('erp.cash-sessions.close'), false)
      assert.equal(Vue.unref(app.state.mutationError as Vue.Ref<string>), 'Consulta negada mais recente')
    }
  })

  it('isolates Company A pending identity and filters from Company B, then restores A identity on return', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const company = Vue.ref({ id: 7, permissions: ['*'], role: 'owner', access_kind: 'direct', status: 'active' })
    const identity = '42:7:erp.cash-sessions.close'
    const recovery = operationRecovery.createPendingOperation(identity, 'erp.cash-sessions.close', '/erp/cash-sessions/21/close', { declared_closing_amount: '0.0000' }, '32345678-1234-1234-1234-123456789abc')
    storage.saved.set('erp-cash:v1:42:7', JSON.stringify(recovery))
    const app = createCashWorkspace(async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }), company)
    t.after(app.stop)
    app.mount()
    assert.equal(Vue.unref(app.state.selectedOperation as Vue.Ref<{ identity: string }>)?.identity, identity)
    app.state.filters.status = 'open'
    app.state.filters.currency = 'BRL'
    company.value = { ...company.value, id: 8 }
    assert.equal(Vue.unref(app.state.selectedOperation as Vue.Ref<unknown>), null)
    assert.equal(Vue.unref(app.state.needsReconciliation as Vue.Ref<boolean>), false)
    assert.equal(app.state.filters.status, '')
    assert.equal(app.state.filters.currency, '')
    assert.equal(storage.saved.has('erp-cash:v1:42:7'), true)
    company.value = { ...company.value, id: 7 }
    assert.equal(Vue.unref(app.state.selectedOperation as Vue.Ref<{ identity: string }>)?.identity, identity)
  })

  it('keeps recovery blocked when a completed lookup cannot remove its stored identity', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const identity = '42:7:erp.cash-sessions.close'
    const pending = operationRecovery.createPendingOperation(identity, 'erp.cash-sessions.close', '/erp/cash-sessions/21/close', { declared_closing_amount: '119.0000' }, '42345678-1234-1234-1234-123456789abc')
    storage.saved.set('erp-cash:v1:42:7', JSON.stringify(pending))
    storage.storage.removeItem = () => {
      throw new Error('storage unavailable')
    }
    const calls: ApiCall[] = []
    const api: Api = async (path, options) => {
      calls.push({ path, options })
      if (path.includes('/operations/lookup')) return { data: { state: 'completed', result: closedSession } }
      if (path === '/erp/cash-sessions/21') return { data: closedSession }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, totals: [] } }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: closedSession }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    await (app.state.reconcile as () => Promise<void>)()
    await (app.state.loadList as (kind: 'establishments') => Promise<void>)('establishments')
    await (app.state.registerCashDrawer as (body: { name: string, erp_establishment_id: number }) => Promise<unknown>)({ name: 'Novo', erp_establishment_id: 3 })
    assert.equal(Vue.unref(app.state.needsReconciliation as Vue.Ref<boolean>), true)
    assert.equal(Vue.unref(app.state.selectedOperation as Vue.Ref<{ key: string }>)?.key, pending.key)
    assert.equal(calls.some(call => call.options?.method === 'POST'), false)
  })

  it('retries a missing close with the original body and key despite a changed live close draft', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const identity = '42:7:erp.cash-sessions.close'
    const pending = operationRecovery.createPendingOperation(identity, 'erp.cash-sessions.close', '/erp/cash-sessions/21/close', { declared_closing_amount: '119.0000' }, '52345678-1234-1234-1234-123456789abc')
    storage.saved.set('erp-cash:v1:42:7', JSON.stringify(pending))
    const calls: ApiCall[] = []
    const api: Api = async (path, options) => {
      calls.push({ path, options })
      if (path.includes('/operations/lookup')) throw Object.assign(new Error('Not found'), { statusCode: 404 })
      if (path === '/erp/cash-sessions/21') return { data: closedSession }
      if (path.startsWith('/erp/cash-sessions/21/movements')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] }, totals: closedSession }
      if (path === pending.path && options?.method === 'POST') return { data: closedSession }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }
    const app = createCashWorkspace(api)
    t.after(app.stop)
    await (app.state.inspectSession as (id: number) => Promise<void>)(21)
    await (app.state.reconcile as () => Promise<void>)()
    assert.equal(Vue.unref(app.state.canRetryExact as Vue.Ref<boolean>), true)
    await (app.state.closeSession as (body: { declared_closing_amount: string }) => Promise<unknown>)({ declared_closing_amount: '999.0000' })
    assert.equal(Vue.unref(app.state.canRetryExact as Vue.Ref<boolean>), true)
    await (app.state.retryExact as () => Promise<unknown>)()
    const retry = calls.find(call => call.path === pending.path && call.options?.method === 'POST')
    assert.deepEqual(retry?.options?.body, { declared_closing_amount: '119.0000' })
    assert.deepEqual(retry?.options?.headers, { 'Idempotency-Key': pending.key })
  })
})

function cashPagination(root: unknown): Record<string, unknown>[] {
  if (!root || typeof root !== 'object') return []
  const node = root as { props?: Record<string, unknown>, children?: unknown[] }
  return [...(node.props?.['data-pagination'] === true ? [node.props] : []), ...(node.children ?? []).flatMap(cashPagination)]
}

describe('real cash list footers', () => {
  for (const kind of ['registers', 'sessions'] as const) {
    it(`pages ${kind} once with its filters, suppresses pending requests and respects revoked authority`, async (t) => {
      const calls: string[] = []
      const pending = deferred<unknown>()
      const workspace = createCashWorkspace(async (path) => {
        calls.push(path)
        const current = Number(new URL(path, 'http://test').searchParams.get('page') ?? 1)
        if (current === 2) return pending.promise
        return { data: [], meta: { current_page: current, last_page: 3, per_page: 15, total: 35, totals: [] } }
      })
      t.after(workspace.stop)
      const state = workspace.state as { selectedPage: Vue.Ref<string>, filters: Record<string, unknown>, loadList: (kind: string, page?: number) => Promise<void> }
      state.selectedPage.value = kind
      Object.assign(state.filters, { status: 'open', currency: 'BRL', erp_cash_register_id: '11', erp_establishment_id: '3', date_from: '2026-10-01', date_to: '2026-10-31' })
      await state.loadList(kind)
      const mounted = mountCashWorkspace({ workspaceState: workspace.state, initialHash: `#${kind}` })
      t.after(() => mounted.app.unmount())
      await Vue.nextTick()
      const pagination = () => cashPagination(mounted.root)[0]!
      const change = (value: number) => (pagination()['onUpdate:page'] as (page: number) => void)(value)
      change(1)
      change(0)
      assert.equal(calls.length, 1)
      change(2)
      await Vue.nextTick()
      assert.equal(calls.length, 2)
      const query = new URL(calls[1]!, 'http://test').searchParams
      assert.equal(query.get('page'), '2')
      if (kind === 'sessions') for (const [key, value] of Object.entries(state.filters)) if (!['page', 'per_page'].includes(key) && value !== '') assert.equal(query.get(key), value)
      change(3)
      await Vue.nextTick()
      assert.equal(calls.length, 2)
      pending.resolve({ data: [], meta: { current_page: 2, last_page: 3, per_page: 15, total: 35, totals: [] } })
      await pending.promise
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(pagination().page, 2)
      assert.equal(state.selectedPage.value, kind)
      assert.match(textContent(mounted.root), /35 registros/)
      workspace.company.value = { ...workspace.company.value, role: 'viewer', permissions: [] }
      await Vue.nextTick()
      assert.equal(cashPagination(mounted.root).length, 0)
      const before = calls.length
      await state.loadList(kind, 3)
      assert.equal(calls.length, before)
    })
  }

  it('guards the real movement loader throughout the awaited page, retaining the session and server total', async (t) => {
    const calls: string[] = []
    const pending = deferred<unknown>()
    const workspace = createCashWorkspace(async (path) => {
      calls.push(path)
      if (path === '/erp/cash-sessions/21') return { data: openSession }
      const current = Number(new URL(path, 'http://test').searchParams.get('page') ?? 1)
      if (current === 2) return pending.promise
      return { data: [], meta: { current_page: current, last_page: 3, per_page: 15, total: 35 }, totals: { movements_in: '90.0000' } }
    })
    t.after(workspace.stop)
    const state = workspace.state as { inspectSession: (id: number) => Promise<void>, selectedSession: Vue.Ref<{ id: number }> }
    await state.inspectSession(21)
    const mounted = mountCashWorkspace({ workspaceState: workspace.state })
    t.after(() => mounted.app.unmount())
    await Vue.nextTick()
    const pagination = () => cashPagination(mounted.root).at(-1)!
    const change = (value: number) => (pagination()['onUpdate:page'] as (page: number) => void)(value)
    const before = calls.length
    change(2)
    change(2) // Synchronous repeated native events must also hit the wrapper guard.
    await Vue.nextTick()
    change(3)
    assert.equal(calls.length, before + 1)
    assert.equal(calls.at(-1), '/erp/cash-sessions/21/movements?per_page=15&page=2')
    assert.equal(pagination().disabled, true)
    pending.resolve({ data: [], meta: { current_page: 2, last_page: 3, per_page: 15, total: 35 } })
    await pending.promise
    await Vue.nextTick()
    await Vue.nextTick()
    for (let turn = 0; turn < 8 && pagination().disabled; turn++) {
      await Promise.resolve()
      await Vue.nextTick()
    }
    assert.equal(pagination().page, 2)
    assert.equal(pagination().disabled, false)
    assert.equal(state.selectedSession.value.id, 21)
    assert.match(textContent(mounted.root), /Histórico: 35 movimentos/)
    workspace.company.value = { ...workspace.company.value, role: 'viewer', permissions: [] }
    await Vue.nextTick()
    assert.equal(cashPagination(mounted.root).length, 0)
  })
})

// Each actual loader keeps confirmed data on transient failure; its mounted consumer must say so.
describe('cash failed refresh guidance', () => {
  for (const kind of ['registers', 'sessions', 'movements'] as const) {
    for (const prior of ['none', 'rows', 'empty'] as const) {
      it(`identifies ${kind} failure with prior ${prior} and clears stale feedback on recovery`, async (t) => {
        let fail = false
        const calls: string[] = []
        const row = kind === 'registers' ? activeRegister : kind === 'sessions' ? openSession : { id: 5, erp_cash_session_id: 21, amount: '5.0000', direction: 'in', kind: 'deposit' }
        const confirmed = prior === 'rows' ? [row] : []
        const workspace = createCashWorkspace(async (path, options) => {
          assert.equal(options?.method, undefined, 'presentation checks perform reads only')
          calls.push(path)
          if (fail) throw { statusCode: 503, data: { message: 'Caixa indisponível' } }
          return { data: confirmed, meta: { current_page: 1, last_page: 1, per_page: 15, total: confirmed.length, totals: [] } }
        })
        t.after(workspace.stop)
        const state = workspace.state as {
          loadList: (kind: string) => Promise<void>
          loadMovementPage: (id: number) => Promise<void>
          selectedSession: Vue.Ref<typeof openSession | null>
          registers: Vue.Ref<unknown[]>
          sessions: Vue.Ref<unknown[]>
          movements: Vue.Ref<unknown[]>
        }
        if (kind === 'movements') state.selectedSession.value = openSession
        const read = () => kind === 'movements' ? state.loadMovementPage(21) : state.loadList(kind)
        if (prior !== 'none') await read()
        const mounted = mountCashWorkspace({ workspaceState: workspace.state, initialHash: kind === 'registers' ? '#registers' : '#sessions' })
        t.after(() => mounted.app.unmount())
        await Vue.nextTick()
        fail = true
        await read()
        await Vue.nextTick()
        const text = textContent(mounted.root)
        assert.match(text, /Caixa indisponível/)
        assert.match(text, prior === 'none'
          ? kind === 'movements' ? /histórico ainda não foi carregado com sucesso/ : /lista ainda não foi carregada com sucesso/
          : kind === 'movements' ? /último histórico confirmado/ : /última lista confirmada/)
        assert.deepEqual(state[kind].value, confirmed)
        assert.ok(mounted.tableRows.some(rows => JSON.stringify(rows) === JSON.stringify(confirmed)), 'retained records remain bound to a mounted table')
        const ordinaryEmpty = kind === 'registers' ? 'Nenhum caixa cadastrado.' : kind === 'sessions' ? 'Nenhuma sessão de caixa encontrada.' : 'Nenhum movimento registrado nesta sessão.'
        if (prior === 'empty') assert.equal(text.includes(ordinaryEmpty), false, 'failed refresh must not present ordinary successful-empty copy')
        fail = false
        await read()
        await Vue.nextTick()
        const recovered = textContent(mounted.root)
        assert.doesNotMatch(recovered, /última lista confirmada|último histórico confirmado|ainda não foi carregad/)
        assert.equal(new Set(calls).size, 1, 'recovery keeps the original query')
        if (prior !== 'rows') assert.ok(recovered.includes(ordinaryEmpty), 'successful empty recovery restores ordinary empty guidance')
      })
    }
  }
})
