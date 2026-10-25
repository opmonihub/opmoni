import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { compileVueComponent, evaluateVueBindings, vueSource } from './helpers/compileVueComponent.ts'
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
    setup: (_props, { slots }) => () => Vue.h('div', { 'data-dashboard-panel': true }, [slots.header?.(), slots.body?.()])
  }))
  app.component('UDashboardNavbar', Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('header', slots.right?.()) }))
  app.component('UDashboardSidebarCollapse', Vue.defineComponent({ setup: () => () => Vue.h('button') }))
  app.component('UDashboardToolbar', Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('div', slots.left?.()) }))
  app.component('UNavigationMenu', Vue.defineComponent({
    inheritAttrs: false,
    props: { as: String, items: Array, highlight: Boolean },
    setup: (props, { attrs }) => () => Vue.h(props.as ?? 'div', attrs)
  }))
  app.component('NuxtPage', Vue.defineComponent({ setup: () => () => Vue.h('section', { id: 'page-content' }) }))

  return renderToString(app)
}

test('commercial shell exposes a named ERP navigation landmark', async () => {
  const html = await renderCommercialShell('/gestao-erp')

  assert.match(html, /<nav[^>]*aria-label="Navegação comercial ERP"/)
})

test('commercial content is contained by a main landmark', async () => {
  const html = await renderCommercialShell('/gestao-erp')

  assert.match(html, /<main aria-label="Gestão comercial ERP"[^>]*>[\s\S]*<header title="Gestão ERP">[\s\S]*<nav[^>]*aria-label="Navegação comercial ERP"[\s\S]*<section id="page-content"><\/section>[\s\S]*<\/main>/)
})

test('invoice print route stays outside the commercial navigation shell', async () => {
  const html = await renderCommercialShell('/gestao-erp/faturas/123/imprimir')

  assert.match(html, /<section id="page-content"><\/section>/)
  assert.doesNotMatch(html, /<main[^>]*aria-label="Gestão comercial ERP"/)
  assert.doesNotMatch(html, /data-dashboard-panel/)
})

test('commercial shell tabs include all shipped pages and keep invoice descendants active', () => {
  const tabsAt = (path: string) => evaluateVueBindings(vueSource('pages/gestao-erp.vue'), ['pageTabs'], { route: { path } }).pageTabs as Array<Array<{ label: string, to: string, active: boolean }>>
  const expectedDestinations = [
    ['Visão geral', '/gestao-erp'],
    ['Ofertas', '/gestao-erp/planos'],
    ['Clientes da carteira', '/gestao-erp/clientes'],
    ['Assinaturas', '/gestao-erp/assinaturas'],
    ['Faturas', '/gestao-erp/faturas'],
    ['Recebimentos', '/gestao-erp/recebimentos'],
    ['Configurações', '/gestao-erp/configuracoes']
  ]
  const overview = tabsAt('/gestao-erp').flat()
  assert.deepEqual(overview.map(({ label, to }) => [label, to]), expectedDestinations)
  assert.deepEqual(overview.filter(item => item.active).map(item => item.label), ['Visão geral'])

  for (const path of ['/gestao-erp/faturas', '/gestao-erp/faturas/123', '/gestao-erp/faturas/123/imprimir']) {
    const activeTabs = tabsAt(path).flat().filter(item => item.active).map(item => item.label)
    assert.deepEqual(activeTabs, ['Faturas'], path)
  }
  assert.deepEqual(tabsAt('/gestao-erp/faturas-antigas').flat().filter(item => item.active).map(item => item.label), [])
  assert.deepEqual(tabsAt('/gestao-erp/recebimentos').flat().filter(item => item.active).map(item => item.label), ['Recebimentos'])
  assert.deepEqual(tabsAt('/gestao-erp/configuracoes').flat().filter(item => item.active).map(item => item.label), ['Configurações'])
})

function mountOverview(api: () => Promise<unknown>) {
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
    can_read: true, can_maintain: true, can_configure: true, can_sell_new: true,
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
  app.component('UPageCard', passthrough)
  app.component('UAlert', { props: ['title', 'description', 'color', 'variant'], setup: (props, slotContext) => () => Vue.h('aside', { role: 'alert', title: props.title, color: props.color, variant: props.variant }, [String(props.title ?? ''), String(props.description ?? ''), slotContext.slots.default?.()]) })
  app.component('UButton', { setup: (_props, slotContext) => () => Vue.h('button', { ...slotContext.attrs, onClick: slotContext.attrs.onClick }, slotContext.slots.default?.()) })
  app.mount(root)

  const text = (node: HostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
  const nodes = (node: HostNode, predicate: (candidate: HostNode) => boolean): HostNode[] => [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => nodes(child, predicate))]
  return {
    app,
    root,
    text: () => text(root),
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
    if (requests > 1) throw new Error('offline')
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
