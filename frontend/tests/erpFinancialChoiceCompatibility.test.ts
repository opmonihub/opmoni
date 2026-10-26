import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as financeUtils from '../shared/utils/erpFinance.ts'
import * as choiceUtils from '../app/utils/erpFinancialChoiceCompatibility.ts'
import * as operationRecovery from '../shared/utils/erpOperationRecovery.ts'

type Props = Record<string, unknown>
type Form = { emitted: Record<string, unknown>[], exposed: Record<string, unknown>, stop: () => void }

function setupForm(name: string, input: Props = {}): Form {
  const props = Vue.reactive(input)
  const path = new URL(`../app/components/erp/${name}.vue`, import.meta.url)
  const { descriptor } = parse(readFileSync(path, 'utf8'))
  const script = compileScript(descriptor, { id: name })
  const output = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  }).outputText
  const moduleExports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', 'reactive', 'computed', 'ref', 'watch', output)
  runtime((path: string) => path === '#shared/utils/erpFinance' ? financeUtils : path === '~/utils/erpFinancialChoiceCompatibility' ? choiceUtils : Vue,
    moduleExports, Vue.reactive, Vue.computed, Vue.ref, Vue.watch)
  const emitted: Record<string, unknown>[] = []
  let exposed: Record<string, unknown> = {}
  const scope = Vue.effectScope()
  scope.run(() => (moduleExports.default as { setup: (props: unknown, context: unknown) => unknown }).setup(props, {
    expose(value: Record<string, unknown>) { exposed = value },
    emit(event: string, payload?: Record<string, unknown>) {
      if (event === 'submit' && payload) emitted.push(payload)
    }
  }))
  return { emitted, exposed, stop: () => scope.stop() }
}

const account = { id: 5, name: 'Conta BRL', currency: 'BRL', erp_establishment_id: 3, status: 'active' }
const companyAccount = { ...account, erp_establishment_id: null }
const session = { id: 9, currency: 'BRL', erp_establishment_id: 3, status: 'open', can_operate: true }

function compileComposable(path: string, imports: Record<string, unknown>, bindings: Record<string, unknown>) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8')
  const output = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  }).outputText
  const moduleExports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', ...Object.keys(bindings), output)
  runtime((specifier: string) => {
    if (specifier === '~/composables/useErpReadScope' || specifier === '~/composables/useErpListNavigation')
      return loadAppComposable(specifier.slice(2) + '.ts', { ...bindings, useState: (_key: string, init: () => unknown) => Vue.ref(init()) })
    const dependency = imports[specifier]
    if (!dependency) throw new Error(`Unexpected test import ${specifier}`)
    return dependency
  }, moduleExports, ...Object.values(bindings))
  return moduleExports
}

function workspaceContext() {
  const key = Vue.ref('42:7:owner')
  return { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
}

function createCashWorkspace(api: (path: string, options?: { method?: string, body?: unknown }) => Promise<unknown>) {
  const user = Vue.ref({ id: 42 })
  const currentCompany = Vue.ref({ id: 7, permissions: ['*'], role: 'owner', access_kind: 'direct', status: 'active' })
  const context = workspaceContext()
  let mounted = () => {}
  const moduleExports = compileComposable('../app/composables/useErpCashWorkspace.ts', {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpFinance': financeUtils,
    '#shared/utils/erpOperationRecovery': operationRecovery
  }, {
    ref: Vue.ref,
    reactive: Vue.reactive,
    computed: Vue.computed,
    watch: Vue.watch,
    onMounted(callback: () => void) { mounted = callback },
    onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus: async () => ({ identity: { id: 42 }, committed: true }) }),
    useErpAdministrationContext: () => context
  })
  const scope = Vue.effectScope()
  const state = scope.run(() => (moduleExports.useErpCashWorkspace as () => Record<string, unknown>)())!
  return { state, mount: () => mounted(), stop: () => scope.stop() }
}

