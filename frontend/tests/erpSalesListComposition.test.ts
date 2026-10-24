import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type HostNode = { tag?: string, text?: string, children: HostNode[], parent: HostNode | null, props: Record<string, unknown> }
type Page<T> = { data: T[], meta: { current_page: number, last_page: number, per_page: number, total: number } }

function emptyPage<T>(data: T[] = []): Page<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length } }
}

function installSessionStorage() {
  const values = new Map<string, string>()
  const previous = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => values.set(key, value),
    removeItem: (key: string) => values.delete(key)
  } })
  return () => previous ? Object.defineProperty(globalThis, 'sessionStorage', previous) : Reflect.deleteProperty(globalThis, 'sessionStorage')
}

function createSalesState(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, permissions: string[]) {
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner' })
  const sessionGeneration = Vue.ref(0)
  const key = Vue.computed(() => `${user.value.id}:${currentCompany.value.id}:${currentCompany.value.role}:${currentCompany.value.permissions.join(',')}`)
  const context = { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
  const scope = Vue.effectScope()
  const composable = loadAppComposable<{ useErpSalesWorkspace: (area?: string) => Record<string, unknown> }>('composables/useErpSalesWorkspace.ts', {
    ref: Vue.ref,
    reactive: Vue.reactive,
    computed: Vue.computed,
    watch: Vue.watch,
    onMounted: () => {},
    onScopeDispose: Vue.onScopeDispose,
    useState: (name: string, initialize: () => unknown) => name === 'access-session-generation' ? sessionGeneration : Vue.ref(initialize()),
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus: async () => ({ committed: true, identity: { id: user.value.id } }) }),
    useErpAdministrationContext: () => context
  })
  const state = scope.run(() => composable.useErpSalesWorkspace('quotes'))!
  return { state, currentCompany, context, stop: () => scope.stop() }
}

