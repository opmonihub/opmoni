import assert from 'node:assert/strict'
import { readFileSync, realpathSync } from 'node:fs'
import { createRequire } from 'node:module'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import type { NavigationMenuItem } from '@nuxt/ui'
import { pickLinkProps } from '../node_modules/@nuxt/ui/dist/runtime/utils/link.js'
import * as adminNav from '../app/utils/adminNav.ts'
import * as clientsNav from '../app/utils/clientsNav.ts'
import * as settingsNav from '../app/utils/settingsNav.ts'
import * as fiscalNav from '../app/utils/fiscalNav.ts'
import * as workNav from '../app/utils/workNav.ts'
import * as equipeNav from '../app/utils/equipeNav.ts'
import * as monitoringNav from '../app/utils/monitoringNav.ts'
import * as sidebarNav from '../app/utils/sidebarNav.ts'

// Resolve the native component's transitive dependency from Nuxt UI under pnpm.
const requireFromUi = createRequire(realpathSync(new URL('../node_modules/@nuxt/ui/package.json', import.meta.url)))
const Reka = requireFromUi('reka-ui')

function evaluate(code: string, imports: Record<string, unknown>, globals: Record<string, unknown> = {}) {
  const compiled = ts.transpileModule(code, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext, esModuleInterop: true }
  }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(globals), compiled)(
    (name: string) => {
      assert.ok(name in imports, `Unexpected runtime import: ${name}`)
      return imports[name]
    }, exports, ...Object.values(globals)
  )
  return exports
}

function compileSurface(filename: URL, imports: Record<string, unknown>, globals: Record<string, unknown> = {}, props: object = {}) {
  const { descriptor } = parse(readFileSync(filename, 'utf8'))
  const script = compileScript(descriptor, { id: filename.pathname })
  const template = compileTemplate({
    source: descriptor.template!.content,
    filename: filename.pathname,
    id: filename.pathname,
    compilerOptions: { bindingMetadata: script.bindings }
  })
  assert.deepEqual(template.errors, [])
  const component = evaluate(script.content, imports, globals).default as {
    setup: (props: object, context: object) => Record<string, unknown>
  }
  const scope = Vue.effectScope()
  const state = scope.run(() => component.setup(props, { expose() {} }))!
  const render = evaluate(template.code, imports).render as (...args: unknown[]) => Vue.VNode
  return {
    render: () => render({ $slots: {} }, [], props, Vue.proxyRefs(state), {}, {}),
    close: () => scope.stop()
  }
}

function setupLayout(path: string, superAdmin = true) {
  const route = Vue.reactive({ path })
  const imports = {
    'vue': {
      ...Vue,
      resolveComponent: (name: string) => ({ name }),
      // The routed page slot is unrelated to the sidebar projection.
      renderSlot: () => Vue.h(Vue.Fragment)
    },
    '~/utils/adminNav': adminNav,
    '~/utils/clientsNav': clientsNav,
    '~/utils/settingsNav': settingsNav,
    '~/utils/fiscalNav': fiscalNav,
    '~/utils/workNav': workNav,
    '~/utils/equipeNav': equipeNav,
    '~/utils/monitoringNav': monitoringNav,
    '~/utils/sidebarNav': sidebarNav
  }
  const surface = compileSurface(new URL('../app/layouts/default.vue', import.meta.url), imports, {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    useRoute: () => route,
    useAuth: () => ({
      currentAccount: Vue.ref({ id: 1, name: 'Escritório teste', status: 'active', role: 'user' }),
      user: Vue.ref({ is_super_admin: superAdmin }),
      canManageMembers: Vue.ref(false)
    })
  })
  return { ...surface, route }
}

function nodes(vnode: Vue.VNode, collapsed: boolean): Vue.VNode[] {
  const result = [vnode]
  if (Array.isArray(vnode.children)) {
    for (const child of vnode.children) if (Vue.isVNode(child)) result.push(...nodes(child, collapsed))
  } else if (vnode.children && typeof vnode.children === 'object') {
    for (const slot of Object.values(vnode.children)) {
      if (typeof slot !== 'function') continue
      const children = slot({ collapsed, collapse() {} })
      for (const child of Array.isArray(children) ? children : [children]) {
        if (Vue.isVNode(child)) result.push(...nodes(child, collapsed))
      }
    }
  }
  return result
}

function named(view: Vue.VNode[], name: string) {
  return view.filter(node => typeof node.type === 'object' && (node.type as { name?: string }).name === name).map((node) => {
    // Mounting Vue components camelizes explicit kebab-case props; this VNode-only harness must do the same.
    if (node.props && 'model-value' in node.props) node.props.modelValue = node.props['model-value']
    return node
  })
}

function menu(surface: ReturnType<typeof setupLayout>, collapsed: boolean, label = 'Clientes') {
  const found = named(nodes(surface.render(), collapsed), 'UNavigationMenu').find((node) => {
    return (node.props!.items as NavigationMenuItem[]).some(item => item.label === label)
  })
  assert.ok(found)
  return found
}

