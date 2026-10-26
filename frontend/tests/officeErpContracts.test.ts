import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }
const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_sell_new: true, capability_key: 'cap-1', allowance: { module_keys: ['purchases'] } })
const revision = { id: 1, revision_number: 1, price: '99.00', interval_months: 1, due_in_days: 10, module_keys: ['purchases'], service_period_start: '2026-10-25', service_period_end: '2026-11-24', reason: 'accepted' }
const detail = { id: 12, erp_plan_id: 7, erp_plan: { id: 7, name: 'Contábil Essencial' }, client: { id: 3, name: 'Mercado Central' }, company: { id: 4, name: 'Mercado ERP', owner_setup_status: 'unclaimed' }, trial: null, next_billing_on: '2026-11-24', agreement_status: 'effective', licence_status: 'active', current_revision: revision, pending_revision: null, service_anchor_on: '2026-10-25', next_period_month_offset: 1, cancel_effective_on: null, cancellation_reason: null, manual_hold: false, balance: { outstanding: '50.00', invoice_count: 2 }, revisions: [revision] }

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
function harness(path: string, recordData = detail, initialRecoveryKey: string | null = null, listRows: unknown[] = [recordData]) {
  const user = Vue.ref({ id: 5 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const calls: Array<{ path: string, body?: Record<string, unknown> }> = []
  const commandCalls: unknown[][] = []
  const recoveryKey = Vue.ref<string | null>(initialRecoveryKey)
  const recoveryInFlight = Vue.ref(false)
  const recoveryCalls: string[] = []
  let finishRecovery: ((result: unknown) => void) | null = null
  let recovered = false
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: `${user.value.id}:${account.value.id}` }), isCurrent: (token: { key: string }) => token.key === `${user.value.id}:${account.value.id}` }
  const bindings = {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch,
    useAccessApi: () => ({ api: async (url: string, options?: { params?: unknown, body?: Record<string, unknown> }) => {
      calls.push({ path: url, body: options?.body })
      return url.endsWith('/12') ? { data: recordData } : { data: listRows, meta: { current_page: 1, last_page: 1, per_page: 25, total: listRows.length } }
    } }),
    useAuth: () => ({ user, currentAccount: account }), useState: () => Vue.ref(1), useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.computed(() => recoveryKey.value !== null || recoveryInFlight.value),
      recoveryKey,
      execute: async (...args: unknown[]) => {
        commandCalls.push(args)
        recoveryKey.value = 'operation-key'
        return { status: 'unknown', key: 'operation-key' }
      },
      recover: (key: string) => {
        assert.equal(key, 'operation-key')
        recoveryCalls.push(key)
        recoveryInFlight.value = true
        return new Promise((resolve) => {
          finishRecovery = resolve
        })
      }
    }),
    useRoute: () => ({ params: { id: '12' } }), useToast: () => ({ add() {} }), definePageMeta() {}, refDebounced: (value: unknown) => value
  }
  const component = compileVueComponent(path, bindings)
  const button: Vue.Component = { props: ['disabled', 'loading', 'to'], setup: (props, ctx) => () => Vue.h(props.to ? 'a' : 'button', { ...ctx.attrs, disabled: props.disabled, href: props.to, onClick: ctx.attrs.onClick }, props.to ? ctx.slots.default?.() : ctx.slots.default?.()) }
  const passthrough = passThrough()
  const registered: Record<string, Vue.Component> = { UButton: button, UAlert: passthrough, UBadge: passthrough, UFormField: { setup: (_p, ctx) => () => Vue.h('label', {}, [ctx.slots.default?.(), ctx.slots.hint?.()]) }, UInput: { props: ['modelValue'], setup: (props, ctx) => () => Vue.h('input', { 'value': props.modelValue, 'aria-label': ctx.attrs['aria-label'], 'onInput': (event: Event) => ctx.emit('update:modelValue', (event.target as HTMLInputElement).value) }) }, USelect: passthrough, USelectMenu: passthrough, UTextarea: passthrough, UModal: passthrough, UTable: { props: ['data'], setup: (props, ctx) => () => Vue.h('div', {}, (props.data as unknown[]).length ? (props.data as unknown[]).map(original => Vue.h('article', {}, Object.entries(ctx.slots).filter(([name]) => name.endsWith('-cell')).map(([, slot]) => slot?.({ row: { original } })))) : ctx.slots['empty']?.()) }, UPagination: passthrough, DataTablePanelList: passthrough, NuxtLink: { setup: (_p, ctx) => () => Vue.h('a', {}, ctx.slots.default?.()) } }
  const rendered = mount(component, registered)
  return {
    ...rendered,
    calls,
    account,
    commandCalls,
    recoveryKey,
    recoveryCalls,
    finishRecovery(result: unknown) {
      recovered = true
      recoveryInFlight.value = false
      recoveryKey.value = null
      finishRecovery?.(result)
    },
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
    assert.match(text(page.root), /R\$ 99,00/)
    assert.match(text(page.root), /Contábil Essencial/)
    assert.match(text(page.root), /Próxima cobrança/)
    assert.match(text(page.root), /aguardando configuração/)
    assert.match(text(page.root), /Vigente/)
    assert.match(text(page.root), /Ativa/)
    assert.ok(page.calls.some(call => call.path === '/erp-commercial/contracts'))
  } finally {
    page.app.unmount()
  }
})