function createFinanceWorkspace(api: (path: string, options?: { method?: string, body?: unknown, headers?: Record<string, string> }) => Promise<unknown>, permissions: string[] = ['*']) {
  const user = Vue.ref({ id: 42 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner', access_kind: 'direct', status: 'active' })
  const context = workspaceContext()
  const moduleExports = compileComposable('../app/composables/useErpFinanceWorkspace.ts', {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpFinance': financeUtils,
    '#shared/utils/erpOperationRecovery': operationRecovery
  }, {
    ref: Vue.ref,
    reactive: Vue.reactive,
    computed: Vue.computed,
    watch: Vue.watch,
    onMounted: () => {},
    onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus: async () => ({ identity: { id: 42 }, committed: true }) }),
    useErpAdministrationContext: () => context
  })
  const scope = Vue.effectScope()
  const state = scope.run(() => (moduleExports.useErpFinanceWorkspace as () => Record<string, unknown>)())!
  return { state, stop: () => scope.stop() }
}

describe('financial choice compatibility', () => {
  it('keeps an incompatible retained account visible and blocks its submission', (t) => {
    const form = setupForm('ErpPaymentForm', {
      referenceAvailability: { establishments: true, accounts: true, cashSessions: true },
      accounts: [account],
      establishments: [{ id: 3, name: 'Matriz' }, { id: 4, name: 'Filial', status: 'active' }],
      sessions: [session]
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, {
      amount: '10.00',
      method: 'bank_transfer',
      currency: 'USD',
      erp_establishment_id: '3',
      erp_financial_account_id: '5'
    })

    ;(form.exposed.submit as () => void)()

    assert.equal(state.erp_financial_account_id, '5')
    assert.equal(form.emitted.length, 0)
    assert.deepEqual((form.exposed.accountItems as Vue.ComputedRef<Array<{ value: string, label: string, disabled?: boolean }>>).value.at(-1), {
      label: 'Conta #5 (seleção incompatível)', value: '5', disabled: true
    })
    assert.match(String((form.exposed.localError as Vue.Ref<string>).value), /conta financeira/i)
  })

  it('requires a current compatible open session for cash method', (t) => {
    const form = setupForm('ErpPaymentForm', {
      referenceAvailability: { establishments: true, accounts: true, cashSessions: true },
      accounts: [account],
      establishments: [{ id: 3, name: 'Matriz' }, { id: 4, name: 'Filial', status: 'active' }],
      sessions: [session]
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, {
      amount: '10.00',
      method: 'cash',
      currency: 'BRL',
      erp_establishment_id: '4',
      erp_cash_session_id: '9'
    })

    ;(form.exposed.submit as () => void)()

    assert.equal(state.erp_cash_session_id, '9')
    assert.equal(form.emitted.length, 0)
    assert.match(String((form.exposed.localError as Vue.Ref<string>).value), /sessão|caixa/i)
  })

  it('keeps a retained cash session visible and rejects it when the method changes', (t) => {
    const form = setupForm('ErpPaymentForm', {
      referenceAvailability: { establishments: true, accounts: true, cashSessions: true },
      accounts: [account],
      establishments: [{ id: 3, name: 'Matriz', status: 'active' }],
      sessions: [session]
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '10.00', erp_cash_session_id: '9', method: 'bank_transfer' })

    ;(form.exposed.submit as () => void)()

    assert.equal(state.erp_cash_session_id, '9')
    assert.equal(form.emitted.length, 0)
    assert.match(String((form.exposed.localError as Vue.Ref<string>).value), /método dinheiro/i)
  })

  it('renders the whole-company sentinel only once as an available choice', (t) => {
    const form = setupForm('ErpPaymentForm', { establishments: [], accounts: [], sessions: [] })
    t.after(form.stop)

    const items = (form.exposed.establishmentItems as Vue.ComputedRef<Array<{ value: string, label: string, disabled?: boolean }>>).value
    assert.deepEqual(items, [])
  })

  it('keeps an incompatible account visible and blocks a cash-form payment', (t) => {
    const form = setupForm('ErpCashPaymentForm', {
      session: { ...session, erp_establishment_id: 4 },
      accounts: [account],
      referencesCurrent: true
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '10.00', erp_financial_account_id: '5' })

    ;(form.exposed.submit as () => void)()

    assert.equal(state.erp_financial_account_id, '5')
    assert.equal(form.emitted.length, 0)
    assert.equal((form.exposed.accountItems as Vue.ComputedRef<Array<{ value: string, disabled?: boolean }>>).value.at(-1)?.disabled, true)
    assert.match(String((form.exposed.localError as Vue.Ref<string>).value), /conta financeira/i)
  })

  it('does not submit from stale references after a failed refresh', (t) => {
    const form = setupForm('ErpPaymentForm', {
      accounts: [account],
      establishments: [{ id: 3, name: 'Matriz' }],
      sessions: [session],
      referenceAvailability: { establishments: true, accounts: false, cashSessions: false },
      referenceError: 'Não foi possível atualizar as referências.'
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '10.00', erp_financial_account_id: '5' })

    ;(form.exposed.submit as () => void)()

    assert.equal(form.emitted.length, 0)
    assert.match(String((form.exposed.localError as Vue.Ref<string>).value), /atualiz|referência/i)
  })

  it('blocks a retained session when its own reference list is not current', (t) => {
    const form = setupForm('ErpPaymentForm', {
      referenceAvailability: { establishments: true, accounts: true, cashSessions: false },
      sessions: [session]
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '10.00', method: 'cash', erp_cash_session_id: '9' })
    ;(form.exposed.submit as () => void)()

    assert.equal(state.erp_cash_session_id, '9')
    assert.equal(form.emitted.length, 0)
    assert.match(String((form.exposed.localError as Vue.Ref<string>).value), /atualiz|referência/i)
  })

  it('preserves a compatible payment payload for the existing server validation contract', (t) => {
    const form = setupForm('ErpPaymentForm', {
      referenceAvailability: { establishments: true, accounts: true, cashSessions: false },
      accounts: [companyAccount],
      establishments: [{ id: 3, name: 'Matriz' }],
      sessions: [session]
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '10.00', erp_financial_account_id: '5' })

    ;(form.exposed.submit as () => void)()

    assert.deepEqual(form.emitted[0], {
      direction: 'in',
      amount: '10.00',
      method: 'bank_transfer',
      currency: 'BRL',
      erp_establishment_id: null,
      erp_financial_account_id: 5,
      erp_cash_session_id: null
    })
  })

  it('submits a noncash payment with exact grants when the unused cash-session index is not granted', async (t) => {
    const prior = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
    Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: {
      getItem: () => null, setItem: () => {}, removeItem: () => {}
    } })
    t.after(() => prior ? Object.defineProperty(globalThis, 'sessionStorage', prior) : Reflect.deleteProperty(globalThis, 'sessionStorage'))
    const calls: Array<{ path: string, method?: string }> = []
    const app = createFinanceWorkspace(async (path, options) => {
      calls.push({ path, method: options?.method })
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    }, ['erp.payments.store', 'erp.payments.index', 'erp.establishments.index', 'erp.financial-accounts.index'])
    t.after(app.stop)
    await (app.state.loadReferences as () => Promise<void>)()

    const form = setupForm('ErpPaymentForm', {
      referenceAvailability: (app.state.referenceAvailability as Vue.ComputedRef<Record<string, boolean>>).value,
      accounts: (app.state.accountOptions as Vue.Ref<unknown[]>).value,
      establishments: (app.state.establishments as Vue.Ref<unknown[]>).value,
      sessions: (app.state.cashSessions as Vue.Ref<unknown[]>).value
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '10.00', method: 'bank_transfer', erp_cash_session_id: 'none' })
    ;(form.exposed.submit as () => void)()

    assert.equal(form.emitted.length, 1)
    assert.equal((app.state.referenceAvailability as Vue.ComputedRef<Record<string, boolean>>).value.cashSessions, false)
    await (app.state.post as (path: string, body: Record<string, unknown>) => Promise<unknown>)('/erp/payments', form.emitted[0]!)
    assert.equal(calls.some(call => call.path === '/erp/payments' && call.method === 'POST'), true)
  })

  it('submits a payment without optional references for a payments-only grant', (t) => {
    const app = createFinanceWorkspace(async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }), ['erp.payments.store', 'erp.payments.index'])
    t.after(app.stop)

    const form = setupForm('ErpPaymentForm', {
      referenceAvailability: (app.state.referenceAvailability as Vue.ComputedRef<Record<string, boolean>>).value,
      accounts: (app.state.accountOptions as Vue.Ref<unknown[]>).value,
      establishments: (app.state.establishments as Vue.Ref<unknown[]>).value,
      sessions: (app.state.cashSessions as Vue.Ref<unknown[]>).value
    })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '10.00', method: 'bank_transfer' })
    ;(form.exposed.submit as () => void)()

    assert.equal(form.emitted.length, 1)
  })

  it('requires an active current register before opening a cash session', (t) => {
    const registers = [{ id: 11, name: 'Caixa principal', status: 'active' }]
    const form = setupForm('ErpCashSessionForm', { mode: 'open', registers })
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { erp_cash_register_id: '11', opening_amount: '0.00', currency: 'BRL' })
    registers[0]!.status = 'inactive'

    ;(form.exposed.submit as () => void)()

    assert.equal(state.erp_cash_register_id, '11')
    assert.equal(form.emitted.length, 0)
    assert.equal((form.exposed.registerItems as Vue.ComputedRef<Array<{ value: string, label: string, disabled?: boolean }>>).value.at(-1)?.disabled, true)
    assert.match(String((form.exposed.localError as Vue.Ref<string>).value), /caixa|atualiz/i)
  })

  it('blocks opening against a stale register list after its refresh fails', async (t) => {
    const calls: Array<{ path: string, method?: string }> = []
    const app = createCashWorkspace(async (path, options) => {
      calls.push({ path, method: options?.method })
      if (path === '/erp/cash-registers?per_page=100&page=1&status=active') {
        throw Object.assign(new Error('Não foi possível atualizar os caixas.'), { statusCode: 503 })
      }
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    })
    t.after(app.stop)
    app.mount()
    await new Promise(resolve => setImmediate(resolve))

    assert.equal((app.state.registerOptionsCurrent as Vue.ComputedRef<boolean>).value, false)
    assert.match((app.state.registerOptionsError as Vue.Ref<string>).value, /atualizar os caixas/i)
    const result = await (app.state.openCashSession as (payload: Record<string, unknown>) => Promise<unknown>)({
      erp_cash_register_id: 11, opening_amount: '0.00', currency: 'BRL'
    })

    assert.equal(result, null)
    assert.equal(calls.some(call => call.path === '/erp/cash-sessions' && call.method === 'POST'), false)
  })

  it('requires a reconciled open session for cash movements', async (t) => {
    const calls: Array<{ path: string, method?: string }> = []
    const app = createCashWorkspace(async (path, options) => {
      calls.push({ path, method: options?.method })
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] } }
    })
    t.after(app.stop)
    ;(app.state.selectedSession as Vue.Ref<Record<string, unknown> | null>).value = {
      id: 21, currency: 'BRL', status: 'open', can_operate: true, needs_reconciliation: true
    }

    const result = await (app.state.postMovement as (payload: Record<string, unknown>) => Promise<unknown>)(
      { direction: 'in', kind: 'deposit', amount: '10.00', reason: 'Suprimento' }
    )

    assert.equal(result, null)
    assert.match((app.state.mutationError as Vue.Ref<string>).value, /sessão aberta, reconciliada/i)
    assert.equal(calls.some(call => call.path.endsWith('/movements') && call.method === 'POST'), false)
  })

  it('keeps server 422 field rejection and recovery behavior unchanged', async (t) => {
    const prior = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
    Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: {
      getItem: () => null, setItem: () => {}, removeItem: () => {}
    } })
    t.after(() => prior ? Object.defineProperty(globalThis, 'sessionStorage', prior) : Reflect.deleteProperty(globalThis, 'sessionStorage'))
    const app = createFinanceWorkspace(async () => {
      throw Object.assign(new Error('Conta inativa ou moeda incompatível.'), {
        statusCode: 422,
        data: { errors: { erp_financial_account_id: ['Conta inativa ou moeda incompatível.'] } }
      })
    })
    t.after(app.stop)
    const result = await (app.state.post as (path: string, body: Record<string, unknown>) => Promise<unknown>)(
      '/erp/payments', { direction: 'in', amount: '10.00', method: 'bank_transfer', currency: 'BRL', erp_financial_account_id: 5 }
    )

    assert.equal(result, null)
    assert.deepEqual((app.state.fieldErrors as Vue.Ref<Record<string, string>>).value, {
      erp_financial_account_id: 'Conta inativa ou moeda incompatível.'
    })
    assert.equal((app.state.needsReconciliation as Vue.Ref<boolean>).value, false)
  })
})
