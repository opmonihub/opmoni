import { registerOfficeErpPresentationStubs } from './helpers/officeErpPresentationStubs.ts'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { compileVueComponent, vueSource } from './helpers/compileVueComponent.ts'
import { officeErpSidebarItem } from '../app/utils/sidebarNav.ts'
import { formatOfficeErpMoney } from '../shared/utils/officeErpCommercial.ts'

type HostNode = { tag?: string, text?: string, props: Record<string, unknown>, children: HostNode[], parent?: HostNode | null }

const report = {
  contracted_licences: 3,
  reserved_licences: 1,
  invoiced_movement: '9007199254740993.01',
  effective_received: '20.00',
  outstanding: '70.00',
  overdue: '30.00',
  from_on: '2026-10-01',
  to_on: '2026-10-31',
  due_from: null,
  due_to: null
}

async function renderCommercialShell(path: string): Promise<string> {
  const shell = compileVueComponent('pages/gestao-erp.vue', {
    useRoute: () => ({ path }),
    useAuth: () => ({ currentAccount: Vue.ref({ name: 'Escritório exemplo' }) }),
    definePageMeta() {}
  })
  const app = Vue.createSSRApp(shell)
  app.component('UDashboardPanel', Vue.defineComponent({
    props: ['ui'],
    setup: (props, { slots }) => () => Vue.h('div', { 'data-dashboard-panel': true }, [
      slots.header?.(), Vue.h('div', { class: (props.ui as { body?: string })?.body }, slots.body?.())
    ])
  }))
  app.component('UDashboardNavbar', Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('header', slots.right?.()) }))
  app.component('UDashboardSidebarCollapse', Vue.defineComponent({ setup: () => () => Vue.h('button') }))
  app.component('UDashboardToolbar', Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('div', { 'data-dashboard-toolbar': true }, [slots.left?.(), slots.right?.()]) }))
  app.component('UTooltip', Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('div', slots.default?.()) }))
  app.component('USelect', Vue.defineComponent({
    props: ['modelValue', 'items'],
    setup: props => () => Vue.h('select', (props.items as Array<{ label: string, value: string }>).map(item => Vue.h('option', {
      value: item.value, selected: item.value === props.modelValue
    }, item.label)))
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['to', 'active'],
    setup: (props, { attrs, slots }) => () => Vue.h('a', {
      ...attrs, href: props.to
    }, slots.default?.())
  }))
  app.component('UNavigationMenu', Vue.defineComponent({
    inheritAttrs: false,
    props: { as: String, items: Array, highlight: Boolean },
    setup: (props, { attrs }) => () => Vue.h(props.as ?? 'div', attrs, (props.items as Array<Array<{ label: string, to: string, active: boolean }>>).flat().map(item => Vue.h('a', {
      'href': item.to, 'aria-current': item.active ? 'page' : undefined
    }, item.label)))
  }))
  app.component('NuxtPage', Vue.defineComponent({ setup: () => () => Vue.h('section', { id: 'page-content' }) }))

  return renderToString(app)
}

test('commercial shell keeps enrollment and content without duplicating sidebar navigation', async () => {
  for (const path of ['/gestao-erp', '/gestao-erp/planos', '/gestao-erp/assinaturas/empresas/41', '/gestao-erp/faturas/123', '/gestao-erp/recebimentos', '/gestao-erp/configuracoes']) {
    const html = await renderCommercialShell(path)
    assert.match(html, /<header title="Gestão ERP">/)
    assert.match(html, /href="\/gestao-erp\/empresas\/nova"[^>]*>\s*Nova empresa\s*<\/a>/)
    assert.match(html, /<section id="page-content"><\/section>/)
    assert.doesNotMatch(html, /data-dashboard-toolbar|<nav|<select/, path)
    assert.doesNotMatch(html, /Configurações da gestão ERP/, path)
  }
})

test('sidebar remains the single source for financial and settings destinations', () => {
  for (const [path, expected] of [
    ['/gestao-erp/faturas/123', 'Financeiro'],
    ['/gestao-erp/recebimentos', 'Financeiro'],
    ['/gestao-erp/configuracoes', 'Configurações'],
    ['/gestao-erp/assinaturas/empresas/41', 'Assinaturas']
  ]) {
    const item = officeErpSidebarItem(path!)
    assert.equal(item.active, true)
    assert.equal(item.defaultOpen, true)
    assert.deepEqual(item.children.filter(child => child.active).map(child => child.label), [expected])
  }
})

