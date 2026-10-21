import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as financeUtils from '../shared/utils/erpFinance.ts'
import type { ErpFinancePage, ErpObligation, ErpPayment, ErpFinanceTotals } from '../shared/types/erpBusiness.ts'

type Api = (path: string, options?: { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> }) => Promise<unknown>
type Bindings = Record<string, unknown>

function compileModule(source: string, imports: Record<string, unknown>, bindings: Bindings) {
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const moduleExports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', ...Object.keys(bindings), output)
  runtime((path: string) => {
    const value = imports[path]
    if (!value) throw new Error(`Unexpected test import ${path}`)
    return value
  }, moduleExports, ...Object.values(bindings))
  return moduleExports
}

function setupForm(name: string) {
  const file = new URL(`../app/components/erp/${name}.vue`, import.meta.url)
  assert.ok(existsSync(file), `${name} should exist`)
  const source = readFileSync(file, 'utf8')
  const { descriptor } = parse(source)
  const script = compileScript(descriptor, { id: name })
  const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const moduleExports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', 'reactive', 'computed', 'ref', output)
  runtime((path: string) => path === '#shared/utils/erpFinance' ? financeUtils : Vue, moduleExports, Vue.reactive, Vue.computed, Vue.ref)
  const emitted: Record<string, unknown>[] = []
  let exposed: Record<string, unknown> = {}
  const scope = Vue.effectScope()
  scope.run(() => (moduleExports.default as { setup: (props: unknown, context: unknown) => unknown }).setup({}, {
    expose(value: Record<string, unknown>) { exposed = value },
    emit(event: string, payload: Record<string, unknown>) { if (event === 'submit') emitted.push(payload) }
  }))
  return { emitted, exposed, stop: () => scope.stop() }
}

function compileSfc(name: string, imports: Record<string, unknown>) {
  const file = new URL(`../app/components/erp/${name}.vue`, import.meta.url)
  const source = readFileSync(file, 'utf8')
  const { descriptor } = parse(source)
  const script = compileScript(descriptor, { id: name, inlineTemplate: true })
  return (compileModule(script.content, { 'vue': Vue, '#shared/utils/erpFinance': financeUtils, ...imports }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch
  }).default as Vue.Component)
}

