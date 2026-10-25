import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
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
  app.component('UAlert', { props: ['title', 'description'], setup: (props, slotContext) => () => Vue.h('aside', { role: 'alert' }, [String(props.title ?? ''), String(props.description ?? ''), slotContext.slots.default?.()]) })
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