test('labels every canonical agreement state and never describes unknown states as canceled', async () => {
  const rows = ['draft', 'awaiting_owner', 'accepted', 'effective', 'canceled', 'future_state'].map((agreement_status, index) => ({
    ...detail,
    id: 20 + index,
    agreement_status
  }))
  const page = harness('pages/gestao-erp/assinaturas/index.vue', detail, null, rows)
  try {
    await settle()
    const rendered = text(page.root)
    assert.match(rendered, /Rascunho/)
    assert.match(rendered, /Aguardando confirmação do responsável/)
    assert.match(rendered, /Aceito · aguardando ativação/)
    assert.match(rendered, /Vigente/)
    assert.match(rendered, /Cancelado/)
    assert.match(rendered, /Situação não reconhecida/)
  } finally {
    page.app.unmount()
  }
})

test('shows factual trial dates without inventing a next invoice', async () => {
  const trialRecord = {
    ...detail,
    licence_status: 'trialing',
    trial: {
      id: 71,
      started_at: '2026-10-25T12:00:00Z',
      ends_at: '2026-11-01T12:00:00Z',
      converted_at: null,
      expired_at: null,
      situation: 'trialing'
    },
    next_billing_on: null
  }
  const list = harness('pages/gestao-erp/assinaturas/index.vue', detail, null, [trialRecord])
  const page = harness('pages/gestao-erp/assinaturas/[id].vue', trialRecord)
  try {
    await settle()
    assert.match(text(list.root), /Em teste/)
    assert.match(text(list.root), /Encerra 01\/11\/2026/)
    assert.doesNotMatch(text(list.root), /Próxima cobrança/)
    assert.match(text(page.root), /Teste de 7 dias/)
    assert.match(text(page.root), /Encerra 01\/11\/2026/)
    assert.doesNotMatch(text(page.root), /Próxima cobrança/)
  } finally {
    list.app.unmount()
    page.app.unmount()
  }
})

test('keeps paid subscription status and next billing visible after trial conversion', async () => {
  const rows = ['active', 'suspended', 'past_due', 'canceled'].map((licence_status, index) => ({
    ...detail,
    id: 81 + index,
    licence_status,
    trial: {
      id: 91 + index,
      started_at: '2026-10-18T12:00:00Z',
      ends_at: '2026-10-25T12:00:00Z',
      converted_at: '2026-10-25T12:00:00Z',
      expired_at: null,
      situation: 'converted'
    },
    next_billing_on: '2026-11-25'
  }))
  const page = harness('pages/gestao-erp/assinaturas/index.vue', detail, null, rows)
  const convertedActiveDetail = harness('pages/gestao-erp/assinaturas/[id].vue', {
    ...detail,
    licence_status: 'active',
    trial: rows[0]!.trial,
    next_billing_on: '2026-11-25'
  })
  const convertedCanceledDetail = harness('pages/gestao-erp/assinaturas/[id].vue', {
    ...detail,
    licence_status: 'canceled',
    trial: rows[3]!.trial,
    next_billing_on: null
  })
  try {
    await settle()
    const rendered = text(page.root)
    assert.match(rendered, /Ativa/)
    assert.match(rendered, /Suspensa/)
    assert.match(rendered, /Em atraso/)
    assert.match(rendered, /Cancelada/)
    assert.equal((rendered.match(/Próxima cobrança 25\/11\/2026/g) ?? []).length, 4)
    assert.equal((rendered.match(/Período de teste convertido: 25\/10\/2026/g) ?? []).length, 4)
    assert.match(text(convertedActiveDetail.root), /Próxima cobrança 2026-11-25/)
    assert.match(text(convertedActiveDetail.root), /Período original até/)
    assert.match(text(convertedCanceledDetail.root), /Período original até/)
    assert.doesNotMatch(text(convertedCanceledDetail.root), /Próxima cobrança/)
  } finally {
    page.app.unmount()
    convertedActiveDetail.app.unmount()
    convertedCanceledDetail.app.unmount()
  }
})

test('shows an accepted trial as operating while retaining legacy activation copy elsewhere', async () => {
  const acceptedTrial = {
    ...detail,
    agreement_status: 'accepted',
    licence_status: 'trialing',
    trial: {
      id: 71,
      started_at: '2026-10-25T12:00:00Z',
      ends_at: '2026-11-01T12:00:00Z',
      converted_at: null,
      expired_at: null,
      situation: 'trialing'
    },
    next_billing_on: null
  }
  const page = harness('pages/gestao-erp/assinaturas/[id].vue', acceptedTrial)
  try {
    await settle()
    assert.match(text(page.root), /Em teste/)
    assert.doesNotMatch(text(page.root), /Teste iniciado · aguardando ativação/)
    assert.match(text(page.root), /Teste de 7 dias/)
  } finally {
    page.app.unmount()
  }
})