function mountFinanceWorkspace(finance: Record<string, unknown>) {
  const components: Record<string, Vue.Component> = {}
  const modalOpenStates: boolean[] = []
  for (const name of ['ErpFinancialAccountForm', 'ErpObligationForm', 'ErpPaymentForm'])
    components[name] = compileSfc(name, {})
  components.ErpFinanceWorkspace = compileSfc('ErpFinanceWorkspace', { '~/composables/useErpFinanceWorkspace': { useErpFinanceWorkspace: () => finance } })
  components.ErpCatalogPageShell = { setup: (_props, context) => () => Vue.h('main', context.slots.default?.()) }
  components.UButton = { inheritAttrs: false, props: ['label'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, 'data-test-label': props.label }, props.label) }
  components.UTable = { props: ['data'], setup: (props, context) => () => Vue.h('div', {}, [
    ...(props.data as ErpPayment[]).map(original => Vue.h('div', {}, [
      ...(context.slots['id-cell']?.({ row: { original } }) ?? []),
      ...(context.slots['actions-cell']?.({ row: { original } }) ?? [])
    ])),
    ...(context.slots.empty?.() ?? [])
  ]) }
  components.UModal = { props: ['open'], setup: (props, context) => {
    const index = modalOpenStates.length
    modalOpenStates.push(Boolean(props.open))
    Vue.watch(() => props.open, (value) => {
      modalOpenStates[index] = Boolean(value)
    })
    return () => props.open ? Vue.h('div', {}, [context.slots.body?.(), context.slots.footer?.({ close: () => context.emit('update:open', false) })]) : null
  } }
  for (const name of ['UAlert', 'UForm', 'UFormField', 'UInput', 'USelect', 'UTextarea', 'UBadge', 'USkeleton', 'UEmpty', 'UPagination'])
    components[name] = { inheritAttrs: false, setup: (_props, context) => () => Vue.h('div', context.attrs, context.slots.default?.()) }
  const root = { children: [] as unknown[], parent: null as unknown }
  const renderer = Vue.createRenderer({
    patchProp(node: { props: Record<string, unknown> }, key, _previous, next) { node.props[key] = next },
    insert(node: { parent?: unknown }, parent: { children: unknown[] }, anchor?: unknown) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node: { parent?: { children: unknown[] } }) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
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
      const index = node.parent.children.indexOf(node)
      return node.parent.children[index + 1] ?? null
    }
  })
  const app = renderer.createApp(components.ErpFinanceWorkspace)
  for (const [name, component] of Object.entries(components)) app.component(name, component)
  app.mount(root)
  function exposed(name: string): Record<string, unknown> {
    const visit = (vnode: Vue.VNode): Record<string, unknown> | null => {
      const instance = vnode.component
      if (instance?.type === components[name]) return instance.exposed ?? {}
      const result = instance?.subTree && visit(instance.subTree)
      if (result) return result
      for (const child of (vnode.children ?? []) as Vue.VNode[]) {
        if (child && typeof child === 'object') {
          const found = visit(child)
          if (found) return found
        }
      }
      return null
    }
    const found = app._instance && visit(app._instance.subTree)
    assert.ok(found, `${name} should be mounted inside the real workspace`)
    return found
  }
  function button(label: string) {
    const visit = (node: { tag?: string, props?: Record<string, unknown>, children?: unknown[] }): { props: Record<string, unknown> } | null => {
      if (node.props?.['data-test-label'] === label) return { props: node.props }
      for (const child of node.children ?? []) {
        if (child && typeof child === 'object') {
          const result = visit(child as { tag?: string, props?: Record<string, unknown>, children?: unknown[] })
          if (result) return result
        }
      }
      return null
    }
    const found = root.children.map(child => child && typeof child === 'object' ? visit(child as { tag?: string, props?: Record<string, unknown>, children?: unknown[] }) : null).find(Boolean)
    assert.ok(found, `button ${label} should be mounted`)
    return found.props
  }
  function control(predicate: (props: Record<string, unknown>) => boolean, last = false) {
    let matched: Record<string, unknown> | null = null
    const visit = (node: { tag?: string, props?: Record<string, unknown>, children?: unknown[] }): Record<string, unknown> | null => {
      if (node.props && predicate(node.props)) {
        matched = node.props
        if (!last) return matched
      }
      for (const child of node.children ?? []) {
        if (child && typeof child === 'object') {
          const result = visit(child as { tag?: string, props?: Record<string, unknown>, children?: unknown[] })
          if (result && !last) return result
        }
      }
      return matched
    }
    const found = root.children.map(child => child && typeof child === 'object' ? visit(child as { tag?: string, props?: Record<string, unknown>, children?: unknown[] }) : null).find(Boolean)
    assert.ok(found, 'matching form control should be mounted')
    return found
  }
  return { exposed, button, control, modalOpenStates, unmount: () => app.unmount() }
}

const emptyMeta = { current_page: 1, last_page: 1, per_page: 15, total: 0, totals: [] as ErpFinanceTotals[] }
function page<T>(data: T[], total = data.length, totals: ErpFinanceTotals[] = []): ErpFinancePage<T> {
  return { data, meta: { ...emptyMeta, total, last_page: Math.max(1, Math.ceil(total / 15)), totals } }
}
function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function sessionStorageFixture(fail: 'read' | 'write' | 'remove' | null = null) {
  const saved = new Map<string, string>()
  const prior = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  const storage = {
    getItem(key: string) {
      if (fail === 'read') throw new Error('Session storage read blocked')
      return saved.get(key) ?? null
    },
    setItem(key: string, value: string) {
      if (fail === 'write') throw new Error('Session storage write blocked')
      saved.set(key, value)
    },
    removeItem(key: string) {
      if (fail === 'remove') throw new Error('Session storage remove blocked')
      saved.delete(key)
    }
  }
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: storage })
  return {
    saved,
    restore() {
      if (prior) Object.defineProperty(globalThis, 'sessionStorage', prior)
      else Reflect.deleteProperty(globalThis, 'sessionStorage')
    }
  }
}

async function settle() {
  await Promise.resolve()
  await Vue.nextTick()
  await Promise.resolve()
}

async function settleUntil(ready: () => boolean) {
  for (let attempt = 0; attempt < 30 && !ready(); attempt++) await settle()
  assert.ok(ready(), 'asynchronous workspace action should settle')
}