function mountSalesList(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, options: { hash?: string, query?: Record<string, string>, permissions?: string[] } = {}) {
  const restoreStorage = installSessionStorage()
  const sales = createSalesState(api, options.permissions ?? ['erp.quotes.index', 'erp.quotes.show', 'erp.orders.index', 'erp.orders.show'])
  const route = Vue.reactive({ path: '/erp/vendas', hash: options.hash ?? '#quotes', query: options.query ?? {} })
  const router = {
    replace: async (location: { hash?: string }) => {
      if (location.hash) route.hash = location.hash
    }
  }
  const anchors: Array<{ label: string, to: { path?: string, query?: Record<string, string>, hash?: string } }> = []
  let table: { data: unknown[], columns: Array<{ id?: string, accessorKey?: string, header?: unknown }> } | null = null
  const component = compileVueComponent('components/erp/ErpSalesWorkspace.vue', {
    onMounted: Vue.onMounted,
    useRoute: () => route,
    useRouter: () => router,
    useErpAdministrationContext: () => sales.context
  }, { '~/composables/useErpSalesWorkspace': { useErpSalesWorkspace: () => sales.state } })
  const footer = compileVueComponent('components/erp/ErpListFooter.vue')
  const renderer = Vue.createRenderer<HostNode>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor) {
      if (node.parent) {
        const oldIndex = node.parent.children.indexOf(node)
        if (oldIndex >= 0) node.parent.children.splice(oldIndex, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    createElement: tag => ({ tag, children: [], parent: null, props: {} }),
    createText: text => ({ text, children: [], parent: null, props: {} }),
    createComment: text => ({ text, children: [], parent: null, props: {} }),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const app = renderer.createApp(component)
  const passthrough = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('div', context.slots.default?.() ?? []) })
  app.component('ErpCatalogPageShell', Vue.defineComponent({ setup: (_props, context) => () => Vue.h('main', [
    ...(context.slots.actions?.() ?? []), ...(context.slots.toolbar?.() ?? []), ...(context.slots.default?.() ?? [])
  ]) }))
  app.component('ErpListFooter', footer)
  app.component('UNavigationMenu', Vue.defineComponent({
    props: ['items'],
    setup: (props, context) => () => {
      anchors.splice(0)
      const items = props.items as Array<Array<{ label: string, to: { path?: string, query?: Record<string, string>, hash?: string }, active?: boolean }>> | undefined
      return Vue.h('nav', (items ?? []).flat().map((item) => {
        anchors.push({ label: item.label, to: item.to })
        return Vue.h('a', {
          'href': item.to.hash,
          'aria-current': item.active ? 'page' : undefined,
          'onClick': () => {
            route.hash = item.to.hash ?? ''
            context.emit('select', item)
          }
        }, item.label)
      }))
    }
  }))
  app.component('UTable', Vue.defineComponent({
    props: ['data', 'columns'],
    setup: (props, context) => () => {
      table = { data: props.data as unknown[], columns: props.columns as Array<{ id?: string, accessorKey?: string, header?: unknown }> }
      return Vue.h('table', { 'data-sales-table': true }, [
        Vue.h('thead', Vue.h('tr', table.columns.map(column => Vue.h('th', String(column.header ?? column.id ?? column.accessorKey ?? ''))))),
        Vue.h('tbody', table.data.map(row => Vue.h('tr', [Vue.h('td', String((row as Record<string, unknown>).id ?? ''))]))),
        ...(context.slots.default?.() ?? [])
      ])
    }
  }))
  app.component('UPagination', Vue.defineComponent({
    props: ['page', 'total', 'itemsPerPage', 'disabled'],
    emits: ['update:page'],
    setup: (props, context) => () => Vue.h('nav', { 'aria-label': context.attrs['aria-label'] }, [
      Vue.h('span', { 'data-page': props.page, 'data-total': props.total, 'data-per-page': props.itemsPerPage }),
      Vue.h('button', { 'aria-label': 'Próxima página', 'disabled': props.disabled || Number(props.page) * Number(props.itemsPerPage) >= Number(props.total), 'onClick': () => context.emit('update:page', Number(props.page) + 1) }, 'Próxima')
    ])
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['label', 'disabled'],
    setup: (props, context) => () => Vue.h('button', { ...context.attrs, disabled: props.disabled }, props.label ?? context.slots.default?.() ?? [])
  }))
  app.component('USelect', Vue.defineComponent({ props: ['modelValue', 'items'], setup: (props, context) => () => Vue.h('select', { value: props.modelValue, onChange: (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value) }, (props.items as Array<{ label: string, value: string }> ?? []).map(item => Vue.h('option', { value: item.value }, item.label))) }))
  app.component('UModal', Vue.defineComponent({ props: ['open'], setup: (props, context) => () => props.open ? Vue.h('section', context.slots.default?.() ?? []) : null }))
  app.component('UAlert', passthrough)
  app.component('UFormField', passthrough)
  for (const name of ['UBadge', 'UIcon', 'UCheckbox', 'UDropdownMenu', 'UInput', 'UserMenu', 'CompanyMenu', 'ErpCommercialDraftForm', 'ErpCommercialTaxReview', 'ErpSaleDetail', 'ErpSaleReturnForm']) app.component(name, passthrough)
  const root: HostNode = { tag: 'root', children: [], parent: null, props: {} }
  app.mount(root)
  function walk(node: HostNode): HostNode[] {
    return [node, ...node.children.flatMap(walk)]
  }
  function text(node: HostNode): string {
    return `${node.text ?? ''}${node.children.map(text).join('')}`
  }
  async function flush() {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
  }
  return {
    root, route, anchors, get table() { return table }, state: sales.state, walk: () => walk(root), text: (node: HostNode = root) => text(node), flush,
    clickPage: async () => {
      const button = walk(root).find(node => node.tag === 'button' && node.props['aria-label'] === 'Próxima página')
      assert.ok(button)
      if (button.props.disabled !== true) (button.props.onClick as () => void)()
      await flush()
    },
    clickArea: async (label: string) => {
      const anchor = walk(root).find(node => node.tag === 'a' && text(node) === label)
      assert.ok(anchor)
      ;(anchor.props.onClick as () => void)()
      await flush()
    },
    dispose() {
      app.unmount()
      sales.stop()
      restoreStorage()
    }
  }
}