function clients(surface: ReturnType<typeof setupLayout>, collapsed: boolean) {
  return (menu(surface, collapsed).props!.items as NavigationMenuItem[]).find(item => item.label === 'Clientes')!
}

describe('collapsed Clients disclosure', () => {
  it('passes no parent destination to native link props while retaining the module icon, active context and accessible name', (t) => {
    const surface = setupLayout('/customers/procuracao/vencido')
    t.after(surface.close)
    const expanded = clients(surface, false)
    const collapsed = clients(surface, true)
    const link = pickLinkProps(collapsed)

    assert.equal(link.to, undefined, 'Collapsed Clientes must disclose its popover, not navigate to /customers')
    assert.equal(link.href, undefined)
    assert.equal(link['aria-label'], 'Clientes')
    assert.equal(collapsed.icon, 'i-lucide-users')
    assert.equal(collapsed.icon, expanded.icon)
    assert.equal(collapsed.active, true)
    assert.equal(collapsed.value, expanded.value)
    assert.equal(collapsed.type, 'trigger')
    assert.equal(collapsed.children, expanded.children)
    assert.deepEqual(collapsed.children?.filter(item => item.active).map(item => item.label), ['Meus clientes'])
    assert.equal(expanded.to, '/customers', 'The projection must not mutate the expanded or command-palette metadata')
    assert.equal(pickLinkProps(expanded).to, '/customers')
    assert.equal(menu(surface, true).props!.popover, '')
    assert.equal(menu(surface, true).props!.tooltip, '')
    assert.equal(menu(surface, true).props!.collapsed, true)
  })

  it('keeps native ULinkBase a no-href button whose click leaves the current document/status route unchanged', async (t) => {
    const surface = setupLayout('/customers/procuracao/vencido')
    t.after(surface.close)
    const link = pickLinkProps(clients(surface, true))
    const destinations: string[] = []
    const nativeProps = {
      ...link,
      as: 'button',
      type: 'button',
      href: link.to ?? link.href,
      navigate: () => {
        destinations.push(String(link.to ?? link.href))
        surface.route.path = '/customers/painel'
      }
    }
    const native = compileSurface(new URL('../node_modules/@nuxt/ui/dist/runtime/components/LinkBase.vue', import.meta.url), {
      'vue': { ...Vue, renderSlot: () => Vue.h(Vue.Fragment) },
      'reka-ui': Reka
    }, {}, nativeProps)
    t.after(native.close)
    const root = native.render()
    assert.equal(root.props!.as, 'button')
    assert.equal(root.props!.href, undefined)
    assert.equal(root.props!.type, 'button')
    await root.props!.onClick({ stopPropagation() {}, preventDefault() {} })
    assert.deepEqual(destinations, [])
    assert.equal(surface.route.path, '/customers/procuracao/vencido')
  })

  it('retains child destinations and mobile close callbacks without changing other sidebar items or Admin', (t) => {
    const surface = setupLayout('/customers/procuracao/vencido')
    t.after(surface.close)
    const expanded = menu(surface, false).props!.items as NavigationMenuItem[]
    const collapsed = menu(surface, true).props!.items as NavigationMenuItem[]
    for (let index = 0; index < expanded.length; index++) {
      if (expanded[index]!.label !== 'Clientes') assert.equal(collapsed[index], expanded[index])
    }
    const children = clients(surface, true).children!
    assert.deepEqual(children.map(item => [item.label, item.to]), [
      ['Painel', '/customers/painel'],
      ['Meus clientes', '/customers/certificados']
    ])
    const sidebar = () => named(nodes(surface.render(), true), 'UDashboardSidebar')[0]!
    for (const child of children) {
      sidebar().props!['onUpdate:open'](true)
      child.onSelect?.(new Event('select'))
      assert.equal(sidebar().props!.open, false)
    }
    const collapsedAdmin = (menu(surface, true, 'Admin').props!.items as NavigationMenuItem[])[0]!
    const expandedAdmin = (menu(surface, false, 'Admin').props!.items as NavigationMenuItem[])[0]!
    assert.equal(collapsedAdmin.children, expandedAdmin.children)
    assert.equal(collapsedAdmin.icon, expandedAdmin.icon)
    assert.equal(collapsedAdmin.to, expandedAdmin.to)
    assert.notEqual(menu(surface, false, 'Admin').props!.modelValue, 'admin')
    const ordinary = setupLayout('/customers/certificados', false)
    t.after(ordinary.close)
    assert.equal(named(nodes(ordinary.render(), true), 'UNavigationMenu').some((node) => {
      return (node.props!.items as NavigationMenuItem[]).some(item => item.label === 'Admin')
    }), false)
  })

  it('keeps the complete search and popover catalogues while every active module is folded', (t) => {
    for (const [path, label, activeLabel] of [
      ['/customers/certificados', 'Clientes', 'Meus clientes'],
      ['/fiscal/documentos/42', 'Fiscal', 'Documentos'],
      ['/monitoring/declaracoes/defis/pendencias', 'Monitoramento', 'Declarações'],
      ['/monitoring/parcelamentos/especiais/atencao', 'Monitoramento', 'Parcelamentos'],
      ['/settings/members/42', 'Settings', 'Members'],
      ['/admin/contas/42', 'Admin', 'Contas']
    ]) {
      const surface = setupLayout(path!)
      t.after(surface.close)
      const full = (menu(surface, true, label).props!.items as NavigationMenuItem[]).find(item => item.label === label)!
      menu(surface, false, label).props!['onUpdate:modelValue'](undefined)
      const folded = (menu(surface, false, label).props!.items as NavigationMenuItem[]).find(item => item.label === label)!
      assert.deepEqual(folded.children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), [activeLabel], path)
      assert.equal(folded.children?.find(child => child.active), full.children?.find(child => child.active), path)
      const popover = (menu(surface, true, label).props!.items as NavigationMenuItem[]).find(item => item.label === label)!
      assert.equal(popover.children, full.children, path)
      assert.ok(popover.children?.every(child => child.ui?.childItem !== 'hidden'), path)
      const search = named(nodes(surface.render(), false), 'UDashboardSearch')[0]!
      const entries = search.props!.groups[0].items as Array<{ to?: string }>
      for (const child of full.children!) {
        assert.ok(entries.some(entry => entry.to === child.to), `${path}: ${child.to}`)
        const sidebar = () => named(nodes(surface.render(), false), 'UDashboardSidebar')[0]!
        sidebar().props!['onUpdate:open'](true)
        child.onSelect?.(new Event('select'))
        assert.equal(sidebar().props!.open, false, `${label}: ${child.label}`)
      }
    }
  })

  it('preserves the active node, folded state within a module and full icon popovers, reopening on module changes', async (t) => {
    const surface = setupLayout('/customers/procuracao/vencido')
    t.after(surface.close)
    const value = clients(surface, false).value
    const activeNode = clients(surface, false).children!.find(child => child.active)
    assert.equal(menu(surface, false).props!.type, 'single')
    assert.equal(menu(surface, false).props!.modelValue, value)
    menu(surface, false).props!['onUpdate:modelValue'](undefined)
    assert.equal(menu(surface, false).props!.modelValue, value, 'Content remains open for the active link')
    assert.equal(clients(surface, false).children!.find(child => child.active), activeNode, 'No replacement active link')
    assert.equal(clients(surface, false).children![0]?.ui?.childItem, 'hidden', 'display:none removes sibling from keyboard and accessibility tree')
    assert.match(clients(surface, false).ui?.linkTrailingIcon as string, /!rotate-0/)
    assert.deepEqual(clients(surface, true).children?.map(child => child.ui?.childItem), [undefined, undefined])
    assert.equal(clients(surface, true).active, true)

    surface.route.path = '/customers/certificados/a-vencer'
    await Vue.nextTick()
    assert.equal(menu(surface, false).props!.modelValue, value)
    assert.deepEqual(clients(surface, false).children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Meus clientes'])
    assert.deepEqual(clients(surface, true).children?.filter(item => item.active).map(item => item.label), ['Meus clientes'])
    surface.route.path = '/customers/painel'
    await Vue.nextTick()
    assert.deepEqual(clients(surface, false).children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Painel'])

    menu(surface, false, 'Monitoramento').props!['onUpdate:modelValue']('monitoramento')
    assert.equal(menu(surface, false, 'Monitoramento').props!.modelValue, 'monitoramento')
    assert.deepEqual(clients(surface, false).children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Painel'])
    menu(surface, false).props!['onUpdate:modelValue'](undefined)
    assert.deepEqual(clients(surface, false).children?.map(child => child.ui?.childItem), [undefined, undefined], 'Clicking the pinned group fully expands it')
    assert.notEqual(menu(surface, false, 'Monitoramento').props!.modelValue, 'monitoramento')

    surface.route.path = '/admin/contas'
    await Vue.nextTick()
    assert.equal(clients(surface, true).active, false)
    assert.equal(menu(surface, false, 'Admin').props!.modelValue, 'admin')
    menu(surface, false, 'Admin').props!['onUpdate:modelValue'](undefined)
    const adminItem = (menu(surface, false, 'Admin').props!.items as NavigationMenuItem[])[0]!
    assert.deepEqual(adminItem.children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Contas'])
    assert.equal((menu(surface, true, 'Admin').props!.items as NavigationMenuItem[])[0]!.children?.length, 9)
    assert.equal(menu(surface, true, 'Admin').props!.popover, '')
    surface.route.path = '/customers/painel'
    await Vue.nextTick()
    assert.deepEqual(clients(surface, false).children?.map(child => child.ui?.childItem), [undefined, undefined])
  })
})