function createWorkspace(api: Api, refreshCsrf: () => Promise<void> = async () => {}) {
  const user = Vue.ref({ id: 42 })
  const company = Vue.ref({ id: 7, permissions: ['*'], role: 'owner', access_kind: 'direct', status: 'active' })
  const key = Vue.computed(() => JSON.stringify([user.value.id, company.value.id, company.value.permissions.join(',')]))
  let generation = 0
  const context = {
    key,
    capture: () => ({ key: key.value, generation }),
    isCurrent: (token: { key: string, generation: number }) => token.key === key.value && token.generation === generation
  }
  let mounted: () => void = () => {}
  const bindings = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted(callback: () => void) { mounted = callback }, onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf }), useAuth: () => ({ user, currentCompany: company }), useErpAdministrationContext: () => context
  }
  const moduleExports = compileModule(readFileSync(new URL('../app/composables/useErpFinanceWorkspace.ts', import.meta.url), 'utf8'), {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpFinance': financeUtils
  }, bindings)
  const scope = Vue.effectScope()
  const state = scope.run(() => (moduleExports.useErpFinanceWorkspace as () => Record<string, unknown>)())!
  return {
    state,
    user,
    company,
    mount: () => mounted(),
    bump: () => { generation++ },
    stop: () => scope.stop()
  }
}

const obligation: ErpObligation = {
  id: 9, erp_establishment_id: 3, erp_financial_account_id: null, amount: '120.0000', open_amount: '120.0000', allocated_amount: '0.0000',
  currency: 'BRL', direction: 'receivable', status: 'open', category: 'servico', due_on: '2026-10-01', source_type: 'manual', source_id: 0,
  source_operation_key: 'private', return_of_id: null, return_credit_ids: [12], posted_by_user_id: 42, actor: { id: 42, name: 'Operador' }, posted_at: null
}
const payment: ErpPayment = {
  id: 17, erp_establishment_id: 3, erp_financial_account_id: null, erp_cash_session_id: null, amount: '100.0000', allocated_amount: '0.0000', available_amount: '100.0000',
  currency: 'BRL', direction: 'in', status: 'posted', effective_status: 'posted', method: 'bank_transfer', reason: null, reversal_of_id: null, reversal_id: null,
  allocation_ids: [], cash_movement_ids: [], needs_reconciliation: false, correction: { permission: 'erp.payments.reverse', workflow: 'payment_reversal', requires_cash_target: false },
  posted_by_user_id: 42, actor: { id: 42, name: 'Operador' }, posted_at: null
}

describe('finance forms use actual form submissions and exact decimal strings', () => {
  it('submits a financial account body with only supported fields', (t) => {
    const form = setupForm('ErpFinancialAccountForm')
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    state.name = 'Conta operacional'
    state.currency = 'BRL'
    state.erp_establishment_id = '3'
    ;(form.exposed.submit as () => void)()
    assert.deepEqual(form.emitted[0], { name: 'Conta operacional', account_type: 'saving_current', currency: 'BRL', erp_establishment_id: 3 })
  })

  it('submits an obligation while preserving amount text and nullable references', (t) => {
    const form = setupForm('ErpObligationForm')
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { direction: 'payable', category: 'fornecedor', amount: '9007199254740993.1200', currency: 'BRL', due_on: '2026-11-01', erp_establishment_id: 'all', erp_financial_account_id: 'none' })
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted[0]?.amount, '9007199254740993.1200')
    assert.deepEqual(form.emitted[0], { direction: 'payable', category: 'fornecedor', amount: '9007199254740993.1200', currency: 'BRL', due_on: '2026-11-01', erp_establishment_id: null, erp_financial_account_id: null })
  })

  it('rejects malformed decimal text and submits cash payment only with a selected session', (t) => {
    const form = setupForm('ErpPaymentForm')
    t.after(form.stop)
    const state = form.exposed.state as Record<string, string>
    Object.assign(state, { amount: '1,50', method: 'cash' })
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted.length, 0)
    assert.match(String(Vue.unref(form.exposed.localError as Vue.Ref<string>)), /valor positivo/)
    state.amount = '123456789012345678.3400'
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted.length, 0)
    assert.match(String(Vue.unref(form.exposed.localError as Vue.Ref<string>)), /caixa aberto/)
    state.erp_cash_session_id = '8'
    ;(form.exposed.submit as () => void)()
    assert.equal(form.emitted[0]?.amount, '123456789012345678.3400')
    assert.equal(state.amount, '123456789012345678.3400')
    assert.equal(form.emitted[0]?.erp_cash_session_id, 8)
  })
})

