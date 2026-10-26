import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

function mountWorkspace(kind: 'Purchase' | 'Sales' | 'Finance' | 'Stock' | 'Cash', hash: string, permissions = ['*'], memory = new Map<string, Vue.Ref>(), deferLists = false) {
  const paths = { Purchase: '/erp/compras', Sales: '/erp/vendas', Finance: '/erp/financeiro', Stock: '/erp/estoque', Cash: '/erp/caixa' }
  const route = Vue.reactive({ path: paths[kind], hash, query: {} })
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, status: 'active', access_kind: 'direct', permissions, role: 'owner' })
  const currentAccount = Vue.ref(null)
  const states = memory
  const requests: Array<{ path: string, signal?: AbortSignal, reject?: (error: unknown) => void }> = []
  const bindings = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted: Vue.onMounted, onScopeDispose: Vue.onScopeDispose, nextTick: Vue.nextTick,
    useState: (key: string, init: () => unknown) => {
      if (!states.has(key)) states.set(key, Vue.ref(init()))
      return states.get(key)!
    },
    useAuth: () => ({ user, currentCompany, currentAccount, refreshWithStatus: async () => ({ committed: true, identity: user.value }) }),
    useAccessApi: () => ({ api: async (path: string, options: { signal?: AbortSignal } = {}) => {
      const request = { path, signal: options.signal } as (typeof requests)[number]
      requests.push(request)
      if (deferLists && /^\/erp\/(purchases|purchase-returns|quotes|orders|sales|sale-returns)\?/.test(path))
        return new Promise((_resolve, reject) => { request.reject = reject })
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
    [`~/composables/useErp${kind}Workspace`]: { [`useErp${kind}Workspace`]: (...args: unknown[]) => {
      state = (module[`useErp${kind}Workspace`] as (...args: unknown[]) => Record<string, unknown>)(...args)
      return state
    } }
  })
  type Node = { children: Node[], parent?: Node }
  const renderer = Vue.createRenderer<Node, Node>({
    createElement: () => ({ children: [] }), createText: () => ({ children: [] }), createComment: () => ({ children: [] }),
    insert: (node, parent) => {
      parent.children.push(node)
      node.parent = parent
    },
    remove: (node) => { if (node.parent) node.parent.children = node.parent.children.filter(child => child !== node) },
    patchProp: () => {}, setText: () => {}, setElementText: () => {}, parentNode: node => node.parent ?? null, nextSibling: () => null
  })
  const app = renderer.createApp(component)
  app.config.warnHandler = () => {}
  app.component('ErpCatalogPageShell', { setup: () => () => Vue.h('main') })
  app.mount({ children: [] })
  return { route, currentCompany, requests, state, memory, stop: () => app.unmount(), settle: async () => {
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
  } }
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

describe('operational criteria lifecycle', () => {
  it('restores purchase criteria on remount and clears them synchronously on Company change', async () => {
    const first = mountWorkspace('Purchase', '#purchases')
    await first.settle()
    const filters = first.state.filters as { status: string, search: string, page: number }
    Object.assign(filters, { status: 'posted', search: 'NF 432', page: 3 })
    await (first.state.load as () => Promise<void>)()
    first.stop()
    const next = mountWorkspace('Purchase', '#purchases', ['*'], first.memory)
    await next.settle()
    try {
      assert.match(next.requests.find(read => read.path.startsWith('/erp/purchases?'))!.path, /page=3.*status=posted.*search=NF\+432/)
      next.currentCompany.value = { ...next.currentCompany.value, id: 8 }
      assert.equal((next.state.filters as { search: string }).search, '')
      await next.settle()
      assert.equal(next.requests.filter(read => read.path.startsWith('/erp/purchases?')).length, 2)
    } finally { next.stop() }
  })

  it('keeps sales criteria separate per area and normalizes filter changes to one page-one read', async () => {
    const view = mountWorkspace('Sales', '#orders')
    await view.settle()
    try {
      const filters = view.state.filters as { status: string, page: number }
      Object.assign(filters, { status: 'confirmed', page: 3 })
      await (view.state.refreshArea as () => Promise<void>)()
      view.route.hash = '#sales'
      await view.settle()
      assert.equal(filters.status, '')
      assert.equal(filters.page, 1)
      view.route.hash = '#orders'
      await view.settle()
      assert.equal(filters.status, 'confirmed')
      assert.equal(filters.page, 3)
      const before = view.requests.length
      ;(view.state.setStatus as (status: string) => void)('converted')
      await view.settle()
      assert.deepEqual(view.requests.slice(before).map(read => read.path), ['/erp/orders?page=1&per_page=15&status=converted'])
      const old = view.requests.find(read => read.path.startsWith('/erp/orders?'))!
      assert.equal(old.signal?.aborted, true)
      view.stop()
      assert.ok(view.requests.every(read => !read.signal || read.signal.aborted))
    } finally { view.stop() }
  })
})

it('has one sales reinitialization owner when authority changes the permitted default', async () => {
  const view = mountWorkspace('Sales', '')
  await view.settle()
  view.requests.length = 0
  try {
    view.currentCompany.value = { ...view.currentCompany.value, id: 8, permissions: ['erp.orders.index', 'erp.contacts.index', 'erp.establishments.index', 'erp.units.index'] }
    await view.settle()
    assert.equal(view.requests.filter(read => read.path.startsWith('/erp/orders?')).length, 1)
    for (const reference of ['/erp/contacts?', '/erp/establishments?', '/erp/units?'])
      assert.equal(view.requests.filter(read => read.path.startsWith(reference)).length, 1, reference)
  } finally { view.stop() }
})

for (const [kind, initial, destination, prefix] of [
  ['Purchase', '#purchases', '#returns', '/erp/purchases?'],
  ['Sales', '#orders', '#sales', '/erp/orders?']
] as const) {
  it(`${kind} aborts a departed area read without showing its AbortError`, async () => {
    const view = mountWorkspace(kind, initial, ['*'], new Map(), true)
    await view.settle()
    try {
      const old = view.requests.find(read => read.path.startsWith(prefix))!
      view.route.hash = destination
      await view.settle()
      assert.equal(old.signal?.aborted, true)
      old.reject!(new DOMException('Departed area', 'AbortError'))
      await view.settle()
      const error = kind === 'Purchase' ? (view.state.listError as Vue.Ref<string>).value : (view.state.listErrors as Record<string, string>).orders
      assert.equal(error, '')
      const references = view.requests.filter(read => read.path.startsWith('/erp/contacts?'))
      assert.ok(references.length >= 1)
      assert.equal(references.at(-1)!.signal?.aborted, false, 'current reference ownership remains live')
    } finally { view.stop() }
  })
}

for (const [kind, hash, path, field, value] of [
  ['Finance', '#payments', '/erp/payments?', 'currency', 'USD'],
  ['Stock', '#movements', '/erp/stock/movements?', 'direction', 'in'],
  ['Cash', '#sessions', '/erp/cash-sessions?', 'status', 'open']
] as const) {
  it(`${kind} retains its selected destination criteria on a fresh component entry`, async () => {
    const first = mountWorkspace(kind, hash)
    await first.settle()
    const filters = first.state.filters as Record<string, string | number>
    filters[field] = value
    filters.page = 3
    if (kind === 'Cash') await (first.state.loadList as (kind: string, page: number) => Promise<void>)('sessions', 3)
    else if (kind === 'Stock') await (first.state.load as (area: string, page: number) => Promise<void>)('movements', 3)
    else await (first.state.load as () => Promise<void>)()
    first.stop()
    const returned = mountWorkspace(kind, hash, ['*'], first.memory)
    await returned.settle()
    try {
      const read = returned.requests.find(read => read.path.startsWith(path))
      assert.ok(read, `selected list ${path}`)
      const query = new URL(read.path, 'http://test').searchParams
      assert.equal(query.get(field), value)
      assert.equal(query.get('page'), '3')
    } finally { returned.stop() }
  })
}
