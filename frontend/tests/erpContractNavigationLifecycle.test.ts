import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

interface State {
  invoicePage: Vue.Ref<number>
  invoiceMeta: Vue.Ref<{ current_page: number, last_page: number, total: number } | null>
  invoices: Vue.Ref<unknown[]>
  subscription: Vue.Ref<unknown | null>
  invoiceDetail: Vue.Ref<unknown | null>
  invoiceDetailError: Vue.Ref<unknown | null>
  invoicesError: Vue.Ref<unknown | null>
  error: Vue.Ref<unknown | null>
  load(): Promise<void>
  loadInvoices(page: number): Promise<void>
  showInvoice(id: number): Promise<void>
}
interface RequestOptions { signal?: AbortSignal, query?: { page: number, per_page: number } }
const retail = { data: { status: 'active', company: { name: 'Empresa' }, plan: { name: 'Plano', modules: [] }, retail_contract: { agreement_status: 'effective', terms: { price: '9007199254740993.12', currency: 'BRL', interval_months: 1, due_in_days: 5, module_keys: [] } } } }
const pageResult = (page: number, lastPage = 5) => ({ data: [{ id: page }], meta: { current_page: page, last_page: lastPage, per_page: 25, total: lastPage * 25 } })
const settle = async () => {
  await new Promise(resolve => setImmediate(resolve))
}

function fixture(memory = new Map<string, Vue.Ref>(), handler?: (path: string, options: RequestOptions) => Promise<unknown>) {
  const company = { id: 12, status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null }
  const user = Vue.ref({ id: 5, companies: [{ ...company }], accounts: [] })
  const currentCompany = Vue.ref(company)
  const currentAccount = Vue.ref(null)
  const requests: Array<{ path: string, options: RequestOptions }> = []
  const bindings = {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted, onScopeDispose: Vue.onScopeDispose,
    definePageMeta() {}, useHead() {},
    useAuth: () => ({ user, currentCompany, currentAccount }),
    useState: (key: string, init: () => unknown) => {
      if (!memory.has(key)) memory.set(key, Vue.ref(init()))
      return memory.get(key)!
    },
    useNuxtApp: () => ({ $api: async (path: string, options: RequestOptions = {}) => {
      requests.push({ path, options })
      if (handler) return handler(path, options)
      return path === '/erp/subscription' ? retail : path === '/erp/commercial-invoices' ? pageResult(options.query!.page) : { data: { id: Number(path.split('/').at(-1)) } }
    } })
  }
  memory.set('access-user', user)
  const source = readFileSync(new URL('../app/pages/erp/contrato.vue', import.meta.url), 'utf8')
  const script = compileScript(parse(source).descriptor, { id: 'contract-lifecycle' })
  const compiled = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const module = { exports: {} as { default: { setup(props: unknown, context: unknown): State } } }
  new Function('require', 'exports', ...Object.keys(bindings), compiled)((path: string) => {
    if (path === 'vue') return Vue
    if (path.startsWith('~/')) return loadAppComposable(path.slice(2) + '.ts', bindings)
    return {}
  }, module.exports, ...Object.values(bindings))
  let state!: State
  type Node = { children: Node[], parent?: Node }
  const renderer = Vue.createRenderer<Node, Node>({
    createElement: () => ({ children: [] }), createText: () => ({ children: [] }), createComment: () => ({ children: [] }),
    insert: (node, parent) => { parent.children.push(node) }, remove: () => {}, patchProp: () => {}, setText: () => {}, setElementText: () => {}, parentNode: () => null, nextSibling: () => null
  })
  const app = renderer.createApp({ setup() {
    state = module.exports.default.setup({}, { expose() {} })
    return () => Vue.h('main')
  } })
  app.mount({ children: [] })
  return { state, user, currentCompany, requests, memory, stop: () => app.unmount() }
}

