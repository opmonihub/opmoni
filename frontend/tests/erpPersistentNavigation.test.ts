import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import type { CompanyMembership } from '../shared/types/access.ts'

type Node = { tag: string, props: Record<string, unknown>, text: string, children: Node[], parent: Node | null }
function node(tag: string, text = ''): Node {
  return { tag, text, props: {}, children: [], parent: null }
}
function walk(root: Node): Node[] {
  return [root, ...root.children.flatMap(walk)]
}
function text(root: Node): string {
  return root.text + root.children.map(text).join('')
}
const company = (): CompanyMembership => ({ id: 1, name: 'Empresa sintética', status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null })

function mountPage(path: string, props: Record<string, unknown> = {}, initialRoute = '/erp/configuracoes/empresa') {
  const currentCompany = Vue.ref<CompanyMembership | null>(company())
  const route = Vue.reactive({ path: initialRoute, fullPath: initialRoute })
  const scrolls: unknown[] = []
  const focusTarget = {
    getBoundingClientRect: () => ({ left: 712, right: 764 }),
    scrollIntoView: (options: unknown) => scrolls.push(options),
    closest() { return focusTarget }
  }
  const toolbarElement = {
    getBoundingClientRect: () => ({ left: 0, right: 390 }),
    querySelector: () => focusTarget
  }
  const actions = Vue.reactive({ allowed: true, busy: false, label: 'Adicionar produto', calls: 0 })
  const resource = Vue.defineComponent({
    setup(_props, { expose }) {
      expose({ createAction: Vue.reactive({
        get allowed() { return actions.allowed },
        get busy() { return actions.busy },
        get label() { return actions.label },
        trigger: () => { if (actions.allowed && !actions.busy) actions.calls++ }
      }) })
      return () => Vue.h('div', { 'data-resource': true })
    }
  })
  const bindings = {
    definePageMeta() {}, useHead() {}, useRoute: () => route, useAuth: () => ({ currentCompany }),
    onMounted: Vue.onMounted, nextTick: Vue.nextTick, onBeforeUnmount: Vue.onBeforeUnmount
  }
  const moduleMocks = {
    '~/components/erp/catalogs/ErpCatalogResource.vue': resource,
    '~/components/erp/catalogs/ErpProductCatalogForm.vue': Vue.defineComponent({ render: () => null })
  }
  const compiled = compileVueComponent(path, bindings, moduleMocks)
  const product = compileVueComponent('pages/erp/cadastros/produtos.vue', bindings, moduleMocks)
  const renderer = Vue.createRenderer<Node, Node>({
    createElement: tag => node(tag), createText: value => node('#text', value), createComment: value => node('#comment', value),
    insert(child, parent, anchor) {
      if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1)
      child.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(child)
      else parent.children.splice(index, 0, child)
    },
    remove(child) { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1) },
    setText(child, value) { child.text = value }, setElementText(child, value) {
      child.text = value
      child.children = []
    },
    parentNode: child => child.parent, nextSibling: child => child.parent?.children[child.parent.children.indexOf(child) + 1] ?? null,
    patchProp(child, key, _old, value) { child.props[key] = value }
  })
  const app = renderer.createApp(compiled, props)
  app.component('UDashboardPanel', { setup: (_props, { slots }) => () => Vue.h('section', { 'data-panel': true }, [slots.header?.(), slots.body?.()]) })
  app.component('UDashboardNavbar', { props: ['title'], setup: (props, { slots }) => () => Vue.h('header', { 'data-navbar': true }, [props.title, slots.leading?.(), slots.right?.()]) })
  app.component('UDashboardToolbar', { setup(_props, { slots, expose }) {
    expose({ $el: toolbarElement })
    return () => Vue.h('div', { 'data-toolbar': true }, slots.default?.())
  } })
  app.component('UNavigationMenu', { props: ['items'], setup: props => () => Vue.h('nav', (props.items as Array<Array<{ to?: string, label: string, active?: boolean }>>).flat().map(item => Vue.h(item.to ? 'a' : 'button', { 'href': item.to, 'data-active': item.active }, item.label))) })
  app.component('UButton', { props: ['disabled'], setup: (props, { attrs, slots }) => () => Vue.h('button', { ...attrs, disabled: props.disabled }, slots.default?.()) })
  app.component('NuxtPage', { setup: () => () => route.path === '/erp/cadastros/produtos'
    ? Vue.h(product, { key: route.path })
    : Vue.h('main', { 'key': route.path, 'data-child': route.path }, route.path) })
  for (const name of ['UDashboardSidebarCollapse', 'UDashboardSidebarToggle', 'CompanyMenu', 'UserMenu']) app.component(name, { render: () => null })
  app.component('ErpWorkspaceDestinationToolbar', compileVueComponent('components/erp/ErpWorkspaceDestinationToolbar.vue', bindings))
  app.component('ErpCatalogPageShell', compileVueComponent('components/erp/ErpCatalogPageShell.vue', bindings))
  const errors: unknown[] = []
  app.config.errorHandler = error => errors.push(error)
  const root = node('root')
  app.mount(root)
  return { root, route, currentCompany, actions, errors, scrolls, focusTarget, stop: () => app.unmount() }
}