test('unrelated route prefixes do not activate the financial or settings navigation', async () => {
  for (const path of ['/gestao-erp/faturas-antigas', '/gestao-erp/recebimentos-antigos', '/gestao-erp/configuracoes-antigas']) {
    const html = await renderCommercialShell(path)
    assert.doesNotMatch(html, /aria-label="Navegação financeira ERP"/, path)
    assert.doesNotMatch(html, /aria-current="page"/, path)
  }
})

test('commercial content is contained by a main landmark', async () => {
  const html = await renderCommercialShell('/gestao-erp')

  assert.match(html, /<main aria-label="Gestão comercial ERP"[^>]*>[\s\S]*<header title="Gestão ERP">[\s\S]*<section id="page-content"><\/section>[\s\S]*<\/main>/)
})

test('overview uses the full dashboard panel measure without widening sibling list pages', async () => {
  const overview = await renderCommercialShell('/gestao-erp')
  assert.match(overview, /<div class="w-full min-w-0"><section id="page-content"><\/section><\/div>/)
  assert.doesNotMatch(overview, /max-w-5xl|lg:p-8/)
  const list = await renderCommercialShell('/gestao-erp/empresas')
  assert.match(list, /class="mx-auto w-full max-w-5xl"/)
})

test('invoice print route stays outside the commercial navigation shell', async () => {
  const html = await renderCommercialShell('/gestao-erp/faturas/123/imprimir')

  assert.match(html, /<section id="page-content"><\/section>/)
  assert.doesNotMatch(html, /<main[^>]*aria-label="Gestão comercial ERP"/)
  assert.doesNotMatch(html, /data-dashboard-panel/)
})

test('commercial sidebar exposes plans with the other primary destinations and exact active routes', async () => {
  const html = await renderCommercialShell('/gestao-erp')
  assert.doesNotMatch(html, /Escritório exemplo/)
  const destinationsAt = (path: string) => officeErpSidebarItem(path).children
  const expectedDestinations = [
    ['Visão geral', '/gestao-erp'],
    ['Empresas', '/gestao-erp/empresas'],
    ['Assinaturas', '/gestao-erp/assinaturas'],
    ['Financeiro', '/gestao-erp/faturas'],
    ['Configurações', '/gestao-erp/configuracoes']
  ]
  const overview = destinationsAt('/gestao-erp').flat()
  assert.deepEqual(overview.map(({ label, to }) => [label, to]), expectedDestinations)
  assert.deepEqual(destinationsAt('/gestao-erp/planos').flat().filter(item => item.active).map(item => item.label), ['Configurações'])
  assert.deepEqual(destinationsAt('/gestao-erp/planos-antigos').flat().filter(item => item.active).map(item => item.label), [])
  assert.deepEqual(overview.filter(item => item.active).map(item => item.label), ['Visão geral'])

  for (const path of ['/gestao-erp/faturas', '/gestao-erp/faturas/123', '/gestao-erp/faturas/123/imprimir']) {
    const activeTabs = destinationsAt(path).flat().filter(item => item.active).map(item => item.label)
    assert.deepEqual(activeTabs, ['Financeiro'], path)
  }
  assert.deepEqual(destinationsAt('/gestao-erp/faturas-antigas').flat().filter(item => item.active).map(item => item.label), [])
  assert.deepEqual(destinationsAt('/gestao-erp/recebimentos').flat().filter(item => item.active).map(item => item.label), ['Financeiro'])
  assert.deepEqual(destinationsAt('/gestao-erp/configuracoes').flat().filter(item => item.active).map(item => item.label), ['Configurações'])
  assert.deepEqual(destinationsAt('/gestao-erp/empresas').flat().filter(item => item.active).map(item => item.label), ['Empresas'])
})

test('legacy offers page remains directly routable', () => {
  const source = vueSource('pages/gestao-erp/planos.vue')
  assert.match(source, /definePageMeta/)
})

