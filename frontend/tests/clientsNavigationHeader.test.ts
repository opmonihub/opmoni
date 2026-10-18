import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import type { NavigationMenuItem } from '@nuxt/ui'
import * as adminNav from '../app/utils/adminNav.ts'
import * as settingsNav from '../app/utils/settingsNav.ts'
import * as fiscalNav from '../app/utils/fiscalNav.ts'
import * as workNav from '../app/utils/workNav.ts'
import * as equipeNav from '../app/utils/equipeNav.ts'
import * as monitoringNav from '../app/utils/monitoringNav.ts'
import * as customerRoutes from '../app/utils/customerRoutes.ts'
import * as sidebarNav from '../app/utils/sidebarNav.ts'

function evaluate(code: string, imports: Record<string, unknown>, globals: Record<string, unknown> = {}) {
  const compiled = ts.transpileModule(code, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext, esModuleInterop: true }
  }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(globals), compiled)(
    (name: string) => {
      if (name === '~/utils/clientsNav') {
        return evaluate(readFileSync(new URL('../app/utils/clientsNav.ts', import.meta.url), 'utf8'), {
          './customerRoutes.ts': customerRoutes
        })
      }
      assert.ok(name in imports, `Unexpected runtime import: ${name}`)
      return imports[name]
    }, exports, ...Object.values(globals)
  )
  return exports
}

function setupSurface(file: string, path: string, options: { manageClients?: boolean, manageMembers?: boolean, superAdmin?: boolean } = {}) {
  const filename = new URL(`../app/${file}`, import.meta.url)
  const { descriptor } = parse(readFileSync(filename, 'utf8'))
  const script = compileScript(descriptor, { id: file })
  const template = compileTemplate({
    source: descriptor.template!.content,
    filename: filename.pathname,
    id: file,
    compilerOptions: { bindingMetadata: script.bindings }
  })
  assert.deepEqual(template.errors, [])
  const route = Vue.reactive({ path })
  const canManageClients = Vue.ref(options.manageClients ?? true)
  const createRequest = Vue.ref(0)
  const navigations: string[] = []
  const imports = {
    'vue': {
      ...Vue,
      resolveComponent: (name: string) => ({ name }),
      // The routed child slot is outside this header/sidebar regression harness.
      renderSlot: () => Vue.h(Vue.Fragment)
    },
    '~/utils/adminNav': adminNav,
    '~/utils/settingsNav': settingsNav,
    '~/utils/fiscalNav': fiscalNav,
    '~/utils/workNav': workNav,
    '~/utils/equipeNav': equipeNav,
    '~/utils/monitoringNav': monitoringNav,
    '~/utils/customerRoutes': customerRoutes,
    '~/utils/sidebarNav': sidebarNav
  }
  const globals = {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    definePageMeta() {},
    useRoute: () => route,
    useAuth: () => ({
      canManageClients,
      canManageMembers: Vue.ref(options.manageMembers ?? false),
      user: Vue.ref({ is_super_admin: options.superAdmin ?? false })
    }),
    useState: () => createRequest,
    navigateTo: async (destination: string) => {
      navigations.push(destination)
      route.path = destination
    }
  }
  const component = evaluate(script.content, imports, globals).default as {
    setup: (props: object, context: object) => Record<string, unknown>
  }
  const scope = Vue.effectScope()
  const state = scope.run(() => component.setup({}, { expose() {} }))!
  const render = evaluate(template.code, imports).render as (...args: unknown[]) => Vue.VNode
  return {
    route,
    canManageClients,
    createRequest,
    navigations,
    render: () => render({ $slots: {} }, [], {}, Vue.proxyRefs(state), {}, {}),
    close: () => scope.stop()
  }
}