describe('persistent ERP catalog/settings navigation', () => {
  it('renders settings destinations as direct links and follows route/history/grants in one persistent panel', async (t) => {
    const page = mountPage('pages/erp/configuracoes.vue')
    t.after(page.stop)
    const panel = walk(page.root).find(node => node.props['data-panel'])!
    const links = () => walk(page.root).filter(node => node.tag === 'a')
    assert.ok(links().some(node => node.props.href === '/erp/configuracoes/empresa'))
    assert.equal(walk(page.root).filter(node => node.tag === 'button').length, 0, 'toolbar has no category disclosure')
    page.route.path = '/erp/configuracoes/sms'
    page.route.fullPath = page.route.path
    await Vue.nextTick()
    assert.equal(walk(page.root).find(node => node.props['data-panel']), panel)
    assert.ok(links().some(node => node.props.href === page.route.path && node.props['data-active']))
    page.route.path = '/erp/configuracoes/empresa'
    await Vue.nextTick()
    assert.ok(links().some(node => node.props.href === page.route.path && node.props['data-active']))
    page.currentCompany.value = { ...company(), role: 'viewer', permissions: ['erp.establishments.index'] }
    await Vue.nextTick()
    assert.deepEqual(links().map(node => node.props.href), ['/erp/configuracoes/estabelecimentos'])
    page.currentCompany.value = null
    await Vue.nextTick()
    assert.deepEqual(links(), [])
    assert.deepEqual(page.errors, [])
  })

  it('keeps catalog child actions local and permission/busy-aware without owning another panel', async (t) => {
    const page = mountPage('pages/erp/cadastros/produtos.vue')
    t.after(page.stop)
    await Vue.nextTick()
    assert.equal(walk(page.root).filter(node => node.props['data-panel']).length, 0)
    assert.equal(walk(page.root).filter(node => node.props['data-navbar']).length, 0)
    const create = () => walk(page.root).find(node => node.props['aria-label'] === 'Adicionar produto')
    assert.ok(create())
    ;(create()!.props.onClick as () => void)()
    assert.equal(page.actions.calls, 1)
    page.actions.busy = true
    await Vue.nextTick()
    assert.equal(create()!.props.disabled, true)
    page.actions.allowed = false
    await Vue.nextTick()
    assert.equal(create(), undefined)
    assert.deepEqual(page.errors, [])
  })

  it('retains one catalog parent panel and flat toolbar across child transitions, history and a direct reload', async (t) => {
    const page = mountPage('pages/erp/cadastros.vue', {}, '/erp/cadastros/produtos')
    t.after(page.stop)
    await Vue.nextTick()
    const panel = walk(page.root).find(node => node.props['data-panel'])!
    const links = () => walk(page.root).filter(node => node.tag === 'a')
    const create = () => walk(page.root).find(node => node.props['aria-label'] === 'Adicionar produto')
    assert.equal(walk(page.root).filter(node => node.props['data-panel']).length, 1)
    assert.equal(walk(page.root).filter(node => node.props['data-navbar']).length, 1)
    assert.equal(walk(page.root).filter(node => node.props['data-toolbar']).length, 1)
    assert.ok(create())
    assert.ok(links().some(node => node.props.href === page.route.path && node.props['data-active']))
    page.route.path = '/erp/cadastros/contatos'
    await Vue.nextTick()
    assert.equal(walk(page.root).find(node => node.props['data-panel']), panel)
    assert.equal(create(), undefined, 'former child action is unmounted')
    assert.ok(links().some(node => node.props.href === page.route.path && node.props['data-active']))
    page.route.path = '/erp/cadastros/produtos'
    await Vue.nextTick()
    await Vue.nextTick()
    assert.ok(create(), 'history entry gets the new child resource action')
    page.actions.allowed = false
    page.currentCompany.value = { ...company(), id: 2, role: 'viewer', permissions: ['erp.units.index'] }
    await Vue.nextTick()
    assert.equal(create(), undefined)
    assert.deepEqual(links().map(node => node.props.href), ['/erp/cadastros/unidades'])
    assert.deepEqual(page.errors, [])
    const reloaded = mountPage('pages/erp/cadastros.vue', {}, '/erp/cadastros/produtos')
    t.after(reloaded.stop)
    await Vue.nextTick()
    assert.equal(walk(reloaded.root).filter(node => node.props['data-panel']).length, 1)
    assert.ok(walk(reloaded.root).some(node => node.props.href === '/erp/cadastros/produtos' && node.props['data-active']))
  })

  it('reveals an offscreen active destination on mount and history without moving focus', async (t) => {
    const page = mountPage('pages/erp/configuracoes.vue')
    t.after(page.stop)
    await Vue.nextTick()
    assert.deepEqual(page.scrolls, [{ block: 'nearest', inline: 'nearest' }])
    page.route.path = '/erp/configuracoes/sms'
    await Vue.nextTick()
    assert.equal(page.scrolls.length, 2)
  })

  it('reveals a destination reached by native keyboard focus without moving focus itself', async (t) => {
    const page = mountPage('components/erp/ErpWorkspaceDestinationToolbar.vue', { items: [{ label: 'SMS', to: '/erp/configuracoes/sms', active: true }] })
    t.after(page.stop)
    await Vue.nextTick()
    const toolbar = walk(page.root).find(node => node.props['data-toolbar'])!
    assert.equal(typeof toolbar.props.onFocusin, 'function')
    ;(toolbar.props.onFocusin as (event: unknown) => void)({ target: page.focusTarget })
    assert.equal(page.scrolls.length, 2)
  })

  it('retains the standalone operational panel/navbar and its local actions', (t) => {
    const page = mountPage('components/erp/ErpCatalogPageShell.vue', { title: 'Estoque' })
    t.after(page.stop)
    assert.equal(walk(page.root).filter(node => node.props['data-panel']).length, 1)
    assert.equal(walk(page.root).filter(node => node.props['data-navbar']).length, 1)
    assert.match(text(page.root), /Estoque/)
  })
})
