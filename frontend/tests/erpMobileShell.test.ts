import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as nav from '../app/utils/erpModulesNav.ts'
import * as sidebarNav from '../app/utils/sidebarNav.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

const erpPresentation = loadAppComposable('components/erp/erpPresentation.ts')
const workspaceNav = loadAppComposable<typeof import('../app/utils/erpWorkspaceNav.ts')>('utils/erpWorkspaceNav.ts')
const access = loadAppComposable('../shared/utils/access.ts')

function evaluate(code: string, globals: Record<string, unknown> = {}) {
  const output = ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports = {} as { default: { setup: (props: object, context: object) => { statusLabel: Vue.Ref<string>, subscription: Vue.Ref<{ company: { name: string } } | null>, error: Vue.Ref<unknown>, load: () => Promise<void> } }, render: (...args: unknown[]) => Vue.VNode }
  new Function('require', 'exports', ...Object.keys(globals), output)((name: string) => {
    if (name === 'vue') return { ...Vue, resolveComponent: globals.resolveComponent ?? ((name: string) => ({ name })), renderSlot: (slots: Record<string, () => Vue.VNode[]>, name: string, props: { key?: string }) => Vue.h(Vue.Fragment, { key: props?.key }, slots[name]?.()) }
    if (name === '~/utils/erpModulesNav') return nav
    if (name === '~/utils/sidebarNav') return sidebarNav
    if (name === '#shared/utils/access') return access
    if (name === '~/components/erp/erpPresentation') return erpPresentation
    assert.equal(name, '~/utils/erpWorkspaceNav')
    return workspaceNav
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
    definePageMeta: (meta: Record<string, unknown>) => metadata.push(meta), useHead() {},
    useErpSidebarNavigation: () => ({ closeForNavigation() {} }),
    useErpAdministrationContext: () => ({ key: Vue.computed(() => 'test-context') }),
    ...bindings
  }).default
  const state = scope.run(() => component.setup(props, { expose() {} }))
  const render = evaluate(template.code, { resolveComponent: bindings.resolveComponent }).render
  return { state, metadata, mounted, render: (slots = {}) => render({ $slots: slots }, [], props, Vue.proxyRefs(state), {}, {}), close: () => scope.stop() }
}
function nodes(node: Vue.VNode, collapsed = false): Vue.VNode[] {
  const children = Array.isArray(node.children)
    ? node.children
    : node.children && typeof node.children === 'object'
      ? Object.entries(node.children).filter(([, value]) => typeof value === 'function').flatMap(([name, slot]) => {
          if (name === 'item-trailing' && Array.isArray(node.props?.items))
            return node.props.items.flat().filter(item => item.type !== 'label').flatMap(item => slot({ item, active: item.active }))
          return slot({ collapsed, collapse() {} })
        })
      : []
  return [node, ...children.filter(Vue.isVNode).flatMap(child => nodes(child, collapsed))]
}
function named(root: Vue.VNode, name: string, collapsed = false) {
  return nodes(root, collapsed).filter(node => typeof node.type === 'object' && (node.type as { name?: string }).name === name).map((node) => {
    // Vue camelizes explicit kebab-case props while mounting; VNode inspection must match it.
    if (node.props && 'model-value' in node.props) node.props.modelValue = node.props['model-value']
    return node
  })
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
  it('keeps the operational native header visible with company/user controls and one sidebar toggle', (t) => {
    const currentCompany = Vue.ref(company())
    const view = surface('layouts/erp.vue', { useRoute: () => ({ path: '/erp' }), useAuth: () => ({ currentCompany, currentAccount: Vue.ref(null) }) })
    t.after(view.close)
    const slots = named(view.render(), 'UDashboardPanel')[0]!.children as Record<string, () => Vue.VNode[]>
    assert.equal(slots.default, undefined, 'Nuxt UI default slot replaces the header and body')
    assert.ok(slots.body, 'Page content belongs in the scrollable body')
    const header = slots.header!()[0]!
    assert.equal(header.props?.title, 'Empresa A')
    const navbar = named(header, 'UDashboardNavbar')[0]!
    const navbarUi = navbar.props?.ui as { left?: string, title?: string, right?: string }
    assert.doesNotMatch(navbarUi.title ?? '', /hidden/)
    assert.match(navbarUi.left ?? '', /flex-1/)
    assert.match(navbarUi.right ?? '', /shrink-0/)
    assert.ok(named(header, 'CompanyMenu').length, 'Company switch must be available with sidebar closed')
    assert.ok(named(header, 'UserMenu').length, 'User controls must be available with sidebar closed')
    const toggles = named(header, 'UDashboardSidebarToggle')
    assert.equal(toggles.length, 1, 'Navbar exposes one native mobile toggle')
    assert.equal(toggles[0]!.props?.['aria-label'], 'Abrir menu ERP')
    currentCompany.value = { ...company(), name: 'Empresa B' }
    assert.equal(named(view.render(), 'UDashboardNavbar')[0]!.props!.title, 'Empresa B')
    const links = named(view.render(), 'UNavigationMenu').flatMap(menu => menu.props!.items)
    assert.ok(links.flat().some((link: { label: string, children?: Array<{ to?: string }> }) => link.label === 'Relatórios' && link.children?.some(child => child.to === '/erp/relatorios#sales')))
  })
  it('lets every catalog route provide its own single native panel', (t) => {
    const route = Vue.reactive({ path: '/erp/cadastros/contatos' })
    const view = surface('layouts/erp.vue', { useRoute: () => route, useAuth: () => ({ currentCompany: Vue.ref(company()), currentAccount: Vue.ref(null) }) })
    t.after(view.close)
    for (const path of ['/erp/cadastros/contatos', '/erp/cadastros/produtos', '/erp/cadastros/unidades', '/erp/cadastros/categorias', '/erp/cadastros/marcas', '/erp/cadastros/precos', '/erp/cadastros/fiscal', '/erp/cadastros/logistica', '/erp/cadastros/importacao', '/erp/cadastros/etiquetas']) {
      route.path = path
      const layout = view.render({ default: () => [Vue.h('main', { 'data-catalog-page': true })] })
      assert.equal(named(layout, 'UDashboardPanel').length, 0, `The ${path} page supplies the panel without nesting it in the layout panel`)
      assert.ok(nodes(layout).some(node => node.type === 'main'), `The ${path} page content remains in the layout slot`)
    }
  })
  it('keeps one full disclosure across regions, pins the active child, and respects manual fold across hashes', async (t) => {
    const route = Vue.reactive({ path: '/erp/financeiro', hash: '#payments' })
    const currentCompany = Vue.ref(company())
    const currentAccount = Vue.ref<{ id: number } | null>(null)
    const session = Vue.ref(1)
    let selected = 0
    const view = surface('layouts/erp.vue', {
      useRoute: () => route,
      useAuth: () => ({ currentCompany, currentAccount }),
      useErpAdministrationContext: () => ({ key: Vue.computed(() => JSON.stringify([
        session.value, currentAccount.value?.id, currentCompany.value.id,
        currentCompany.value.role, currentCompany.value.permissions
      ])) }),
      useErpSidebarNavigation: () => ({ closeForNavigation() { selected++ } })
    })
    t.after(view.close)
    const menus = () => named(view.render(), 'UNavigationMenu')
    const menu = (label: string) => {
      const found = menus().find(node => node.props?.items?.[0]?.label === label)
      assert.ok(found, `menu entry ${label} is rendered`)
      return found
    }
    const toggle = (label: string, value: string) => {
      const handler = menu(label).props?.['onUpdate:modelValue'] as ((value: string) => void) | undefined
      assert.ok(handler, `${label} has a controlled update handler`)
      handler(value)
    }
    const model = (label: string) => menu(label).props?.modelValue

    await view.mounted[0]!()
    await Vue.nextTick()
    assert.equal(model('Financeiro'), 'erp-financeiro')
    assert.equal(menu('Financeiro').props?.type, 'single')
    assert.equal(model('Configurações'), '', 'the lower region uses the same single open group')

    toggle('Financeiro', '')
    await Vue.nextTick()
    toggle('Configurações', 'erp-configuracoes')
    await Vue.nextTick()
    assert.equal(model('Configurações'), 'erp-configuracoes')
    const finance = menu('Financeiro')
    assert.equal(finance.props?.modelValue, 'erp-financeiro', 'the active child remains disclosed while its parent is folded')
    const pinned = finance.props?.items?.[0]?.children as Array<{ active?: boolean, ui?: { childItem?: string }, onSelect?: () => void }>
    assert.equal(pinned.filter(child => child.active).length, 1)
    assert.ok(pinned.filter(child => !child.active).every(child => child.ui?.childItem === 'hidden'))
    const search = named(view.render(), 'UDashboardSearch')[0]!.props!.groups as Array<{ items: Array<{ to: string }> }>
    assert.ok(search.flatMap(group => group.items).some(item => item.to === '/erp/financeiro#obligations'), 'search keeps the raw permitted destination while siblings are visually folded')
    const collapsed = named(view.render(), 'UNavigationMenu', true)
    const collapsedFinance = collapsed.flatMap(node => node.props!.items).find(item => item.label === 'Financeiro')
    assert.equal(collapsed.length, 2, 'collapsed popovers still use the complete two-region navigation')
    assert.ok(collapsedFinance.children.some((child: { to?: string }) => child.to === '/erp/financeiro#obligations'))
    assert.equal(pinned.find(child => child.active), collapsedFinance.children.find((child: { active?: boolean }) => child.active), 'the pinned destination retains its original node')
    assert.equal(collapsed[0]!.props!.popover, '')
    assert.equal(selected, 0, 'disclosing a parent does not navigate or close the mobile sidebar')
    pinned.find(child => child.active)?.onSelect?.()
    assert.equal(selected, 1, 'selecting a child preserves the mobile navigation/focus path')

    assert.equal(model('Financeiro'), 'erp-financeiro', 'the current child remains pinned after a parent click')
    route.hash = '#obligations'
    await Vue.nextTick()
    assert.equal(model('Financeiro'), 'erp-financeiro')
    assert.equal(model('Configurações'), 'erp-configuracoes', 'same-module hash does not reopen the active parent')

    route.path = '/erp/configuracoes/empresa'
    route.hash = ''
    await Vue.nextTick()
    assert.equal(model('Configurações'), 'erp-configuracoes', 'entering the new owner opens the lower region')
    assert.equal(model('Financeiro'), '', 'previous owner closes when route owner changes')

    route.path = '/erp'
    await Vue.nextTick()
    assert.equal(model('Configurações'), '', 'leaf route clears expansion')
    route.path = '/erp/financeiro'
    await Vue.nextTick()
    assert.equal(model('Financeiro'), 'erp-financeiro')
    toggle('Financeiro', '')
    await Vue.nextTick()
    session.value++
    await Vue.nextTick()
    assert.equal(menu('Financeiro').props?.items?.[0]?.children?.filter((child: { ui?: { childItem?: string } }) => child.ui?.childItem === 'hidden').length, 0, 'a new session clears the stale fold')

    toggle('Financeiro', '')
    await Vue.nextTick()
    currentAccount.value = { id: 9 }
    await Vue.nextTick()
    assert.equal(menu('Financeiro').props?.items?.[0]?.children?.filter((child: { ui?: { childItem?: string } }) => child.ui?.childItem === 'hidden').length, 0, 'Account context clears the stale fold')

    toggle('Financeiro', '')
    await Vue.nextTick()
    currentCompany.value = { ...company(), id: 2 }
    await Vue.nextTick()
    assert.equal(menu('Financeiro').props?.items?.[0]?.children?.filter((child: { ui?: { childItem?: string } }) => child.ui?.childItem === 'hidden').length, 0, 'Company context clears the stale fold')

    currentCompany.value.permissions.splice(0, 1)
    await Vue.nextTick()
    assert.equal(menus().some(node => node.props?.items?.[0]?.label === 'Financeiro'), false, 'permission removal removes the unavailable owner')
  })
  it('keeps the Finance sidebar hash default identical for SSR and initial client render, then applies the URL hash on mount', async (t) => {
    const route = Vue.reactive({ path: '/erp/financeiro', hash: '#payments' })
    const activeFinanceTarget = (items: Array<{ label: string, children?: Array<{ to?: string, active?: boolean }> }>) =>
      items.flat().find(item => item.label === 'Financeiro')?.children?.find(child => child.active)?.to
    const serverView = surface('layouts/erp.vue', {
      useRoute: () => route,
      useAuth: () => ({ currentCompany: Vue.ref(company()), currentAccount: Vue.ref(null) })
    })
    t.after(serverView.close)
    const serverItems = named(serverView.render(), 'UNavigationMenu').flatMap(menu => menu.props!.items) as Array<{ label: string, children?: Array<{ to?: string, active?: boolean }> }>
    assert.equal(activeFinanceTarget(serverItems), '/erp/financeiro#obligations', 'SSR uses the contract default despite the browser URL fragment')

    let clientMenuProps: { items: Array<{ label: string, children?: Array<{ to?: string, active?: boolean }> }> } | undefined
    const menu = Vue.defineComponent({
      name: 'UNavigationMenu',
      props: { items: { type: Array as Vue.PropType<Array<{ label: string, children?: Array<{ to?: string, active?: boolean }> }>>, default: () => [] } },
      setup(props) {
        clientMenuProps = props
        return () => Vue.h('nav')
      }
    })
    const clientView = surface('layouts/erp.vue', {
      useRoute: () => route,
      useAuth: () => ({ currentCompany: Vue.ref(company()), currentAccount: Vue.ref(null) }),
      resolveComponent: (name: string) => name === 'UNavigationMenu' ? menu : ({ name })
    })
    t.after(clientView.close)
    const unmount = mount(() => Vue.h({ render: () => named(clientView.render(), 'UNavigationMenu').find(menu => menu.props?.items?.[0]?.label === 'Financeiro')! }))
    t.after(unmount)
    assert.equal(activeFinanceTarget(clientMenuProps!.items), activeFinanceTarget(serverItems), 'the initial client render matches SSR')

    await clientView.mounted[0]!()
    await Vue.nextTick()
    assert.equal(activeFinanceTarget(clientMenuProps!.items), '/erp/financeiro#payments', 'the mounted client applies the direct-link hash')
  })
  it('changes the catalog content key when company or Account context changes', (t) => {
    const currentCompany = Vue.ref(company())
    const currentAccount = Vue.ref<{ id: number } | null>(null)
    const view = surface('layouts/erp.vue', { useRoute: () => ({ path: '/erp/cadastros/contatos' }), useAuth: () => ({ currentCompany, currentAccount }) })
    t.after(view.close)
    const slotKey = () => nodes(view.render({ default: () => [Vue.h('main')] })).find(node => node.type === Vue.Fragment && typeof node.key === 'string' && node.key.includes(':'))?.key
    const initial = slotKey()
    currentCompany.value = { ...company(), id: 2 }
    assert.notEqual(slotKey(), initial)
    const next = slotKey()
    currentAccount.value = { id: 9 }
    assert.notEqual(slotKey(), next)
  })
  it('disposes the old protected child and mounts a fresh child after selected company context changes', async (t) => {
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
    const unmount = mount(() => nodes(view.render({ default: () => [Vue.h(child)] })).find(node => node.type === Vue.Fragment && typeof node.key === 'string' && node.key.includes(':'))!)
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
  it('uses a native panel, page title, context controls and one marked sidebar toggle for catalogs', (t) => {
    const view = surface('components/erp/ErpCatalogPageShell.vue', {}, { title: 'Contatos' })
    t.after(view.close)
    const root = view.render({ actions: () => [Vue.h('button', { 'aria-label': 'Criar contato' }, 'Novo contato')], default: () => [Vue.h('main', { 'data-catalog-page': true })] })
    const panels = named(root, 'UDashboardPanel')
    assert.equal(panels.length, 1)
    const slots = panels[0]!.children as Record<string, () => Vue.VNode[]>
    const header = slots.header!()[0]!
    assert.equal(header.props?.title, 'Contatos')
    assert.equal(header.props?.toggle, false, 'The explicit trigger owns the only mobile toggle')
    assert.equal(named(header, 'UDashboardSidebarToggle').length, 1)
    assert.equal(named(header, 'CompanyMenu').length, 1)
    assert.equal(named(header, 'UserMenu').length, 1)
    const headerSlots = header.children as Record<string, () => Vue.VNode[]>
    assert.ok(nodes(Vue.h(Vue.Fragment, {}, headerSlots.right!())).some(node => node.props?.['aria-label'] === 'Criar contato'), 'Page actions remain in the same reachable mobile header row')
    assert.ok(nodes(slots.body!()[0]!).some(node => node.type === 'main'))
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
    const menu = surface('components/WorkspaceContextMenu.vue', {
      useAuth: () => ({ user: Vue.ref({ accounts: [], companies: [company()], current_company: company() }) }),
      useToast: () => ({ add() {} })
    })
    t.after(menu.close)
    const groups = named(menu.render(), 'UDropdownMenu')[0]!.props!.items
    assert.deepEqual(groups.map((group: { label: string }[]) => group[0]?.label), ['Empresas'])
    assert.equal(groups[0][1].active, true)
    assert.equal(named(menu.render(), 'UIcon').filter(node => node.props?.name === 'i-lucide-check').length, 1)
    assert.equal(named(menu.render(), 'UButton')[0]!.props!.label, 'Empresa A')
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