describe('company contract list lifecycle', () => {
  it('restores only the invoice page after a remount and bounds it with fresh server metadata', async () => {
    const first = fixture()
    await settle()
    await first.state.loadInvoices(3)
    await first.state.showInvoice(91)
    first.stop()
    const returned = fixture(first.memory, async (path, options) => path === '/erp/subscription' ? retail : pageResult(options.query!.page, 1))
    await settle()
    try {
      assert.deepEqual(returned.requests.filter(read => read.path === '/erp/commercial-invoices').map(read => read.options.query?.page), [3, 1])
      assert.equal(returned.state.invoicePage.value, 1)
      assert.equal(returned.state.invoiceMeta.value?.total, 25)
      assert.equal(returned.state.invoiceDetail.value, null)
      assert.deepEqual(Object.keys((first.memory.get('list-navigation-state')!.value as { lists: Record<string, { value: unknown }> }).lists['erp-contract-invoices']!.value as object), ['page'])
    } finally { returned.stop() }
  })

  it('aborts superseded list/detail reads and ignores their late cancellation errors', async () => {
    const pending: Array<{ reject(error: unknown): void, options: RequestOptions }> = []
    const view = fixture(undefined, async (path, options) => {
      if (path === '/erp/subscription') return retail
      if (path === '/erp/commercial-invoices' && options.query?.page === 1) return pageResult(1)
      return new Promise((_resolve, reject) => pending.push({ reject, options }))
    })
    await settle()
    const oldPage = view.state.loadInvoices(2)
    await settle()
    await view.state.loadInvoices(1)
    assert.equal(pending[0]!.options.signal?.aborted, true)
    pending[0]!.reject(new DOMException('Superseded', 'AbortError'))
    await oldPage
    assert.equal(view.state.invoicesError.value, null)
    const oldDetail = view.state.showInvoice(91)
    await settle()
    await view.state.loadInvoices(1)
    assert.equal(pending[1]!.options.signal?.aborted, true)
    pending[1]!.reject(new DOMException('Superseded', 'AbortError'))
    await oldDetail
    assert.equal(view.state.invoiceDetailError.value, null)
    const activeDetail = view.state.showInvoice(92)
    await settle()
    view.stop()
    assert.equal(pending[2]!.options.signal?.aborted, true)
    pending[2]!.reject(new DOMException('Disposed', 'AbortError'))
    await activeDetail
    assert.ok(view.requests.every(read => read.options.signal?.aborted))
  })

  it('cancels a pending subscription before refresh and ignores its late AbortError', async () => {
    let rejectOld!: (error: unknown) => void
    let subscriptionReads = 0
    const view = fixture(undefined, async (path, options) => {
      if (path === '/erp/subscription') {
        if (++subscriptionReads === 1) {
          return new Promise((_resolve, reject) => {
            rejectOld = reject
          })
        }
        return retail
      }
      return pageResult(options.query!.page)
    })
    await settle()
    await view.state.load()
    try {
      assert.equal(view.requests[0]!.options.signal?.aborted, true)
      rejectOld(new DOMException('Superseded', 'AbortError'))
      await settle()
      assert.equal(view.state.error.value, null)
      assert.ok(view.state.subscription.value)
      assert.equal(view.requests.filter(read => read.path === '/erp/commercial-invoices').length, 1)
    } finally { view.stop() }
  })

  it('clears protected values synchronously when effective grants change independently of selected metadata', async () => {
    const view = fixture()
    await settle()
    await view.state.loadInvoices(3)
    await view.state.showInvoice(91)
    try {
      view.user.value.companies[0]!.permissions = ['erp.read']
      assert.equal(view.state.invoicePage.value, 1)
      assert.equal(view.state.subscription.value, null)
      assert.deepEqual(view.state.invoices.value, [])
      assert.equal(view.state.invoiceDetail.value, null)
      assert.ok(view.requests.every(read => read.options.signal?.aborted))
    } finally { view.stop() }
  })
})
