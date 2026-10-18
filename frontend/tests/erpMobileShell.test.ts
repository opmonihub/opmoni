import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as nav from '../app/utils/erpModulesNav.ts'

function evaluate(code: string, globals: Record<string, unknown> = {}) {
  const output = ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports = {} as { default: { setup: (props: object, context: object) => { statusLabel: Vue.Ref<string>, subscription: Vue.Ref<{ company: { name: string } } | null>, error: Vue.Ref<unknown>, load: () => Promise<void> } }, render: (...args: unknown[]) => Vue.VNode }
  new Function('require', 'exports', ...Object.keys(globals), output)((name: string) => {
    if (name === 'vue') return { ...Vue, resolveComponent: (name: string) => ({ name }), renderSlot: (slots: Record<string, () => Vue.VNode[]>, name: string, props: { key?: string }) => Vue.h(Vue.Fragment, { key: props?.key }, slots[name]?.()) }
    assert.equal(name, '~/utils/erpModulesNav')
    return nav
  }, exports, ...Object.values(globals))
  return exports
}
function surface(path: string, bindings: Record<string, unknown> = {}, props = {}) {
  const { descriptor } = parse(readFileSync(new URL(`../app/${path}`, import.meta.url), 'utf8'))
  const script = compileScript(descriptor, { id: path })
  const template = compileTemplate({ source: descriptor.template!.content, filename: path, id: path, compilerOptions: { bindingMetadata: script.bindings } })
  assert.deepEqual(template.errors, [])
  const metadata: Record<string, unknown>[] = []
  const mounted: Array<() => Promise<void>> = []
  const scope = Vue.effectScope()
  const component = evaluate(script.content, {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    onMounted: (fn: () => Promise<void>) => mounted.push(fn),
    definePageMeta: (meta: Record<string, unknown>) => metadata.push(meta), useHead() {}, ...bindings
  }).default
  const state = scope.run(() => component.setup(props, { expose() {} }))
  const render = evaluate(template.code).render
  return { state, metadata, mounted, render: (slots = {}) => render({ $slots: slots }, [], props, Vue.proxyRefs(state), {}, {}), close: () => scope.stop() }
}
function nodes(node: Vue.VNode): Vue.VNode[] {
  const children = Array.isArray(node.children)
    ? node.children
    : node.children && typeof node.children === 'object'
      ? Object.values(node.children).filter(value => typeof value === 'function').flatMap(slot => slot({ collapsed: false, collapse() {} }))
      : []
  return [node, ...children.filter(Vue.isVNode).flatMap(nodes)]
}
function named(root: Vue.VNode, name: string) {
  return nodes(root).filter(node => typeof node.type === 'object' && (node.type as { name?: string }).name === name)
}
interface Host {
  children: Host[]
  parent: Host | null
  text?: string
}
function mount(render: () => Vue.VNode) {
  const host = (): Host => ({ children: [], parent: null })
  const renderer = Vue.createRenderer<Host, Host>({
    createElement: host, createText: text => ({ ...host(), text }), createComment: host,
    insert(child, parent, anchor) {
      child.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(child)
      else parent.children.splice(index, 0, child)
    },
    remove(child) {
      const parent = child.parent
      if (parent) parent.children.splice(parent.children.indexOf(child), 1)
    },
    setText(node, text) { node.text = text },
    setElementText(node, text) { node.text = text },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    patchProp() {},
    insertStaticContent() {
      const node = host()
      return [node, node]
    }
  })
  const app = renderer.createApp({ render })
  const root = host()
  app.mount(root)
  return () => app.unmount()
}
const company = () => ({ id: 1, name: 'Empresa A', status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null })
function contract(api: () => Promise<unknown>) {
  const currentCompany = Vue.ref(company())
  const currentAccount = Vue.ref<{ id: number } | null>(null)
  return { currentCompany, currentAccount, ...surface('pages/erp/contrato.vue', { useNuxtApp: () => ({ $api: api }), useAuth: () => ({ currentCompany, currentAccount }) }) }
}
const payload = (status = 'active') => ({ data: { status, company: { name: 'Empresa A' }, plan: { name: 'Plano A', modules: [] } } })