describe('finance workspace', { concurrency: 1 }, () => {
  it('mounts the real workspace and child form, emits its submission, and retains decimal input after 422', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: Array<{ path: string, options?: { method?: string, body?: Record<string, unknown> } }> = []
    const app = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (options?.method === 'POST') throw { statusCode: 422, data: { message: 'Confira o valor.', errors: { amount: ['Valor incompatível.'] } } }
      if (path.startsWith('/erp/establishments')) return page([{ id: 3, name: 'Matriz' }])
      return page([])
    })
    t.after(app.stop)
    const mounted = mountFinanceWorkspace(app.state as Record<string, unknown>)
    t.after(mounted.unmount)
    app.mount()
    const form = mounted.exposed('ErpObligationForm')
    const input = form.state as Record<string, string>
    Object.assign(input, { category: 'servico', amount: '9007199254740993.1200', currency: 'BRL', due_on: '2026-11-01' })
    ;(form.submit as () => void)()
    await settle()
    const write = calls.find(call => call.options?.method === 'POST')
    assert.equal(write?.path, '/erp/obligations')
    assert.equal(write?.options?.body?.amount, '9007199254740993.1200')
    assert.equal(input.amount, '9007199254740993.1200')
    const state = app.state as { fieldErrors: Vue.Ref<Record<string, string>>, mutationError: Vue.Ref<string> }
    assert.equal(state.fieldErrors.value.amount, 'Valor incompatível.')
    assert.match(state.mutationError.value, /Valor incompatível/)
  })

  it('keeps an allocation dialog and amount after 422, then closes only after corrected submission succeeds', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const posts: Record<string, unknown>[] = []
    const app = createWorkspace(async (path, options) => {
      if (path.includes('/allocation-candidates?')) return page([obligation])
      if (path.startsWith('/erp/payments?')) return page([payment])
      if (options?.method === 'POST') {
        posts.push(options.body ?? {})
        if (posts.length === 1) throw { statusCode: 422, data: { message: 'Confira a alocação.', errors: { erp_obligation_id: ['Obrigação incompatível.'] } } }
        return { data: { id: 91 } }
      }
      return page([])
    })
    t.after(app.stop)
    const mounted = mountFinanceWorkspace(app.state as Record<string, unknown>)
    t.after(mounted.unmount)
    app.mount()
    await settle()
    mounted.button('Pagamentos').onClick?.()
    await settle()
    mounted.button('Alocar').onClick?.()
    await settle()
    mounted.control(props => Array.isArray(props.items) && (props.items as { value: string }[]).some(item => item.value === '9'))['onUpdate:modelValue']?.('9')
    mounted.control(props => props.inputmode === 'decimal', true)['onUpdate:modelValue']?.('50.0000')
    mounted.button('Confirmar alocação').onClick?.()
    const state = app.state as { fieldErrors: Vue.Ref<Record<string, string>>, mutating: Vue.Ref<boolean> }
    await settleUntil(() => !state.mutating.value)
    assert.ok(mounted.button('Confirmar alocação'))
    assert.equal(mounted.modalOpenStates[0], true)
    assert.equal(mounted.control(props => props.inputmode === 'decimal', true).modelValue, '50.0000')
    assert.equal(state.fieldErrors.value.erp_obligation_id, 'Obrigação incompatível.')
    mounted.control(props => Array.isArray(props.items) && (props.items as { value: string }[]).some(item => item.value === '9'))['onUpdate:modelValue']?.('10')
    mounted.button('Confirmar alocação').onClick?.()
    await settleUntil(() => !state.mutating.value)
    assert.equal(posts.length, 2)
    assert.deepEqual(posts.map(post => post.amount), ['50.0000', '50.0000'])
    assert.deepEqual(posts.map(post => post.erp_obligation_id), [9, 10])
    assert.equal(mounted.modalOpenStates[0], false)
  })

  it('refuses a write before POST when session recovery storage cannot persist the identity', async (t) => {
    const storage = sessionStorageFixture('write')
    t.after(storage.restore)
    const calls: string[] = []
    const app = createWorkspace(async (path, options) => {
      calls.push(`${options?.method ?? 'GET'} ${path}`)
      return page([])
    })
    t.after(app.stop)
    const state = app.state as { post: (path: string, body: Record<string, unknown>) => Promise<unknown>, mutationError: Vue.Ref<string>, selectedOperation: Vue.Ref<unknown> }
    const body = { direction: 'receivable', category: 'servico', amount: '8.5000', currency: 'BRL' }
    await state.post('/erp/obligations', body)
    assert.deepEqual(calls, [])
    assert.equal(state.selectedOperation.value, null)
    assert.match(state.mutationError.value, /guardar a identificação e os dados/)
  })

  it('prevents a different action from replacing any unresolved payment identity', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: Array<{ path: string, options?: { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> } }> = []
    const app = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (options?.method === 'POST') throw { statusCode: 503, data: { message: 'Timeout' } }
      return page([])
    })
    t.after(app.stop)
    const state = app.state as { post: (path: string, body: Record<string, unknown>, operation?: string) => Promise<unknown>, selectedOperation: Vue.Ref<{ key: string, payload: Record<string, unknown>, operation: string } | null>, mutationError: Vue.Ref<string> }
    const original = { direction: 'in', amount: '25.2500', method: 'bank_transfer', currency: 'BRL' }
    await state.post('/erp/payments', original)
    const pending = JSON.parse(storage.saved.get('erp-finance:v1:42:7')!) as { key: string, payload: Record<string, unknown>, operation: string }
    await state.post('/erp/payments/17/allocate', { erp_obligation_id: 9, amount: '5.0000' }, 'erp.payments.allocate')
    assert.equal(calls.filter(call => call.options?.method === 'POST').length, 1)
    assert.deepEqual(state.selectedOperation.value?.payload, original)
    assert.equal(state.selectedOperation.value?.operation, 'erp.payments.store')
    assert.equal(JSON.parse(storage.saved.get('erp-finance:v1:42:7')!).key, pending.key)
    assert.match(state.mutationError.value, /operação sem resultado confirmado/)
  })

  it('restores Company A pending identity after switching A to B and back in the same mount', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const posts: Array<{ path: string, options?: { method?: string, headers?: Record<string, string> } }> = []
    const app = createWorkspace(async (path, options) => {
      if (options?.method === 'POST') {
        posts.push({ path, options })
        throw { statusCode: 503, data: { message: 'Timeout' } }
      }
      return page([])
    })
    t.after(app.stop)
    const state = app.state as { post: (path: string, body: Record<string, unknown>) => Promise<unknown>, selectedOperation: Vue.Ref<{ key: string } | null>, mutationError: Vue.Ref<string> }
    await state.post('/erp/payments', { direction: 'in', amount: '25.0000', method: 'bank_transfer', currency: 'BRL' })
    const firstKey = state.selectedOperation.value!.key
    app.company.value = { ...app.company.value, id: 8 }
    assert.equal(state.selectedOperation.value, null)
    app.company.value = { ...app.company.value, id: 7 }
    assert.equal(state.selectedOperation.value?.key, firstKey)
    await state.post('/erp/obligations', { direction: 'receivable', amount: '10.0000' })
    assert.equal(posts.length, 1)
    assert.equal(JSON.parse(storage.saved.get('erp-finance:v1:42:7')!).key, firstKey)
    assert.match(state.mutationError.value, /operação sem resultado confirmado/)
  })

  it('clears busy state on authority change and ignores a late Company A result after Company B succeeds', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const late = deferred<unknown>()
    let postCount = 0
    const app = createWorkspace(async (_path, options) => {
      if (options?.method === 'POST') {
        postCount++
        if (postCount === 1) return late.promise
        return { data: { id: 88, currency: 'BRL', name: 'B result' } }
      }
      return page([])
    })
    t.after(app.stop)
    const state = app.state as { post: (path: string, body: Record<string, unknown>) => Promise<unknown>, mutating: Vue.Ref<boolean>, selected: Vue.Ref<{ id: number } | null> }
    const requestA = state.post('/erp/financial-accounts', { name: 'A', account_type: 'saving_current', currency: 'BRL' })
    await Promise.resolve()
    assert.equal(state.mutating.value, true)
    app.company.value = { ...app.company.value, id: 8 }
    assert.equal(state.mutating.value, false)
    await state.post('/erp/financial-accounts', { name: 'B', account_type: 'saving_current', currency: 'BRL' })
    assert.equal(state.selected.value?.id, 88)
    late.resolve({ data: { id: 77, currency: 'BRL', name: 'late A result' } })
    await requestA
    assert.equal(state.selected.value?.id, 88)
    assert.equal(state.mutating.value, false)
    assert.ok(storage.saved.has('erp-finance:v1:42:7'))
    assert.equal(storage.saved.has('erp-finance:v1:42:8'), false)
  })

  it('fences a company switch during CSRF refresh before any finance POST is sent', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const csrf = deferred<undefined>()
    const businessPosts: string[] = []
    const app = createWorkspace(async (path, options) => {
      if (options?.method === 'POST') businessPosts.push(path)
      return page([])
    }, () => csrf.promise)
    t.after(app.stop)
    const state = app.state as { post: (path: string, body: Record<string, unknown>) => Promise<unknown>, selectedOperation: Vue.Ref<{ key: string } | null> }
    const request = state.post('/erp/obligations', { direction: 'receivable', category: 'servico', amount: '75.0000', currency: 'BRL' })
    const originalKey = JSON.parse(storage.saved.get('erp-finance:v1:42:7')!).key
    app.company.value = { ...app.company.value, id: 8 }
    csrf.resolve(undefined)
    await request
    assert.deepEqual(businessPosts, [])
    assert.equal(state.selectedOperation.value, null)
    app.company.value = { ...app.company.value, id: 7 }
    assert.equal(state.selectedOperation.value?.key, originalKey)
    assert.equal(JSON.parse(storage.saved.get('erp-finance:v1:42:7')!).key, originalKey)
  })

  it('remounts from the persisted key, reconciles a 404, and retries the frozen payload exactly', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const pending = {
      identity: '42:7:erp.payments.store',
      operation: 'erp.payments.store',
      path: '/erp/payments',
      payload: { direction: 'in', amount: '123456789012345678.1200', method: 'bank_transfer', currency: 'BRL' },
      key: 'a1b2c3d4-e5f6-4789-a123-123456789abc'
    }
    storage.saved.set('erp-finance:v1:42:7', JSON.stringify(pending))
    const calls: Array<{ path: string, options?: { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> } }> = []
    const app = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/operations/lookup')) throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
      if (options?.method === 'POST') return { data: payment }
      return page([])
    })
    t.after(app.stop)
    app.mount()
    const state = app.state as { selectedOperation: Vue.Ref<typeof pending | null>, reconcile: () => Promise<void>, canRetryExact: Vue.Ref<boolean>, retryExact: () => Promise<unknown> }
    assert.equal(state.selectedOperation.value?.key, pending.key)
    await state.reconcile()
    assert.equal(state.canRetryExact.value, true)
    await state.retryExact()
    const writes = calls.filter(call => call.options?.method === 'POST')
    assert.equal(writes.length, 1)
    assert.deepEqual(writes[0]?.options?.body, pending.payload)
    assert.equal(writes[0]?.options?.headers?.['Idempotency-Key'], pending.key)
  })

  it('keeps the original unknown identity after an exact retry is refused with 403', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: Array<{ path: string, options?: { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> } }> = []
    let initial = true
    const app = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/operations/lookup')) throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
      if (options?.method === 'POST' && initial) {
        initial = false
        throw { statusCode: 503, data: { message: 'Timeout' } }
      }
      if (options?.method === 'POST') throw { statusCode: 403, data: { message: 'Sem acesso atual' } }
      return page([])
    })
    t.after(app.stop)
    const state = app.state as { post: (path: string, body: Record<string, unknown>) => Promise<unknown>, reconcile: () => Promise<void>, retryExact: () => Promise<unknown>, canRetryExact: Vue.Ref<boolean>, selectedOperation: Vue.Ref<{ key: string, payload: Record<string, unknown> } | null>, mutationError: Vue.Ref<string> }
    const body = { direction: 'in', amount: '50.0000', method: 'bank_transfer', currency: 'BRL' }
    await state.post('/erp/payments', body)
    const firstKey = state.selectedOperation.value!.key
    await state.reconcile()
    assert.equal(state.canRetryExact.value, true)
    await state.retryExact()
    assert.equal(state.selectedOperation.value?.key, firstKey)
    assert.deepEqual(JSON.parse(storage.saved.get('erp-finance:v1:42:7')!).payload, body)
    assert.match(state.mutationError.value, /operação original continua sem resultado confirmado/)
    await state.post('/erp/obligations', { amount: '10.0000' })
    assert.equal(calls.filter(call => call.options?.method === 'POST').length, 2)
    assert.equal(calls.filter(call => call.options?.method === 'POST')[1]?.options?.headers?.['Idempotency-Key'], firstKey)
  })

  it('keeps server pagination, grouped totals and prior rows when refresh fails', async (t) => {
    let fail = false
    const totals: ErpFinanceTotals[] = [{ currency: 'BRL', amount: '120.0000', open_amount: '120.0000', available_amount: '0.0000', receivable_open: '120.0000', payable_open: '40.0000', net_exposure: '80.0000', overdue_open: '20.0000' }]
    const app = createWorkspace(async (path) => {
      if (path.startsWith('/erp/establishments')) return page([{ id: 3, name: 'Matriz' }])
      if (fail) throw { statusCode: 503, data: { message: 'Financeiro indisponível' } }
      if (path.startsWith('/erp/obligations')) return page([obligation], 31, totals)
      return page([], 0)
    })
    t.after(app.stop)
    const state = app.state as { load: () => Promise<void>, rows: Vue.Ref<ErpObligation[]>, meta: Vue.Ref<ErpFinancePage<ErpObligation>['meta'] | null>, pageError: Vue.Ref<string>, filters: Record<string, unknown> }
    await state.load()
    assert.equal(state.rows.value[0]?.return_credit_ids[0], 12)
    assert.equal(state.meta.value?.total, 31)
    assert.equal(state.meta.value?.totals[0]?.net_exposure, '80.0000')
    assert.equal(state.meta.value?.totals[0]?.overdue_open, '20.0000')
    state.filters.page = 2
    fail = true
    await state.load()
    assert.equal(state.rows.value[0]?.id, obligation.id)
    assert.match(state.pageError.value, /indisponível/)
  })

  it('reconciles an uncertain write and retries only the frozen body with the same key', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: Array<{ path: string, options?: { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> } }> = []
    let lose = true
    const app = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (options?.method === 'POST' && lose) throw { statusCode: 503, data: { message: 'Tempo esgotado' } }
      if (path.startsWith('/erp/operations/lookup')) throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
      if (options?.method === 'POST') return { data: { ...obligation, id: 40 } }
      if (path.startsWith('/erp/establishments')) return page([{ id: 3, name: 'Matriz' }])
      return page([])
    })
    t.after(app.stop)
    const state = app.state as { post: (path: string, body: Record<string, unknown>) => Promise<unknown>, reconcile: () => Promise<void>, retryExact: () => Promise<unknown>, canRetryExact: Vue.Ref<boolean>, selectedOperation: Vue.Ref<{ key: string, payload: Record<string, unknown> } | null> }
    const body = { direction: 'receivable', category: 'servico', amount: '9.9900', currency: 'BRL' }
    await state.post('/erp/obligations', body)
    const frozen = JSON.parse(JSON.stringify(state.selectedOperation.value!)) as { key: string, payload: Record<string, unknown> }
    await state.reconcile()
    assert.equal(state.canRetryExact.value, true)
    lose = false
    await state.retryExact()
    const writes = calls.filter(call => call.options?.method === 'POST')
    assert.equal(writes.length, 2)
    assert.deepEqual(writes[0]?.options?.body, writes[1]?.options?.body)
    assert.equal(writes[0]?.options?.headers?.['Idempotency-Key'], frozen.key)
    assert.equal(writes[1]?.options?.headers?.['Idempotency-Key'], frozen.key)
    assert.deepEqual(writes[1]?.options?.body, frozen.payload)
  })

  it('uses the private receipt endpoint and enforces action-specific read versus write access', async (t) => {
    const calls: string[] = []
    const app = createWorkspace(async (path) => {
      calls.push(path)
      if (path.startsWith('/erp/establishments')) return page([{ id: 3, name: 'Matriz' }])
      if (path.endsWith('/receipt')) return { data: { version: 1, reference: 'ERP-PAYMENT-17', company: { id: 7, name: 'Empresa' }, id: 17 } }
      return page([payment])
    })
    t.after(app.stop)
    const state = app.state as { loadReceipt: (payment: ErpPayment) => Promise<void>, receipt: Vue.Ref<{ reference: string } | null>, canWrite: Vue.Ref<boolean>, allowed: (action: string) => boolean }
    app.company.value = { ...app.company.value, role: 'viewer', permissions: ['erp.payments.index'] }
    assert.equal(state.allowed('erp.payments.receipt'), false)
    assert.equal(state.canWrite.value, false)
    app.company.value = { ...app.company.value, permissions: ['erp.payments.index', 'erp.payments.receipt'] }
    await state.loadReceipt(payment)
    assert.equal(state.receipt.value?.reference, 'ERP-PAYMENT-17')
    assert.ok(calls.includes('/erp/17/receipt') === false)
    assert.ok(calls.includes('/erp/payments/17/receipt'))
  })

  it('loads scoped allocation candidates and posts allocation and linked reversals with exact bodies', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: Array<{ path: string, options?: { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> } }> = []
    const app = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/establishments')) return page([{ id: 3, name: 'Matriz' }])
      if (path.includes('/allocation-candidates?')) return page([obligation])
      if (options?.method === 'POST') return { data: { id: 31, amount: options.body?.amount } }
      return page([])
    })
    t.after(app.stop)
    const state = app.state as { loadCandidates: (payment: ErpPayment) => Promise<void>, candidates: Vue.Ref<ErpObligation[]>, post: (path: string, body: Record<string, unknown>, operation?: string) => Promise<unknown> }
    await state.loadCandidates(payment)
    assert.equal(state.candidates.value[0]?.id, obligation.id)
    await state.post('/erp/payments/17/allocate', { erp_obligation_id: 9, amount: '20.0100' }, 'erp.payments.allocate')
    await state.post('/erp/payment-allocations/31/reverse', { amount: '2.0000', reason: 'Ajuste conferido' }, 'erp.payment-allocations.reverse')
    await state.post('/erp/payments/17/reverse', { reason: 'Compensação autorizada' }, 'erp.payments.reverse')
    const posts = calls.filter(call => call.options?.method === 'POST')
    assert.equal(posts[0]?.path, '/erp/payments/17/allocate')
    assert.deepEqual(posts[0]?.options?.body, { erp_obligation_id: 9, amount: '20.0100' })
    assert.deepEqual(posts.map(call => call.path), ['/erp/payments/17/allocate', '/erp/payment-allocations/31/reverse', '/erp/payments/17/reverse'])
    assert.deepEqual(posts[1]?.options?.body, { amount: '2.0000', reason: 'Ajuste conferido' })
    assert.equal(posts[2]?.path, '/erp/payments/17/reverse')
    assert.deepEqual(posts[2]?.options?.body, { reason: 'Compensação autorizada' })
    assert.ok(calls.some(call => call.path === '/erp/payments/17/allocation-candidates?per_page=100&page=1'))
  })

  it('drops a response after authority changes and never invents empty success data', async (t) => {
    const pending = deferred<unknown>()
    let failing = true
    const app = createWorkspace(async (path) => {
      if (path.startsWith('/erp/establishments')) return page([{ id: 3, name: 'Matriz' }])
      if (failing) throw { statusCode: 503, data: { message: 'Leitura indisponível' } }
      return pending.promise
    })
    t.after(app.stop)
    const state = app.state as { load: () => Promise<void>, rows: Vue.Ref<unknown[]>, pageError: Vue.Ref<string> }
    await state.load()
    assert.equal(state.rows.value.length, 0)
    assert.match(state.pageError.value, /indisponível/)
    failing = false
    const request = state.load()
    app.company.value = { ...app.company.value, id: 8, permissions: [] }
    app.bump()
    pending.resolve(page([obligation]))
    await request
    assert.equal(state.rows.value.length, 0)
  })
})
