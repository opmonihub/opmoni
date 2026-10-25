import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }
const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_sell_new: true, capability_key: 'cap-1', allowance: { module_keys: ['purchases'] } })
const revision = { id: 1, revision_number: 1, price: '99.00', interval_months: 1, due_in_days: 10, module_keys: ['purchases'], service_period_start: '2026-10-25', service_period_end: '2026-11-24', reason: 'accepted' }
const detail = { id: 12, client: { id: 3, name: 'Mercado Central' }, company: { id: 4, name: 'Mercado ERP' }, agreement_status: 'effective', licence_status: 'active', current_revision: revision, pending_revision: null, service_anchor_on: '2026-10-25', next_period_month_offset: 1, cancel_effective_on: null, cancellation_reason: null, manual_hold: false, balance: { outstanding: '50.00', invoice_count: 2 }, revisions: [revision] }

function nodes(node: Node, predicate: (candidate: Node) => boolean): Node[] {
  return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => nodes(child, predicate))]
}
function text(node: Node): string {
  return `${node.text ?? ''}${node.children.map(text).join('')}`
}
function mount(component: Vue.Component, registered: Record<string, Vue.Component>) {
  const root: Node = { props: {}, children: [] }
  const renderer = Vue.createRenderer({
    patchProp(node: Node, key, _old, next) { node.props[key] = next },
    insert(node: Node, parent: Node, anchor?: Node) {
      node.parent = parent
      const i = anchor ? parent.children.indexOf(anchor) : -1
      if (i < 0)
        parent.children.push(node)
      else
        parent.children.splice(i, 0, node)
    },
    remove(node: Node) { if (node.parent) parentRemove(node) },
    createElement(tag) { return { tag, props: {}, children: [], parent: null } },
    createText(value) { return { text: value, props: {}, children: [], parent: null } },
    createComment(value) { return { text: value, props: {}, children: [], parent: null } },
    setText(node: Node, value) { node.text = value },
    setElementText(node: Node, value) {
      node.text = value
      node.children = []
    },
    parentNode(node: Node) { return node.parent ?? null },
    nextSibling(node: Node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(component)
  Object.entries(registered).forEach(([name, value]) => app.component(name, value))
  app.mount(root)
  return { app, root }
}
function parentRemove(node: Node) {
  const siblings = node.parent!.children
  const index = siblings.indexOf(node)
  if (index >= 0)
    siblings.splice(index, 1)
}
function passThrough(): Vue.Component {
  return { setup: (_props, ctx) => () => Vue.h('section', {}, [ctx.slots.default?.(), ctx.slots.body?.(), ctx.slots.footer?.(), ctx.slots.description?.(), ctx.slots['empty']?.()]) }
}
function harness(path: string) {
  const user = Vue.ref({ id: 5 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const calls: Array<{ path: string, body?: Record<string, unknown> }> = []
  const commandCalls: unknown[][] = []
  const recoveryKey = Vue.ref<string | null>(null)
  let recovered = false
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: `${user.value.id}:${account.value.id}` }), isCurrent: (token: { key: string }) => token.key === `${user.value.id}:${account.value.id}` }
  const bindings = {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch,
    useAccessApi: () => ({ api: async (url: string, options?: { params?: unknown, body?: Record<string, unknown> }) => {
      calls.push({ path: url, body: options?.body })
      return url.endsWith('/12') ? { data: detail } : { data: [detail], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    } }),
    useAuth: () => ({ user, currentAccount: account }), useState: () => Vue.ref(1), useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey,
      execute: async (...args: unknown[]) => {
        commandCalls.push(args)
        recoveryKey.value = 'operation-key'
        return { status: 'unknown', key: 'operation-key' }
      },
      recover: async (key: string) => {
        assert.equal(key, 'operation-key')
        recovered = true
        recoveryKey.value = null
        return { status: 'confirmed', data: {} }
      }
    }),
    useRoute: () => ({ params: { id: '12' } }), useToast: () => ({ add() {} }), definePageMeta() {}, refDebounced: (value: unknown) => value
  }
  const component = compileVueComponent(path, bindings)
  const button: Vue.Component = { props: ['disabled', 'loading', 'to'], setup: (props, ctx) => () => Vue.h(props.to ? 'a' : 'button', { ...ctx.attrs, disabled: props.disabled, href: props.to, onClick: ctx.attrs.onClick }, props.to ? ctx.slots.default?.() : ctx.slots.default?.()) }
  const passthrough = passThrough()
  const registered: Record<string, Vue.Component> = { UButton: button, UAlert: passthrough, UBadge: passthrough, UFormField: { setup: (_p, ctx) => () => Vue.h('label', {}, [ctx.slots.default?.(), ctx.slots.hint?.()]) }, UInput: { props: ['modelValue'], setup: (_p, ctx) => () => Vue.h('input', { onInput: ctx.attrs.onInput }) }, USelect: passthrough, USelectMenu: passthrough, UTextarea: passthrough, UModal: passthrough, UTable: { props: ['data'], setup: (props, ctx) => () => Vue.h('div', {}, (props.data as unknown[]).length ? (props.data as unknown[]).map(original => Vue.h('article', {}, Object.entries(ctx.slots).filter(([name]) => name.endsWith('-cell')).map(([, slot]) => slot?.({ row: { original } })))) : ctx.slots['empty']?.()) }, UPagination: passthrough, DataTablePanelList: passthrough, NuxtLink: { setup: (_p, ctx) => () => Vue.h('a', {}, ctx.slots.default?.()) } }
  const rendered = mount(component, registered)
  return {
    ...rendered,
    calls,
    commandCalls,
    recoveryKey,
    get recovered() {
      return recovered
    }
  }
}
async function settle() {
  for (let i = 0; i < 5; i++) {
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
  }
}

test('renders the contracts list SFC and keeps agreement and licence statuses distinct', async () => {
  const page = harness('pages/gestao-erp/assinaturas/index.vue')
  try {
    await settle()
    assert.match(text(page.root), /Mercado Central/)
    assert.match(text(page.root), /Vigente/)
    assert.match(text(page.root), /Ativa/)
    assert.ok(page.calls.some(call => call.path === '/erp-commercial/contracts'))
  } finally {
    page.app.unmount()
  }
})

test('renders the contract detail SFC, dispatches lifecycle actions, and recovers unknown outcomes', async () => {
  const page = harness('pages/gestao-erp/assinaturas/[id].vue')
  try {
    await settle()
    assert.match(text(page.root), /Mercado Central/)
    assert.match(text(page.root), /Saldo em aberto/)
    const renew = nodes(page.root, node => node.tag === 'button' && text(node).includes('Renovar sem mudar termos'))[0]
    assert.ok(renew)
    ;(renew.props.onClick as () => void)()
    await settle()
    assert.equal((page.commandCalls[0]?.[0]), 'contract.renew')
    assert.match(text(page.root), /Recuperar operação/)
    const recover = nodes(page.root, node => node.tag === 'button' && text(node).includes('Recuperar operação'))[0]
    assert.ok(recover)
    ;(recover.props.onClick as () => void)()
    await settle()
    assert.equal(page.recovered, true)
  } finally { page.app.unmount() }
})