function mountOverview(api: (path: string) => Promise<unknown>, options: { canRead?: boolean } = {}) {
  const root: HostNode = { props: {}, children: [] }
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    createElement: tag => ({ tag, props: {}, children: [], parent: null }),
    createText: text => ({ text, props: {}, children: [], parent: null }),
    createComment: text => ({ text, props: {}, children: [], parent: null }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => {
      node.text = text
      node.children = []
    },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert: (node, parent, anchor) => {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove: (node) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    parentNode: node => node.parent ?? null,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const user = Vue.ref({ id: 7 })
  const account = Vue.ref({ id: 9, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({
    can_read: options.canRead ?? true, can_maintain: true, can_configure: true, can_sell_new: true,
    capability_key: 'cap-1', reasons: {}, allowance: { enabled: true, licence_limit: 10, module_keys: [] }, usage: { reserved: 1, occupied: 3 }
  })
  let generation = 0
  const context = {
    capabilities,
    refresh: async () => {},
    capture: () => ({ key: `${user.value.id}:${account.value.id}:${capabilities.value.capability_key}`, generation }),
    isCurrent: (token: { key: string, generation: number }) => token.generation === generation && token.key === `${user.value.id}:${account.value.id}:${capabilities.value.capability_key}`
  }
  const component = compileVueComponent('pages/gestao-erp/index.vue', {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    onMounted: Vue.onMounted,
    definePageMeta() {},
    useAuth: () => ({ user, currentAccount: account }),
    useState: () => Vue.ref(0),
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    formatOfficeErpMoney
  }, {
    '#shared/utils/officeErpCommercial': { formatOfficeErpMoney }
  })
  const app = renderer.createApp(component)
  const passthrough: Vue.Component = { setup: (_props, slotContext) => () => Vue.h('section', {}, [slotContext.slots.default?.(), slotContext.slots.description?.(), slotContext.slots.footer?.()]) }
  app.component('UPageGrid', passthrough)
  app.component('UPageCard', {
    props: ['as', 'title', 'description', 'icon'],
    setup: (props, { attrs, slots }) => () => Vue.h(props.as ?? 'div', attrs, [
      slots.title?.() ?? (props.title ? Vue.h('h2', props.title) : null),
      props.description ? Vue.h('p', props.description) : null,
      slots.default?.()
    ])
  })
  app.component('UCard', {
    props: ['as'],
    setup: (props, { attrs, slots }) => () => Vue.h(props.as ?? 'div', attrs, [slots.header?.(), slots.default?.(), slots.footer?.()])
  })
  app.component('USkeleton', { setup: () => () => Vue.h('div', { 'data-skeleton': true }) })
  app.component('UAlert', {
    props: ['title', 'description', 'color', 'variant'],
    setup: (props, { slots }) => () => Vue.h('aside', {
      role: 'alert', title: props.title, color: props.color, variant: props.variant
    }, [String(props.title ?? ''), slots.description?.() ?? String(props.description ?? ''), slots.default?.()])
  })
  app.component('UButton', {
    props: ['to', 'disabled'],
    setup: (props, { attrs, slots }) => () => Vue.h(props.to ? 'a' : 'button', {
      ...attrs, href: props.to, disabled: props.disabled
    }, slots.default?.())
  })
  registerOfficeErpPresentationStubs(app)
  app.mount(root)

  const text = (node: HostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
  const nodes = (node: HostNode, predicate: (candidate: HostNode) => boolean): HostNode[] => [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => nodes(child, predicate))]
  return {
    app,
    root,
    text: () => text(root),
    nodeText: text,
    nodes,
    setGeneration: (value: number) => {
      generation = value
    }
  }
}

async function settle() {
  for (let index = 0; index < 5; index++) {
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
  }
}

test('overview groups exact financial values and licence counts into two compact semantic blocks', async (t) => {
  const requests: string[] = []
  const page = mountOverview(async (path) => {
    requests.push(path)
    return { data: report }
  })
  t.after(() => page.app.unmount())
  await settle()

  const cards = page.nodes(page.root, node => node.tag === 'article')
  assert.equal(cards.length, 2)
  assert.deepEqual(cards.map(card => page.nodes(card, node => node.tag === 'h3').map(node => page.nodeText(node).trim())), [['Financeiro'], ['Licenças']])
  const financialRows = page.nodes(cards[0]!, node => node.tag === 'dt').map(node => [page.nodeText(node).trim(), page.nodeText(node.parent!.children.find(child => child.tag === 'dd')!).trim()])
  const licenceRows = page.nodes(cards[1]!, node => node.tag === 'dt').map(node => [page.nodeText(node).trim(), page.nodeText(node.parent!.children.find(child => child.tag === 'dd')!).trim()])
  assert.deepEqual(financialRows, [
    ['Faturado', 'R$ 9.007.199.254.740.993,01'],
    ['Recebido', 'R$ 20,00'],
    ['Em aberto', 'R$ 70,00'],
    ['Vencido', 'R$ 30,00']
  ])
  assert.deepEqual(licenceRows, [['Contratadas', '3'], ['Reservas pendentes', '1']])
  const strip = page.nodes(cards[0]!, node => node.tag === 'div' && String(node.props.class).includes('lg:grid-cols-2'))[0]
  assert.ok(strip, 'financial groups form a connected desktop KPI strip')
  assert.ok(String(strip.props.class).includes('divide-y'))
  assert.ok(String(strip.props.class).includes('lg:divide-x'))
  const metricValues = page.nodes(cards[0]!, node => node.tag === 'dd')
  assert.equal(metricValues.length, 4)
  for (const value of metricValues) {
    assert.match(String(value.props.class), /text-xl/)
    assert.match(String(value.props.class), /wrap-anywhere/)
    assert.doesNotMatch(String(value.props.class), /truncate|whitespace-nowrap/)
  }
  const supportingLicences = cards[1]!
  assert.ok(page.nodes(supportingLicences, node => String(node.props.class).includes('sm:grid-cols-2')).length > 0)
  assert.doesNotMatch(page.text(), /Dados de demonstração|%|crescimento|evolução/i)
  const movementGroup = page.nodes(cards[0]!, node => node.tag === 'section' && page.nodes(node, child => child.tag === 'dt' && page.nodeText(child).trim() === 'Faturado').length > 0)[0]
  const balanceGroup = page.nodes(cards[0]!, node => node.tag === 'section' && page.nodes(node, child => child.tag === 'dt' && page.nodeText(child).trim() === 'Em aberto').length > 0)[0]
  assert.ok(movementGroup)
  assert.ok(balanceGroup)
  assert.notEqual(movementGroup, balanceGroup)
  assert.match(page.nodeText(movementGroup), /01\/10\/2026 a 31\/10\/2026/)
  assert.match(page.nodeText(balanceGroup), /Saldos atuais/)
  assert.match(page.nodeText(balanceGroup), /Todos os vencimentos/)
  assert.doesNotMatch(page.nodeText(balanceGroup), /01\/10\/2026|31\/10\/2026/)
  assert.equal((page.text().match(/01\/10\/2026 a 31\/10\/2026/g) ?? []).length, 1)
  assert.deepEqual(requests, ['/erp-commercial/summary'])
  assert.equal(page.nodes(page.root, node => node.tag === 'svg' || node.props.role === 'img').length, 0)
})

test('overview removes duplicate shortcuts while retaining the refresh action and global enrollment', async (t) => {
  let requests = 0
  const page = mountOverview(async () => {
    requests++
    return { data: report }
  })
  t.after(() => page.app.unmount())
  await settle()

  assert.equal(page.nodes(page.root, node => node.tag === 'a').length, 0)
  assert.doesNotMatch(page.text(), /Acessos rápidos|Empresas do escritório|Nova empresa|Registre uma empresa|Empresas, assinaturas e cobranças/)
  const refresh = page.nodes(page.root, node => node.tag === 'button' && page.nodeText(node).trim() === 'Atualizar')[0]
  assert.ok(refresh)
  ;(refresh.props.onClick as () => void)()
  await settle()
  assert.equal(requests, 2)
  assert.match(await renderCommercialShell('/gestao-erp'), /href="\/gestao-erp\/empresas\/nova"/)
})

test('overview discloses the actual due date criteria only with current balances', async (t) => {
  for (const [dueFrom, dueTo, expected] of [
    ['2026-11-01', '2026-11-30', 'Vencimentos de 01/11/2026 a 30/11/2026'],
    ['2026-11-01', null, 'Vencimentos a partir de 01/11/2026'],
    [null, '2026-11-30', 'Vencimentos até 30/11/2026']
  ] as const) {
    const page = mountOverview(async () => ({ data: { ...report, due_from: dueFrom, due_to: dueTo } }))
    t.after(() => page.app.unmount())
    await settle()
    const financial = page.nodes(page.root, node => node.tag === 'article')[0]
    assert.ok(financial)
    const balanceGroup = page.nodes(financial, node => node.tag === 'section' && page.nodeText(node).includes('Saldos atuais'))[0]
    assert.ok(balanceGroup)
    assert.ok(page.nodeText(balanceGroup).includes(expected), expected)
    assert.doesNotMatch(page.nodeText(balanceGroup), /01\/10\/2026 a 31\/10\/2026/)
  }
})

test('initial loading uses two placeholders without inventing financial or licence values', async (t) => {
  const page = mountOverview(() => new Promise(() => {}))
  t.after(() => page.app.unmount())
  await settle()

  const loading = page.nodes(page.root, node => node.props['aria-label'] === 'Carregando resumo comercial')[0]
  assert.ok(loading)
  assert.equal(String(loading.props['aria-busy']), 'true')
  assert.equal(page.nodes(loading, node => node.props['data-skeleton'] === true).length, 2)
  assert.equal(page.nodes(page.root, node => node.tag === 'article').length, 0)
  assert.doesNotMatch(page.text(), /R\$|Contratadas|Reservas pendentes|Ainda não há movimentação/)
})

test('denied summary access does not request data or display zero-value blocks', async (t) => {
  const requests: string[] = []
  const page = mountOverview(async (path) => {
    requests.push(path)
    return { data: report }
  }, { canRead: false })
  t.after(() => page.app.unmount())
  await settle()

  assert.match(page.text(), /Resumo indisponível/)
  assert.match(page.text(), /não tem autorização/)
  assert.deepEqual(requests, [])
  assert.equal(page.nodes(page.root, node => node.tag === 'article').length, 0)
  assert.doesNotMatch(page.text(), /R\$|Ainda não há movimentação/)
})

test('initial report failure shows an error instead of fabricated zero metrics', async (t) => {
  const page = mountOverview(async () => {
    throw new Error('offline')
  })
  t.after(() => page.app.unmount())
  await settle()
  assert.match(page.text(), /Não foi possível carregar o resumo/)
  assert.doesNotMatch(page.text(), /R\$\s*0,00/)
})

test('refresh failure keeps prior report values explicitly marked stale', async (t) => {
  let requests = 0
  const page = mountOverview(async () => {
    requests++
    if (requests > 1) throw { statusCode: 503, data: { message: 'Serviço temporariamente indisponível.' } }
    return { data: report }
  })
  t.after(() => page.app.unmount())
  await settle()
  assert.match(page.text(), /R\$\s*9\.007\.199\.254\.740\.993,01/)
  const refresh = page.nodes(page.root, node => node.tag === 'button' && page.text().includes('Atualizar'))[0]
  assert.ok(refresh)
  ;(refresh.props.onClick as () => void)()
  await settle()
  assert.match(page.text(), /Dados desatualizados/)
  assert.match(page.text(), /R\$\s*9\.007\.199\.254\.740\.993,01/)
})

test('authorization and account-context failures remove the previously loaded report', async (t) => {
  for (const statusCode of [401, 403, 409]) {
    let requests = 0
    const page = mountOverview(async () => {
      requests++
      if (requests > 1) throw {
        statusCode,
        data: {
          code: statusCode === 409 ? 'account_context_changed' : 'authorization_denied',
          message: 'A autorização do contexto mudou.'
        }
      }
      return { data: report }
    })
    t.after(() => page.app.unmount())
    await settle()

    const refresh = page.nodes(page.root, node => node.tag === 'button' && page.text().includes('Atualizar'))[0]
    assert.ok(refresh)
    ;(refresh.props.onClick as () => void)()
    await settle()

    assert.match(page.text(), /Não foi possível carregar o resumo/, `status ${statusCode}`)
    assert.match(page.text(), /A autorização do contexto mudou\./, `status ${statusCode}`)
    assert.doesNotMatch(page.text(), /R\$\s*9\.007\.199\.254\.740\.993,01/, `status ${statusCode}`)
    assert.doesNotMatch(page.text(), /Dados desatualizados/, `status ${statusCode}`)
  }
})

test('empty overview uses a theme-aware neutral alert without clipping its root', async (t) => {
  const page = mountOverview(async () => ({
    data: {
      ...report,
      contracted_licences: 0,
      reserved_licences: 0,
      invoiced_movement: '0.00',
      effective_received: '0.00',
      outstanding: '0.00',
      overdue: '0.00'
    }
  }))
  t.after(() => page.app.unmount())
  await settle()

  const [alert] = page.nodes(page.root, node => node.tag === 'aside' && node.props.title === 'Ainda não há movimentação comercial')
  assert.ok(alert, 'the empty-state alert is rendered')
  assert.equal(alert.props.color, 'neutral')
  assert.equal(alert.props.variant, 'subtle')

  let root: HostNode | null | undefined = alert
  while (root && !(root.tag === 'div' && typeof root.props.class === 'string' && root.props.class.includes('shrink-0'))) {
    root = root.parent
  }
  assert.ok(root, 'the overview root does not flex-shrink its content')
  assert.doesNotMatch(String(root.props.class), /overflow-hidden/)
})