describe('Sales list composition', () => {
  it('uses permitted hash links and preserves the current route query when switching areas', async (t) => {
    const calls: string[] = []
    const api = async (path: string) => {
      calls.push(path)
      return emptyPage()
    }
    const mounted = mountSalesList(api, { query: { origem: 'painel' }, permissions: ['erp.quotes.index', 'erp.quotes.show', 'erp.orders.index', 'erp.orders.show'] })
    t.after(mounted.dispose)
    await mounted.flush()

    assert.deepEqual(mounted.anchors.map(link => link.label), ['Orçamentos', 'Pedidos'])
    assert.ok(mounted.anchors.every(link => link.to.path === '/erp/vendas' && link.to.query?.origem === 'painel'))
    assert.equal(mounted.anchors.some(link => link.label === 'Vendas / balcão' || link.label === 'Devoluções'), false)
    assert.ok(calls.some(path => path.startsWith('/erp/quotes?')))
    assert.equal(calls.some(path => path.startsWith('/erp/sales?') || path.startsWith('/erp/sale-returns?')), false)

    await mounted.clickArea('Pedidos')
    assert.equal(mounted.route.hash, '#orders')
    assert.ok(calls.some(path => path.startsWith('/erp/orders?page=1&per_page=15')))
  })

  it('uses server totals and page transitions while retaining the mobile record detail action', async (t) => {
    const calls: string[] = []
    let resolveSecondPage: ((value: unknown) => void) | undefined
    const first = { id: 101, quote_no: 'ORC-101', erp_establishment_id: 3, status: 'draft', currency: 'BRL', total_amount: '10.0000' }
    const second = { id: 102, quote_no: 'ORC-102', erp_establishment_id: 3, status: 'draft', currency: 'BRL', total_amount: '20.0000' }
    const detail = { ...second, draft_revision: 1, subtotal_amount: '20.0000', discount_amount: '0.0000', tax_amount: '0.0000', stock_reserved: false, needs_reconciliation: false, lines: [] }
    const api = async (path: string) => {
      calls.push(path)
      if (path === '/erp/quotes?page=1&per_page=15') return { data: [first], meta: { current_page: 1, last_page: 3, per_page: 15, total: 42 } }
      if (path === '/erp/quotes?page=2&per_page=15') {
        return await new Promise((resolve) => {
          resolveSecondPage = resolve
        })
      }
      if (path === '/erp/quotes/102') return { data: detail }
      if (path.startsWith('/erp/establishments?') || path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/tax-settings/operation-natures?')) return emptyPage()
      return emptyPage()
    }
    const mounted = mountSalesList(api)
    t.after(mounted.dispose)
    await mounted.flush()

    assert.ok(mounted.walk().some(node => node.tag === 'nav' && node.props['aria-label'] === 'Paginação de registros'))
    assert.equal(mounted.walk().find(node => node.props['data-total'] === 42)?.props['data-page'], 1)
    assert.equal(mounted.table?.data.length, 1)
    assert.ok(mounted.text().includes('42 registros'), 'footer count comes from server meta, not the one visible row')

    const next = mounted.walk().find(node => node.tag === 'button' && node.props['aria-label'] === 'Próxima página')!
    ;(next.props.onClick as () => void)()
    await Vue.nextTick()
    assert.equal(mounted.walk().find(node => node.props['data-total'] === 42)?.props['data-page'], 2)
    assert.equal(calls.filter(path => path === '/erp/quotes?page=2&per_page=15').length, 1)
    const pendingNext = mounted.walk().find(node => node.tag === 'button' && node.props['aria-label'] === 'Próxima página')!
    assert.equal(pendingNext.props.disabled, true)
    ;(pendingNext.props.onClick as () => void)()
    assert.equal(calls.filter(path => path === '/erp/quotes?page=2&per_page=15').length, 1, 'pending pagination cannot issue a duplicate page request')

    resolveSecondPage?.({ data: [second], meta: { current_page: 2, last_page: 3, per_page: 15, total: 42 } })
    await mounted.flush()
    assert.deepEqual(mounted.table?.data[0], second)
    assert.equal(mounted.walk().find(node => node.props['data-total'] === 42)?.props['data-page'], 2)
    const mobileRecord = mounted.walk().find(node => node.tag === 'button' && mounted.text().includes('ORC-102') && mounted.text(node).includes('ORC-102'))
    assert.ok(mobileRecord, 'the compact mobile record remains an actionable button')
    ;(mobileRecord.props.onClick as () => void)(new Event('click'))
    await mounted.flush()
    assert.ok(calls.includes('/erp/quotes/102'), 'record activation uses the existing authorized detail read')
    assert.equal((mounted.state.selected as Vue.Ref<{ id: number } | null>).value?.id, 102)
  })
})
