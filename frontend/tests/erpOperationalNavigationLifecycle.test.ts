import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

function mountWorkspace(kind: 'Purchase' | 'Sales', hash: string, permissions = ['*']) {
  const route = Vue.reactive({ path: kind === 'Purchase' ? '/erp/compras' : '/erp/vendas', hash, query: {} })
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, status: 'active', access_kind: 'direct', permissions, role: 'owner' })
  const currentAccount = Vue.ref(null)
  const states = new Map<string, Vue.Ref>()
  const requests: Array<{ path: string, signal?: AbortSignal }> = []
  const bindings = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted: Vue.onMounted, onScopeDispose: Vue.onScopeDispose, nextTick: Vue.nextTick,
    useState: (key: string, init: () => unknown) => {
      if (!states.has(key)) states.set(key, Vue.ref(init()))
      return states.get(key)!
    },
    useAuth: () => ({ user, currentCompany, currentAccount, refreshWithStatus: async () => ({ committed: true, identity: user.value }) }),
    useAccessApi: () => ({ api: async (path: string, options: { signal?: AbortSignal } = {}) => {
      requests.push({ path, signal: options.signal })
      const page = Number(new URL(path, 'http://test').searchParams.get('page') ?? 1)
      return { data: [], meta: { current_page: page, last_page: path.includes('per_page=100') ? 1 : 5, per_page: 15, total: 70 } }
    }, refreshCsrf: async () => {} }),
    useRoute: () => route,
    useRouter: () => ({ replace: async (next: { hash?: string }) => { route.hash = next.hash ?? '' } })
  }
  const contextModule = loadAppComposable<{ useErpAdministrationContext: () => unknown }>('composables/useErpAdministrationContext.ts', bindings)
  Object.assign(bindings, { useErpAdministrationContext: contextModule.useErpAdministrationContext })
  const module = loadAppComposable<Record<string, () => Record<string, unknown>>>(`composables/useErp${kind}Workspace.ts`, bindings)
  let state!: Record<string, unknown>
  const component = compileVueComponent(`components/erp/Erp${kind}Workspace.vue`, bindings, {
    [`~/composables/useErp${kind}Workspace`]: { [`useErp${kind}Workspace`]: (...args: unknown[]) => { state = (module[`useErp${kind}Workspace`] as (...args: unknown[]) => Record<string, unknown>)(...args); return state } }
  })
  type Node = { children: Node[], parent?: Node }
  const renderer = Vue.createRenderer<Node, Node>({
    createElement: () => ({ children: [] }), createText: () => ({ children: [] }), createComment: () => ({ children: [] }),
    insert: (node, parent) => { parent.children.push(node); node.parent = parent },
    remove: node => { if (node.parent) node.parent.children = node.parent.children.filter(child => child !== node) },
    patchProp: () => {}, setText: () => {}, setElementText: () => {}, parentNode: node => node.parent ?? null, nextSibling: () => null
  })
  const app = renderer.createApp(component)
  app.config.warnHandler = () => {}
  app.component('ErpCatalogPageShell', { setup: () => () => Vue.h('main') })
  app.mount({ children: [] })
  return { route, currentCompany, requests, state, stop: () => app.unmount(), settle: async () => { await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick() } }
}

describe('operational entry read ownership', () => {
  for (const [kind, hash, path] of [
    ['Purchase', '', '/erp/purchases'], ['Purchase', '#returns', '/erp/purchase-returns'],
    ['Sales', '#orders', '/erp/orders'], ['Sales', '#sales', '/erp/sales'], ['Sales', '#returns', '/erp/sale-returns']
  ] as const) {
    it(`${kind} ${hash || 'default'} initializes only its selected list and one reference cycle`, async () => {
      const view = mountWorkspace(kind, hash)
      await view.settle()
      try {
        const lists = view.requests.filter(request => /^\/erp\/(purchases|purchase-returns|quotes|orders|sales|sale-returns)\?/.test(request.path))
        assert.deepEqual(lists.map(request => request.path.split('?')[0]), [path])
        for (const reference of ['/erp/contacts?', '/erp/establishments?', '/erp/units?'])
          assert.equal(view.requests.filter(request => request.path.startsWith(reference)).length, 1, reference)
        view.route.hash = kind === 'Purchase' ? '#purchases' : '#quotes'
        await view.settle()
        assert.equal(view.requests.filter(request => request.path.startsWith(kind === 'Purchase' ? '/erp/purchases?' : '/erp/quotes?')).length, 1)
      } finally { view.stop() }
    })
  }
  it('never reads a denied requested destination or the denied default', async () => {
    const view = mountWorkspace('Sales', '#quotes', ['erp.sales.index'])
    await view.settle()
    try {
      assert.deepEqual(view.requests, [])
    } finally { view.stop() }
  })
})