function nodes(vnode: Vue.VNode, collapsed = false): Vue.VNode[] {
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

function mainMenu(surface: ReturnType<typeof setupSurface>, collapsed = false) {
  const menu = named(nodes(surface.render(), collapsed), 'UNavigationMenu').find((node) => {
    return (node.props?.items as NavigationMenuItem[]).some(item => item.label === 'Clientes')
  })
  assert.ok(menu, 'The main sidebar menu must include Clientes')
  return menu
}

function sidebarItems(surface: ReturnType<typeof setupSurface>, collapsed = false) {
  return named(nodes(surface.render(), collapsed), 'UNavigationMenu')
    .flatMap(node => node.props!.items as NavigationMenuItem[])
}

function groupMenu(surface: ReturnType<typeof setupSurface>, label: string) {
  const menu = named(nodes(surface.render()), 'UNavigationMenu').find(node =>
    (node.props!.items as NavigationMenuItem[]).some(item => item.label === label))
  assert.ok(menu, label)
  return menu
}

function fullyOpenGroups(surface: ReturnType<typeof setupSurface>) {
  return named(nodes(surface.render()), 'UNavigationMenu').flatMap((node) => {
    return (node.props!.items as NavigationMenuItem[]).filter(item => item.children?.length
      && node.props!.modelValue === item.value
      && !item.children.some(child => child.ui?.childItem === 'hidden')).map(item => item.label)
  })
}

function clientsItem(surface: ReturnType<typeof setupSurface>) {
  return (mainMenu(surface).props!.items as NavigationMenuItem[]).find(item => item.label === 'Clientes')!
}

const listPaths = [
  '/customers/certificados',
  '/customers/certificados/a-vencer',
  '/customers/certificados/vencido',
  '/customers/certificados/valido',
  '/customers/certificados/sem-cadastro',
  '/customers/procuracao',
  '/customers/procuracao/a-vencer',
  '/customers/procuracao/vencido',
  '/customers/procuracao/valido',
  '/customers/procuracao/sem-cadastro'
]

describe('Clients sidebar navigation', () => {
  it('places Painel and Meus clientes under a native collapsible Clients trigger', (t) => {
    const surface = setupSurface('layouts/default.vue', '/')
    t.after(surface.close)
    const clients = clientsItem(surface)

    assert.equal(clients.type, 'trigger')
    assert.ok(clients.value)
    assert.equal(clients.to, '/customers')
    assert.deepEqual(clients.children?.map(item => [item.label, item.to]), [
      ['Painel', '/customers/painel'],
      ['Meus clientes', '/customers/certificados']
    ])
    assert.equal(clients.children?.[0]?.exact, true)
  })

  it('keeps Clients children text-only like the established module submenus', (t) => {
    const surface = setupSurface('layouts/default.vue', '/customers/painel')
    t.after(surface.close)
    for (const path of ['/customers/painel', ...listPaths, '/customers/empresa/42']) {
      surface.route.path = path
      for (const collapsed of [false, true]) {
        const items = sidebarItems(surface, collapsed)
        const clients = items.find(item => item.label === 'Clientes')!
        assert.equal(clients.icon, 'i-lucide-users', path)
        for (const label of ['Clientes', 'Fiscal', 'Monitoramento', 'Work', 'Equipe', 'Settings']) {
          const item = items.find(entry => entry.label === label)!
          assert.ok(item.children?.length, label)
          for (const child of item.children!) {
            assert.equal(child.icon, undefined, `${path}: ${label} / ${child.label}`)
            assert.equal('icon' in child, false, `${path}: ${label} / ${child.label}`)
          }
        }
      }
    }
  })

  it('marks only the current submenu across dashboard, both lists, statuses and client details', (t) => {
    const surface = setupSurface('layouts/default.vue', '/')
    t.after(surface.close)
    for (const path of ['/customers/painel', ...listPaths, '/customers/empresa/42']) {
      surface.route.path = path
      const clients = clientsItem(surface)
      assert.equal(clients.active, false, `${path}: only the child is highlighted in the expanded sidebar`)
      assert.equal(sidebarItems(surface, true).find(item => item.label === 'Clientes')?.active, true, `${path}: icon retains module context`)
      assert.deepEqual(clients.children?.filter(item => item.active).map(item => item.label), [
        path === '/customers/painel' ? 'Painel' : 'Meus clientes'
      ], path)
    }
    for (const path of ['/', '/customers-other', '/customers/certificados-extra', '/customers/procuracao-extra']) {
      surface.route.path = path
      const clients = clientsItem(surface)
      assert.deepEqual(clients.children?.filter(item => item.active).map(item => item.label), [], path)
      if (!path.startsWith('/customers/')) assert.equal(clients.active, false, path)
    }
  })

  it('exposes the current child on direct entry and reopens Clients after a route transition', async (t) => {
    const direct = setupSurface('layouts/default.vue', '/customers/procuracao/vencido')
    const surface = setupSurface('layouts/default.vue', '/')
    t.after(direct.close)
    t.after(surface.close)
    const value = clientsItem(direct).value
    assert.ok(value)
    assert.equal(mainMenu(direct).props!.modelValue, value)

    surface.route.path = '/customers/empresa/42'
    await Vue.nextTick()
    assert.equal(mainMenu(surface).props!.modelValue, value)
    groupMenu(surface, 'Work').props!['onUpdate:modelValue']('work')
    assert.deepEqual(fullyOpenGroups(surface), ['Work'])
    assert.equal(mainMenu(surface).props!.modelValue, value, 'Current child stays technically mounted')
    assert.deepEqual(clientsItem(surface).children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Meus clientes'])
    surface.route.path = '/customers/certificados/a-vencer'
    await Vue.nextTick()
    assert.deepEqual(fullyOpenGroups(surface), ['Work'], 'Navigation within the module preserves manual state')
    surface.route.path = '/monitoring/declaracoes/defis/pendencias'
    await Vue.nextTick()
    assert.deepEqual(fullyOpenGroups(surface), ['Monitoramento'])
  })

  it('uses stable single group values and preserves Fiscal, Members and specialized obligation activity', (t) => {
    const surface = setupSurface('layouts/default.vue', '/fiscal/documentos/42')
    t.after(surface.close)
    const expected = { Clientes: 'clientes', Fiscal: 'fiscal', Monitoramento: 'monitoramento', Work: 'work', Equipe: 'equipe', Settings: 'configuracoes' }
    for (const [label, value] of Object.entries(expected)) {
      const node = groupMenu(surface, label)
      const item = (node.props!.items as NavigationMenuItem[]).find(item => item.label === label)!
      assert.equal(item.value, value, label)
      assert.equal(node.props!.type, 'single', label)
    }
    for (const [path, label, active] of [
      ['/fiscal/documentos/42', 'Fiscal', 'Documentos'],
      ['/settings/members', 'Settings', 'Members'],
      ['/settings/members/42', 'Settings', 'Members'],
      ['/monitoring/declaracoes/defis/pendencias', 'Monitoramento', 'Declarações'],
      ['/monitoring/parcelamentos/especiais/atencao', 'Monitoramento', 'Parcelamentos'],
      ['/monitoring/situacao-fiscal/certidoes', 'Monitoramento', 'Situação Fiscal']
    ]) {
      surface.route.path = path!
      const item = sidebarItems(surface).find(item => item.label === label)!
      assert.deepEqual(item.children?.filter(child => child.active).map(child => child.label), [active], path)
    }
    surface.route.path = '/settings/members-other'
    assert.equal(sidebarItems(surface).find(item => item.label === 'Settings')!.children?.find(child => child.label === 'Members')?.active, false)
  })

  it('highlights only the active child in expanded groups while retaining icon activity', async (t) => {
    for (const [path, label, childLabel] of [
      ['/customers/painel', 'Clientes', 'Painel'],
      ['/fiscal/documentos/42', 'Fiscal', 'Documentos'],
      ['/monitoring/declaracoes/defis/pendencias', 'Monitoramento', 'Declarações'],
      ['/work/processos/42', 'Work', 'Processos'],
      ['/equipe/departamentos', 'Equipe', 'Departamentos'],
      ['/settings/members/42', 'Settings', 'Members'],
      ['/admin/contas/42', 'Admin', 'Contas']
    ]) {
      const surface = setupSurface('layouts/default.vue', path!, { superAdmin: true })
      t.after(surface.close)
      const expanded = () => sidebarItems(surface).find(item => item.label === label)!
      const collapsed = () => sidebarItems(surface, true).find(item => item.label === label)!
      assert.equal(expanded().active, false, `${path}: parent stays neutral`)
      assert.deepEqual(expanded().children?.filter(child => child.active).map(child => child.label), [childLabel], path)
      assert.equal(collapsed().active, true, `${path}: module icon remains active`)
      assert.equal(collapsed().children, expanded().children, `${path}: no child replacement`)
      groupMenu(surface, label!).props!['onUpdate:modelValue'](undefined)
      assert.equal(expanded().active, false, `${path}: pinned parent stays neutral`)
      assert.deepEqual(expanded().children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), [childLabel], path)
      assert.equal(collapsed().active, true, `${path}: folding does not mute the icon`)
      surface.route.path = '/inbox'
      await Vue.nextTick()
      assert.equal(collapsed().active, false, `${path}: leaving the module clears icon activity`)
    }
    const surface = setupSurface('layouts/default.vue', '/')
    t.after(surface.close)
    const dashboard = sidebarItems(surface).find(item => item.label === 'Dashboard')!
    assert.equal(dashboard.active, undefined, 'Standalone links keep native route activity')
    assert.equal(dashboard.to, '/')
  })

  it('keeps a single fully open group through opening, closing and toggling the pinned module', async (t) => {
    const surface = setupSurface('layouts/default.vue', '/customers/certificados', { superAdmin: true })
    t.after(surface.close)
    assert.deepEqual(fullyOpenGroups(surface), ['Clientes'])
    for (const label of ['Fiscal', 'Monitoramento', 'Work', 'Equipe', 'Settings', 'Admin']) {
      const node = groupMenu(surface, label)
      const item = (node.props!.items as NavigationMenuItem[])[0]!
      node.props!['onUpdate:modelValue'](item.value)
      assert.deepEqual(fullyOpenGroups(surface), [label])
      assert.deepEqual(clientsItem(surface).children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Meus clientes'])
      groupMenu(surface, label).props!['onUpdate:modelValue'](undefined)
      assert.deepEqual(fullyOpenGroups(surface), [])
    }
    mainMenu(surface).props!['onUpdate:modelValue'](undefined)
    assert.deepEqual(fullyOpenGroups(surface), ['Clientes'])
    mainMenu(surface).props!['onUpdate:modelValue'](undefined)
    assert.deepEqual(fullyOpenGroups(surface), [])
    surface.route.path = '/inbox'
    await Vue.nextTick()
    assert.deepEqual(fullyOpenGroups(surface), [])
    surface.route.path = '/customers/painel'
    await Vue.nextTick()
    assert.deepEqual(fullyOpenGroups(surface), ['Clientes'])
  })

  it('keeps group models explicitly controlled even with no expanded module', (t) => {
    const surface = setupSurface('layouts/default.vue', '/', { superAdmin: true })
    t.after(surface.close)
    const assertControlled = () => {
      for (const node of named(nodes(surface.render()), 'UNavigationMenu')) {
        const item = (node.props!.items as NavigationMenuItem[])[0]!
        if (!item.children?.length) continue
        // Reka picks passive/uncontrolled mode once at setup when the initial value is undefined.
        assert.equal(typeof node.props!.modelValue, 'string', item.label)
      }
    }
    assertControlled()
    for (const label of ['Clientes', 'Monitoramento', 'Admin']) {
      const node = groupMenu(surface, label)
      const item = (node.props!.items as NavigationMenuItem[])[0]!
      node.props!['onUpdate:modelValue'](item.value)
      groupMenu(surface, label).props!['onUpdate:modelValue'](undefined)
      assert.deepEqual(fullyOpenGroups(surface), [])
      assertControlled()
    }
  })

  it('preserves Settings permission gates and unconditional Members visibility when folded', (t) => {
    for (const manageMembers of [false, true]) {
      const surface = setupSurface('layouts/default.vue', '/settings/members/42', { manageMembers })
      t.after(surface.close)
      const item = () => sidebarItems(surface).find(item => item.label === 'Settings')!
      assert.equal(item().children?.some(child => child.label === 'Certificado do escritório'), manageMembers)
      assert.equal(item().children?.some(child => child.label === 'Canal WhatsApp'), manageMembers)
      assert.equal(item().children?.find(child => child.label === 'Members')?.active, true)
      groupMenu(surface, 'Settings').props!['onUpdate:modelValue'](undefined)
      assert.deepEqual(item().children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Members'])
      const collapsed = sidebarItems(surface, true).find(item => item.label === 'Settings')!
      assert.ok(collapsed.children?.every(child => child.ui?.childItem !== 'hidden'))
      assert.equal(collapsed.children?.length, manageMembers ? 6 : 4)
    }
  })

  it('preserves collapsed popovers and closes the mobile sidebar from either Clients child', (t) => {
    const surface = setupSurface('layouts/default.vue', '/customers/painel')
    t.after(surface.close)
    const collapsedMenu = mainMenu(surface, true)
    assert.equal(collapsedMenu.props!.collapsed, true)
    assert.equal(collapsedMenu.props!.popover, '')
    assert.equal(collapsedMenu.props!.tooltip, '')
    assert.equal(collapsedMenu.props!.orientation, 'vertical')
    const sidebar = () => named(nodes(surface.render()), 'UDashboardSidebar')[0]!
    const children = clientsItem(surface).children
    assert.equal(children?.length, 2)
    for (const child of children!) {
      sidebar().props!['onUpdate:open'](true)
      assert.equal(sidebar().props!.open, true)
      child.onSelect?.(new Event('select'))
      assert.equal(sidebar().props!.open, false)
    }
  })

  it('keeps both Clients destinations in the command palette alongside the gated Admin menu', async (t) => {
    const surface = setupSurface('layouts/default.vue', '/', { superAdmin: true })
    const ordinary = setupSurface('layouts/default.vue', '/')
    t.after(surface.close)
    t.after(ordinary.close)
    const view = () => nodes(surface.render())
    const search = named(view(), 'UDashboardSearch')[0]!
    const entries = search.props!.groups[0].items as Array<{ label: string, to: string, icon?: string }>
    assert.ok(entries.some(item => item.label === 'Painel' && item.to === '/customers/painel'))
    assert.ok(entries.some(item => item.label === 'Meus clientes' && item.to === '/customers/certificados'))
    assert.ok(entries.some(item => item.to === '/admin/serpro'))
    assert.equal(entries.find(item => item.to === '/customers')?.icon, 'i-lucide-users')
    for (const to of ['/customers/painel', '/customers/certificados', ...adminNav.adminPages.map(page => page.to)]) {
      const entry = entries.find(item => item.to === to)
      assert.ok(entry, to)
      assert.equal(entry.icon, undefined, to)
    }
    assert.equal(named(nodes(ordinary.render()), 'UNavigationMenu').some((node) => {
      return (node.props?.items as NavigationMenuItem[]).some(item => item.label === 'Admin')
    }), false)

    surface.route.path = '/admin/contas'
    await Vue.nextTick()
    const admin = named(view(), 'UNavigationMenu').find((node) => {
      return (node.props?.items as NavigationMenuItem[]).some(item => item.label === 'Admin')
    })!
    assert.equal(admin.props!.modelValue, 'admin')
    assert.deepEqual((admin.props!.items as NavigationMenuItem[])[0]?.children?.map(item => item.to), adminNav.adminPages.map(item => item.to))
    const adminPage = setupSurface('pages/admin.vue', '/admin/contas', { superAdmin: true })
    t.after(adminPage.close)
    const layoutPanels = named(view(), 'UDashboardPanel')
    const routedPanels = named(nodes(adminPage.render()), 'UDashboardPanel')
    assert.equal(layoutPanels.length, 0, 'The layout must not wrap Admin Accounts in a second panel')
    assert.equal(routedPanels.length, 1, 'The routed Admin shell owns exactly one panel')
    assert.equal(routedPanels[0]!.props?.id, 'admin')
    surface.route.path = '/customers/certificados'
    await Vue.nextTick()
    const menus = named(view(), 'UNavigationMenu')
    const closedAdmin = menus.find(node => (node.props!.items as NavigationMenuItem[]).some(item => item.label === 'Admin'))!
    assert.notEqual(closedAdmin.props!.modelValue, 'admin')
    assert.deepEqual(fullyOpenGroups(surface), ['Clientes'])
    closedAdmin.props!['onUpdate:modelValue']('admin')
    assert.deepEqual(fullyOpenGroups(surface), ['Admin'])
    assert.deepEqual(clientsItem(surface).children?.filter(child => child.ui?.childItem !== 'hidden').map(child => child.label), ['Meus clientes'])
    groupMenu(surface, 'Fiscal').props!['onUpdate:modelValue']('fiscal')
    assert.deepEqual(fullyOpenGroups(surface), ['Fiscal'])
  })
})

describe('Clients document selector and header', () => {
  it('uses only two compact document links on list routes, selected from the route and targeting Todos', (t) => {
    const surface = setupSurface('pages/customers.vue', listPaths[0]!)
    t.after(surface.close)
    for (const path of listPaths) {
      surface.route.path = path
      const view = nodes(surface.render())
      assert.equal(named(view, 'UDashboardNavbar')[0]!.props!.title, 'Meus clientes')
      assert.equal(named(view, 'UNavigationMenu').length, 0)
      assert.equal(named(view, 'UDashboardToolbar').length, 0)
      const selector = view.find(node => node.type === 'nav' && node.props?.['aria-label'] === 'Tipo de documento')
      assert.ok(selector, path)
      const choices = named(nodes(selector), 'UButton')
      assert.deepEqual(choices.map(node => [node.props!.label, node.props!.to]), [
        ['Certificados', '/customers/certificados'],
        ['Procurações', '/customers/procuracao']
      ])
      const selected = path.startsWith('/customers/certificados') ? 'Certificados' : 'Procurações'
      assert.deepEqual(choices.filter(node => node.props!.active).map(node => node.props!.label), [selected])
      assert.deepEqual(choices.filter(node => node.props!['aria-current'] === 'page').map(node => node.props!.label), [selected])
    }
  })

  it('hides document selection outside the lists and preserves the detail back action', (t) => {
    const surface = setupSurface('pages/customers.vue', '/customers/painel')
    t.after(surface.close)
    for (const path of ['/customers/painel', '/customers', '/customers/certificados-extra', '/customers/procuracao-extra', '/customers/empresa/42']) {
      surface.route.path = path
      const view = nodes(surface.render())
      assert.equal(view.some(node => node.type === 'nav' && node.props?.['aria-label'] === 'Tipo de documento'), false, path)
      assert.equal(named(view, 'UNavigationMenu').length, 0, path)
    }
    const view = nodes(surface.render())
    const back = named(view, 'UButton').find(node => node.props?.['aria-label'] === 'Voltar para Meus clientes')
    assert.ok(back)
    assert.equal(back.props!.to, '/customers/certificados')
    assert.equal(named(view, 'UButton').some(node => node.props?.icon === 'i-lucide-plus'), false)
  })

  it('preserves permission-gated creation and navigation-before-create on Painel', async (t) => {
    const surface = setupSurface('pages/customers.vue', '/customers/painel')
    t.after(surface.close)
    const createButton = () => named(nodes(surface.render()), 'UButton').find(node => node.props?.['aria-label'] === 'Novo cliente')
    assert.ok(createButton())
    await createButton()!.props!.onClick()
    assert.deepEqual(surface.navigations, ['/customers/certificados'])
    assert.equal(surface.createRequest.value, 1)
    surface.route.path = '/customers/procuracao/vencido'
    await createButton()!.props!.onClick()
    assert.deepEqual(surface.navigations, ['/customers/certificados'])
    assert.equal(surface.createRequest.value, 2)

    surface.canManageClients.value = false
    const view = nodes(surface.render())
    assert.equal(named(view, 'UButton').some(node => node.props?.icon === 'i-lucide-plus'), false)
    assert.ok(view.some(node => node.type === 'nav' && node.props?.['aria-label'] === 'Tipo de documento'))
  })

  it('lets the compact header wrap instead of restoring a full navigation toolbar', (t) => {
    const surface = setupSurface('pages/customers.vue', '/customers/certificados')
    t.after(surface.close)
    const navbar = named(nodes(surface.render()), 'UDashboardNavbar')[0]!
    assert.match(navbar.props!.ui?.root ?? '', /\bflex-wrap\b/)
    assert.match(navbar.props!.ui?.root ?? '', /\bh-auto\b/)
    assert.match(navbar.props!.ui?.right ?? '', /\bflex-wrap\b/)
    assert.match(navbar.props!.ui?.right ?? '', /\bmin-w-0\b/)
    assert.match(navbar.props!.ui?.right ?? '', /\bmax-w-full\b/)
    assert.match(navbar.props!.ui?.right ?? '', /\bshrink\b/)
  })
})