describe('ERP mobile shell and contract', () => {
  it('keeps native header slots visible and exposes company/user controls with sidebar closed', (t) => {
    const currentCompany = Vue.ref(company())
    const view = surface('layouts/erp.vue', { useRoute: () => ({ path: '/erp/cadastros/contatos' }), useAuth: () => ({ currentCompany, currentAccount: Vue.ref(null) }) })
    t.after(view.close)
    const slots = named(view.render(), 'UDashboardPanel')[0]!.children as Record<string, () => Vue.VNode[]>
    assert.equal(slots.default, undefined, 'Nuxt UI default slot replaces the header and body')
    assert.ok(slots.body, 'Page content belongs in the scrollable body')
    const header = slots.header!()[0]!
    assert.equal(header.props?.title, 'Empresa A')
    assert.ok(named(header, 'CompanyMenu').length, 'Company switch must be available with sidebar closed')
    assert.ok(named(header, 'UserMenu').length, 'User controls must be available with sidebar closed')
    assert.equal(named(header, 'UDashboardSidebarToggle').length, 0, 'Navbar supplies its native mobile toggle')
    assert.equal(header.props?.toggle?.['aria-label'], 'Abrir menu ERP')
    currentCompany.value = { ...company(), name: 'Empresa B' }
    assert.equal(named(view.render(), 'UDashboardNavbar')[0]!.props!.title, 'Empresa B')
    const links = named(view.render(), 'UNavigationMenu')[0]!.props!.items
    assert.ok(links.some((link: { to: string }) => link.to === '/erp/relatorios'))
  })
  it('remounts catalog content on workspace changes without changing the destination', (t) => {
    const route = Vue.reactive({ path: '/erp/cadastros/contatos' })
    const currentCompany = Vue.ref(company())
    const currentAccount = Vue.ref<{ id: number } | null>(null)
    const view = surface('layouts/erp.vue', { useRoute: () => route, useAuth: () => ({ currentCompany, currentAccount }) })
    t.after(view.close)
    const bodyKey = () => (named(view.render(), 'UDashboardPanel')[0]!.children as Record<string, () => Vue.VNode[]>).body!()[0]!.key
    const initial = bodyKey()
    currentCompany.value = { ...company(), id: 2 }
    assert.notEqual(bodyKey(), initial)
    const next = bodyKey()
    currentAccount.value = { id: 9 }
    assert.notEqual(bodyKey(), next)
    assert.equal(route.path, '/erp/cadastros/contatos')
  })
  it('disposes the old protected child and mounts a fresh child on company switch', async (t) => {
    const currentCompany = Vue.ref(company())
    const view = surface('layouts/erp.vue', { useRoute: () => ({ path: '/erp/cadastros/contatos' }), useAuth: () => ({ currentCompany, currentAccount: Vue.ref(null) }) })
    t.after(view.close)
    const mounted: number[] = []
    const disposed: number[] = []
    const child = Vue.defineComponent({
      setup() {
        const id = currentCompany.value.id
        mounted.push(id)
        Vue.onUnmounted(() => disposed.push(id))
        return () => Vue.h('p', `Company ${id}`)
      }
    })
    const unmount = mount(() => {
      const panel = named(view.render({ default: () => [Vue.h(child)] }), 'UDashboardPanel')[0]!
      return Vue.h('main', null, (panel.children as Record<string, () => Vue.VNode[]>).body!())
    })
    t.after(unmount)
    assert.deepEqual(mounted, [1])
    currentCompany.value = { ...company(), id: 2 }
    await Vue.nextTick()
    assert.deepEqual(disposed, [1])
    assert.deepEqual(mounted, [1, 2])
  })
  it('lets operational routes retain their own native panel without nesting', (t) => {
    const route = Vue.reactive({ path: '/erp/financeiro' })
    const view = surface('layouts/erp.vue', { useRoute: () => route, useAuth: () => ({ currentCompany: Vue.ref(company()), currentAccount: Vue.ref(null) }) })
    t.after(view.close)
    for (const path of ['/erp/financeiro', '/erp/caixa', '/erp/compras', '/erp/estoque', '/erp/vendas', '/erp/fiscal', '/erp/contabilidade', '/erp/relatorios', '/erp/configuracoes']) {
      route.path = path
      assert.equal(named(view.render(), 'UDashboardPanel').length, 0, path)
    }
  })
  it('uses regular catalog content inside the single layout panel', (t) => {
    const view = surface('components/erp/ErpCatalogPageShell.vue', {}, { title: 'Contatos' })
    t.after(view.close)
    assert.equal(view.render().type, 'div', 'Catalog content must not create an unresolved dashboard component')
  })
  it('renders overview content inside the ERP layout panel', (t) => {
    const view = surface('pages/erp/index.vue', { useAuth: () => ({ currentCompany: Vue.ref(company()) }) })
    t.after(view.close)
    assert.equal(view.render().type, 'div')
  })
  it('uses ERP layout for a company-only contract route', (t) => {
    const view = contract(async () => payload())
    t.after(view.close)
    assert.equal(view.metadata[0]?.layout, 'erp')
    const menu = surface('components/WorkspaceContextMenu.vue', { useAuth: () => ({ user: Vue.ref({ accounts: [], companies: [company()] }) }) })
    t.after(menu.close)
    assert.equal(named(menu.render(), 'AccountMenu').length, 0)
    assert.equal(named(menu.render(), 'CompanyMenu').length, 1)
  })
  for (const [status, expected] of [['pending', 'Pendente de habilitação'], ['active', 'Ativo'], ['past_due', 'Em atraso'], ['canceled', 'Cancelado']]) {
    it(`preserves ${status} contract feedback`, async (t) => {
      const view = contract(async () => payload(status))
      t.after(view.close)
      await view.mounted[0]!()
      assert.equal(view.state.statusLabel.value, expected)
    })
  }
  it('offers refresh/retry and retains confirmed contract on refresh failure', async (t) => {
    let fail = false
    let count = 0
    const view = contract(async () => {
      count++
      if (fail) throw new Error('offline')
      return payload()
    })
    t.after(view.close)
    await view.mounted[0]!()
    const refresh = named(view.render(), 'UButton').find(node => nodes(node).some(child => typeof child.children === 'string' && child.children.includes('Atualizar')))
    assert.ok(refresh)
    fail = true
    await refresh.props!.onClick()
    assert.ok(named(view.render(), 'UAlert').length)
    assert.ok(named(view.render(), 'UCard').length, 'Confirmed contract stays visible after refresh failure')
    fail = false
    const retry = named(view.render(), 'UButton').find(node => nodes(node).some(child => typeof child.children === 'string' && child.children.includes('Tentar novamente')))
    assert.ok(retry)
    await retry.props!.onClick()
    assert.equal(view.state.error.value, null)
    assert.equal(count, 3)
  })
  it('clears contract state and rejects late replies on company/Account switch', async (t) => {
    const requests: Array<(value: unknown) => void> = []
    const view = contract(() => new Promise((resolve) => {
      requests.push(resolve)
    }))
    t.after(view.close)
    const pending = view.mounted[0]!()
    view.currentCompany.value = { ...company(), id: 2, name: 'Empresa B' }
    await Vue.nextTick()
    requests[0]!(payload())
    await pending
    assert.equal(view.state.subscription.value, null)
    view.state.subscription.value = { company: { name: 'Empresa B' } }
    view.currentAccount.value = { id: 9 }
    await Vue.nextTick()
    assert.equal(view.state.subscription.value, null)
  })
})