test('shows an expired accepted trial as ended instead of awaiting activation', async () => {
  const expiredTrial = {
    ...detail,
    agreement_status: 'accepted',
    licence_status: 'trial_expired',
    trial: {
      id: 71,
      started_at: '2026-10-18T12:00:00Z',
      ends_at: '2026-10-25T12:00:00Z',
      converted_at: null,
      expired_at: '2026-10-25T12:00:00Z',
      situation: 'expired'
    },
    next_billing_on: null
  }
  const page = harness('pages/gestao-erp/assinaturas/[id].vue', expiredTrial)
  try {
    await settle()
    assert.match(text(page.root), /Teste encerrado/)
    assert.doesNotMatch(text(page.root), /Aceito · aguardando ativação/)
  } finally {
    page.app.unmount()
  }
})

test('renders the contract detail SFC, dispatches lifecycle actions, and recovers unknown outcomes', async () => {
  const page = harness('pages/gestao-erp/assinaturas/[id].vue')
  try {
    await settle()
    assert.match(text(page.root), /Mercado Central/)
    assert.match(text(page.root), /Contábil Essencial/)
    assert.match(text(page.root), /Aguardando configuração/)
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
    ;(recover.props.onClick as () => void)()
    assert.equal(page.recoveryCalls.length, 1)
    page.finishRecovery({ status: 'confirmed', data: {} })
    await settle()
    assert.equal(page.recovered, true)
  } finally {
    page.app.unmount()
  }
})

test('sends amendments as PATCH with canonical money and retains fields for recovery', async () => {
  const page = harness('pages/gestao-erp/assinaturas/[id].vue')
  try {
    await settle()
    const price = nodes(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Preço do contrato')[0]
    assert.ok(price)
    ;(price.props.onInput as (event: Event) => void)({ target: { value: '9.007.199.254.740.993,01' } } as unknown as Event)
    await settle()
    const amend = nodes(page.root, node => node.tag === 'button' && text(node).includes('Agendar alteração'))[0]
    assert.ok(amend)
    ;(amend.props.onClick as () => void)()
    await settle()
    const args = page.commandCalls[0]!
    assert.equal(args[0], 'contract.amend')
    assert.equal(args[3], 'PATCH')
    assert.deepEqual(args[2], { price: '9007199254740993.01', interval_months: 1, due_in_days: 10, module_keys: ['purchases'] })
    assert.match(text(page.root), /Recuperar operação/)
    assert.equal((nodes(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Preço do contrato')[0].props.value), '9.007.199.254.740.993,01')
  } finally {
    page.app.unmount()
  }
})

test('keeps authorized maintenance controls visible with pending terms and period-end cancellation', async () => {
  const pendingRecord = {
    ...detail,
    pending_revision: { ...revision, id: 2, revision_number: 2, service_period_start: '2026-11-25', reason: 'amendment' },
    cancel_effective_on: '2026-11-25',
    cancellation_reason: 'Encerramento solicitado'
  }
  const page = harness('pages/gestao-erp/assinaturas/[id].vue', pendingRecord)
  try {
    await settle()
    assert.ok(nodes(page.root, node => node.tag === 'button' && text(node).includes('Suspender manualmente'))[0])
    assert.ok(nodes(page.root, node => node.tag === 'button' && text(node).includes('Cancelar contrato'))[0])
  } finally {
    page.app.unmount()
  }
})

test('discards recovery success after the selected Account changes', async () => {
  const page = harness('pages/gestao-erp/assinaturas/[id].vue')
  try {
    await settle()
    const renew = nodes(page.root, node => node.tag === 'button' && text(node).includes('Renovar sem mudar termos'))[0]
    assert.ok(renew)
    ;(renew.props.onClick as () => void)()
    await settle()
    const recover = nodes(page.root, node => node.tag === 'button' && text(node).includes('Recuperar operação'))[0]
    assert.ok(recover)
    ;(recover.props.onClick as () => void)()
    await settle()
    page.account.value.id = 9
    await settle()
    const readsAfterSwitch = page.calls.filter(call => call.path === '/erp-commercial/contracts/12').length
    page.finishRecovery({ status: 'confirmed', data: {} })
    await settle()
    assert.equal(page.calls.filter(call => call.path === '/erp-commercial/contracts/12').length, readsAfterSwitch)
  } finally {
    page.app.unmount()
  }
})

test('shows restored recovery from the command key without a prior mutation error', async () => {
  const page = harness('pages/gestao-erp/assinaturas/[id].vue', detail, 'operation-key')
  try {
    await settle()
    assert.match(text(page.root), /Recuperar operação/)
    const recover = nodes(page.root, node => node.tag === 'button' && text(node).includes('Recuperar operação'))[0]
    assert.ok(recover)
    ;(recover.props.onClick as () => void)()
    await settle()
    assert.deepEqual(page.recoveryCalls, ['operation-key'])
    page.finishRecovery({ status: 'confirmed', data: {} })
    await settle()
    assert.equal(page.recovered, true)
  } finally {
    page.app.unmount()
  }
})
