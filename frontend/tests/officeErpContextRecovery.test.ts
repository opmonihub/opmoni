import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type Account = { id: number, status: 'active' | 'suspended', role: 'admin' | 'operador' | 'user' }
type Identity = { id: number, current_account: Account | null }
type Capabilities = {
  can_read: boolean
  can_maintain: boolean
  can_configure: boolean
  can_sell_new: boolean
  can_provision: boolean
  can_start_trial: boolean
  can_manage_trial_link: boolean
  capability_key: string
  capability_reasons: Record<'can_configure' | 'can_sell_new' | 'can_provision' | 'can_start_trial' | 'can_manage_trial_link', string[]>
  reasons: Record<string, string[]>
  allowance: { enabled: boolean, licence_limit: number | null, module_keys: string[] }
  usage: { reserved: number, occupied: number }
}
type Result<T>
  = | { status: 'confirmed', data: T }
    | { status: 'unknown', key: string }
    | { status: 'failed', failure: { code: string, message: string, http_status?: number } }
    | { status: 'stale' }
type Command = {
  pending: Vue.Ref<boolean>
  recoveryKey: Vue.Ref<string | null>
  execute<T>(action: string, path: string, body: Record<string, unknown>): Promise<Result<T>>
  recover<T>(key: string): Promise<Result<T>>
}
type ApiCall = { path: string, init?: Record<string, unknown> }
type Shared = {
  identity: Vue.Ref<Identity>
  session: Vue.Ref<number>
  states: Map<string, Vue.Ref<unknown>>
  storage: Map<string, string>
  capabilities: Vue.Ref<Capabilities | null>
  calls: ApiCall[]
  uuid: number
}
type Harness = ReturnType<typeof mountComposables>

const fullCapabilities = (capabilityKey: string): Capabilities => ({
  can_read: true,
  can_maintain: true,
  can_configure: true,
  can_sell_new: true,
  can_provision: true,
  can_start_trial: false,
  can_manage_trial_link: true,
  capability_key: capabilityKey,
  capability_reasons: {
    can_configure: [],
    can_sell_new: [],
    can_provision: [],
    can_start_trial: [],
    can_manage_trial_link: []
  },
  reasons: {},
  allowance: { enabled: true, licence_limit: 8, module_keys: ['purchases'] },
  usage: { reserved: 0, occupied: 2 }
})

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

function newShared(): Shared {
  return {
    identity: Vue.ref({ id: 41, current_account: { id: 8, status: 'active', role: 'admin' } }),
    session: Vue.ref(3),
    states: new Map(),
    storage: new Map(),
    capabilities: Vue.ref(fullCapabilities('cap-1')),
    calls: [],
    uuid: 0
  }
}

function mountComposables(shared = newShared(), apiOverride?: (path: string, init?: Record<string, unknown>) => Promise<unknown>) {
  const currentAccount = Vue.computed(() => shared.identity.value.current_account)
  const api = async (path: string, init?: Record<string, unknown>) => {
    shared.calls.push({ path, init })
    if (apiOverride) return apiOverride(path, init)
    if (path === '/erp-commercial/context') return { data: shared.capabilities.value }
    if (path.startsWith('/erp-commercial/operations/')) return { data: { id: 72 } }
    if (path === '/erp-commercial/plans' && init?.method === 'POST') return { data: { id: 72 } }
    if (path === '/erp-commercial/plans') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    return { data: {} }
  }
  const bindings = {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    readonly: Vue.readonly,
    onScopeDispose: Vue.onScopeDispose,
    useNuxtApp: () => ({}),
    useState: <T>(key: string, initial: () => T) => {
      if (key === 'access-session-generation') return shared.session as Vue.Ref<T>
      if (!shared.states.has(key)) shared.states.set(key, Vue.ref(initial()))
      return shared.states.get(key) as Vue.Ref<T>
    },
    useAuth: () => ({ user: shared.identity, currentAccount }),
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    crypto: { randomUUID: () => `7cbbdbb6-7f88-4479-a78a-${String(++shared.uuid).padStart(12, '0')}` },
    sessionStorage: {
      getItem: (key: string) => shared.storage.get(key) ?? null,
      setItem: (key: string, value: string) => { shared.storage.set(key, value) },
      removeItem: (key: string) => { shared.storage.delete(key) }
    }
  }
  const contextModule = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpContext.ts', bindings)
  const commandModule = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpCommand.ts', {
    ...bindings,
    useOfficeErpContext: contextModule.useOfficeErpContext
  })
  const scope = Vue.effectScope()
  const mounted = scope.run(() => ({
    context: (contextModule.useOfficeErpContext as () => { refresh(): Promise<void> })(),
    command: (commandModule.useOfficeErpCommand as () => Command)()
  }))!
  return {
    ...mounted,
    shared,
    bindings: {
      ...bindings,
      useOfficeErpContext: contextModule.useOfficeErpContext,
      useOfficeErpCommand: commandModule.useOfficeErpCommand
    },
    stop: () => scope.stop()
  }
}

async function flushVue(): Promise<void> {
  await Vue.nextTick()
  await new Promise<void>(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

async function createUnknownCommand(harness: Harness, name: string): Promise<string> {
  const result = await harness.command.execute('offer.create', '/erp-commercial/plans', { name })
  assert.equal(result.status, 'unknown')
  if (result.status !== 'unknown') throw new Error('Expected an unknown mutation outcome')
  return result.key
}

async function assertNewRecoverySurvivesOldRecovery(
  authorityChange: 'account' | 'session' | 'capability',
  oldResponse: 'success' | 'error'
): Promise<void> {
  const lookups = new Map<string, ReturnType<typeof deferred<unknown>>>()
  let postCount = 0
  const shared = newShared()
  const harness = mountComposables(shared, async (path, init) => {
    if (path === '/erp-commercial/context') return { data: shared.capabilities.value }
    if (path === '/erp-commercial/plans' && init?.method === 'POST') {
      postCount++
      throw new Error('connection dropped after dispatch')
    }
    const key = path.split('/').at(-1)!
    return lookups.get(key)!.promise
  })
  try {
    const oldKey = await createUnknownCommand(harness, 'old-context')
    const oldResponsePromise = deferred<unknown>()
    lookups.set(oldKey, oldResponsePromise)
    const oldRecovery = harness.command.recover(oldKey)
    await flushVue()
    assert.equal(harness.command.pending.value, true)

    if (authorityChange === 'account') {
      shared.identity.value.current_account = { id: 9, status: 'active', role: 'admin' }
    } else if (authorityChange === 'session') {
      shared.session.value++
    } else {
      shared.capabilities.value = fullCapabilities('cap-2')
      await harness.context.refresh()
    }
    await flushVue()
    await harness.context.refresh()
    await flushVue()

    const newKey = await createUnknownCommand(harness, 'new-context')
    assert.notEqual(newKey, oldKey)
    const newResponsePromise = deferred<unknown>()
    lookups.set(newKey, newResponsePromise)
    const newRecovery = harness.command.recover(newKey)
    await flushVue()
    assert.equal(harness.command.pending.value, true)

    if (oldResponse === 'success') oldResponsePromise.resolve({ data: { id: 1, source: 'old' } })
    else oldResponsePromise.reject(Object.assign(new Error('old lookup forbidden'), { statusCode: 403 }))
    assert.deepEqual(await oldRecovery, { status: 'stale' })
    await flushVue()

    assert.equal(harness.command.pending.value, true, 'the old completion must not clear the new reservation')
    assert.equal(harness.command.recoveryKey.value, newKey)
    assert.deepEqual(await harness.command.execute('offer.create', '/erp-commercial/plans', { name: 'duplicate' }), { status: 'unknown', key: newKey })
    assert.equal(postCount, 2, 'a duplicate submit is blocked while the new lookup is pending')

    newResponsePromise.resolve({ data: { id: 2, source: 'new' } })
    assert.deepEqual(await newRecovery, { status: 'confirmed', data: { id: 2, source: 'new' } })
    assert.equal(harness.command.pending.value, false)
  } finally {
    harness.stop()
  }
}

type TestNode = { tag?: string, text?: string, props: Record<string, unknown>, children: TestNode[], parent?: TestNode | null }

function renderedText(node: TestNode): string {
  return `${node.text ?? ''}${node.children.map(renderedText).join('')}`
}

function findRendered(node: TestNode, predicate: (candidate: TestNode) => boolean): TestNode[] {
  return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => findRendered(child, predicate))]
}

function mountOfficePlans(component: Vue.Component) {
  const root: TestNode = { props: {}, children: [] }
  const renderer = Vue.createRenderer({
    patchProp(node: TestNode, key, _previous, next) { node.props[key] = next },
    insert(node: TestNode, parent: TestNode, anchor?: TestNode) {
      node.parent = parent
      const position = anchor ? parent.children.indexOf(anchor) : -1
      if (position < 0) parent.children.push(node)
      else parent.children.splice(position, 0, node)
    },
    remove(node: TestNode) {
      if (!node.parent) return
      const position = node.parent.children.indexOf(node)
      if (position >= 0) node.parent.children.splice(position, 1)
    },
    createElement(tag) { return { tag, props: {}, children: [], parent: null } },
    createText(text) { return { text, props: {}, children: [], parent: null } },
    createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node: TestNode, text) { node.text = text },
    setElementText(node: TestNode, text) {
      node.children = []
      node.text = text
    },
    parentNode(node: TestNode) { return node.parent ?? null },
    nextSibling(node: TestNode) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(component)
  const passthrough: Vue.Component = {
    props: ['open'],
    setup: (props, context) => () => Vue.h('div', {}, props.open
      ? Object.values(context.slots).map(slot => slot?.())
      : Object.values(context.slots).filter((_slot, index) => index < 4).map(slot => slot?.()))
  }
  const alert: Vue.Component = {
    setup: (_props, context) => () => Vue.h('aside', {}, [context.slots.default?.(), context.slots.description?.()])
  }
  const button: Vue.Component = {
    props: ['disabled', 'loading'],
    setup: (props, context) => () => Vue.h('button', {
      ...context.attrs,
      'disabled': props.disabled,
      'data-loading': String(props.loading ?? false)
    }, context.slots.default?.())
  }
  const panel: Vue.Component = {
    setup: (_props, context) => () => Vue.h('main', {}, [context.slots.action?.(), context.slots.toolbar?.(), context.slots.default?.()])
  }
  app.component('UAlert', alert)
  app.component('UButton', button)
  app.component('DataTablePanelList', panel)
  for (const name of ['UBadge', 'UCheckbox', 'UFormField', 'UInput', 'UModal', 'UPagination', 'USelect', 'USelectMenu', 'USlideover', 'UTextarea'])
    app.component(name, passthrough)
  app.component('UTable', { setup: () => () => Vue.h('div') })
  app.component('OfficeErpPlanEditor', { setup: () => () => Vue.h('form') })
  app.mount(root)
  return { app, root }
}

describe('Office ERP recovery context boundaries', () => {
  it('does not let a late successful lookup clear a new Account recovery reservation', async () => {
    await assertNewRecoverySurvivesOldRecovery('account', 'success')
  })

  it('does not let a late failed lookup clear a new session-generation recovery reservation', async () => {
    await assertNewRecoverySurvivesOldRecovery('session', 'error')
  })

  it('does not let a late successful lookup clear a new server-capability recovery reservation', async () => {
    await assertNewRecoverySurvivesOldRecovery('capability', 'success')
  })

  it('keeps lookup 404 unknown and 403 failed under the same key without posting again', async (t) => {
    let lookupStatus = 404
    let postCount = 0
    const harness = mountComposables(undefined, async (path, init) => {
      if (path === '/erp-commercial/context') return { data: fullCapabilities('cap-1') }
      if (path === '/erp-commercial/plans' && init?.method === 'POST') {
        postCount++
        throw new Error('connection dropped')
      }
      throw Object.assign(new Error('lookup denied or missing'), { statusCode: lookupStatus, data: { code: lookupStatus === 403 ? 'forbidden' : 'not_found', message: 'lookup failed' } })
    })
    t.after(harness.stop)

    const key = await createUnknownCommand(harness, 'keep-key')
    assert.deepEqual(await harness.command.recover(key), { status: 'unknown', key })
    lookupStatus = 403
    const forbidden = await harness.command.recover(key)
    assert.equal(forbidden.status, 'failed')
    if (forbidden.status === 'failed') assert.equal(forbidden.failure.http_status, 403)
    assert.equal(harness.command.recoveryKey.value, key)
    assert.equal(harness.command.pending.value, true)
    assert.deepEqual(await harness.command.execute('offer.create', '/erp-commercial/plans', { name: 'no retry' }), { status: 'unknown', key })
    assert.equal(postCount, 1)
  })

  it('keeps nested invoice balances from a real 409 command failure envelope', async (t) => {
    const currentBalances = [{
      invoice_id: 81,
      balance: {
        original_total: '100.00',
        adjusted_charge: '100.00',
        effective_received: '60.00',
        outstanding: '40.00',
        settlement_status: 'partial',
        overdue: false
      }
    }]
    const harness = mountComposables(undefined, async (path, init) => {
      if (path === '/erp-commercial/context') return { data: fullCapabilities('cap-1') }
      if (path === '/erp-commercial/receipts' && init?.method === 'POST') {
        throw Object.assign(new Error('receipt allocation exceeds current balance'), {
          statusCode: 409,
          data: {
            code: 'receipt_balance_conflict',
            message: 'O saldo atual da fatura não comporta esta alocação.',
            data: { current_balances: currentBalances }
          }
        })
      }
      return { data: {} }
    })
    t.after(harness.stop)

    assert.deepEqual(await harness.command.execute('receipt.record', '/erp-commercial/receipts', {
      amount: '50.00',
      received_on: '2026-10-25',
      allocations: [{ invoice_id: 81, amount: '50.00' }]
    }), {
      status: 'failed',
      failure: {
        code: 'receipt_balance_conflict',
        message: 'O saldo atual da fatura não comporta esta alocação.',
        http_status: 409,
        data: { current_balances: currentBalances }
      }
    })
    assert.equal(harness.command.pending.value, false)
    assert.equal(harness.shared.calls.filter(call => call.path === '/erp-commercial/receipts' && call.init?.method === 'POST').length, 1)
  })

  it('restores only a matching nonsecret operation and blocks admission without capabilities', async (t) => {
    const original = mountComposables(undefined, async (path, init) => {
      if (path === '/erp-commercial/context') return { data: fullCapabilities('cap-1') }
      if (path === '/erp-commercial/plans' && init?.method === 'POST') throw new Error('connection dropped')
      return { data: { id: 72 } }
    })
    t.after(original.stop)
    const key = await createUnknownCommand(original, 'do-not-persist-this-payload')
    assert.doesNotMatch([...original.shared.storage.values()].join('\n'), /do-not-persist-this-payload|password|token/)

    const restored = mountComposables({
      ...newShared(),
      identity: Vue.ref({ id: 41, current_account: { id: 8, status: 'active', role: 'admin' } }),
      storage: new Map(original.shared.storage)
    })
    t.after(restored.stop)
    await restored.context.refresh()
    await flushVue()
    assert.equal(restored.command.recoveryKey.value, key)

    for (const identity of [
      { id: 42, current_account: { id: 8, status: 'active' as const, role: 'admin' as const } },
      { id: 41, current_account: { id: 9, status: 'active' as const, role: 'admin' as const } }
    ]) {
      const mismatch = mountComposables({
        ...newShared(),
        identity: Vue.ref(identity),
        storage: new Map(original.shared.storage)
      })
      await mismatch.context.refresh()
      assert.deepEqual(await mismatch.command.recover(key), { status: 'stale' })
      mismatch.stop()
    }

    const differentCapability = mountComposables({
      ...newShared(),
      storage: new Map(original.shared.storage),
      capabilities: Vue.ref(fullCapabilities('cap-other'))
    })
    await differentCapability.context.refresh()
    assert.deepEqual(await differentCapability.command.recover(key), { status: 'stale' })
    differentCapability.stop()

    let writes = 0
    let lookups = 0
    const unavailable = mountComposables(newShared(), async (path, init) => {
      if (path === '/erp-commercial/context') return { data: null }
      if (path === '/erp-commercial/plans' && init?.method === 'POST') writes++
      if (path.startsWith('/erp-commercial/operations/')) lookups++
      return { data: {} }
    })
    t.after(unavailable.stop)
    const execute = await unavailable.command.execute('offer.create', '/erp-commercial/plans', { name: 'blocked' })
    assert.equal(execute.status, 'failed')
    assert.equal(writes, 0)
    const recover = await unavailable.command.recover(key)
    assert.equal(recover.status, 'failed')
    assert.equal(lookups, 0)
  })

  it('renders the real plans recovery key and fences an old success from a new Account editor', async (t) => {
    const seedShared = newShared()
    const seed = mountComposables(seedShared, async (path, init) => {
      if (path === '/erp-commercial/context') return { data: seedShared.capabilities.value }
      if (path === '/erp-commercial/plans' && init?.method === 'POST') throw new Error('connection dropped')
      return { data: { id: 72 } }
    })
    t.after(seed.stop)
    const oldKey = await createUnknownCommand(seed, 'initial offer')
    seed.stop()

    const reloadedShared = newShared()
    reloadedShared.identity.value = {
      id: seedShared.identity.value.id,
      current_account: { ...seedShared.identity.value.current_account! }
    }
    reloadedShared.session.value = seedShared.session.value + 1
    reloadedShared.storage = new Map(seedShared.storage)
    assert.equal(reloadedShared.states.size, 0, 'a full reload starts with fresh Nuxt state')

    const oldLookup = deferred<unknown>()
    let lookupCount = 0
    let postCount = 0
    const pageHarness = mountComposables(reloadedShared, async (path, init) => {
      if (path === '/erp-commercial/context') return { data: reloadedShared.capabilities.value }
      if (path === '/erp-commercial/plans' && init?.method === 'POST') {
        postCount++
        throw new Error('unexpected mutation resend')
      }
      if (path === `/erp-commercial/operations/${oldKey}`) {
        lookupCount++
        return oldLookup.promise
      }
      if (path === '/erp-commercial/plans') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
      return { data: {} }
    })
    t.after(pageHarness.stop)
    const toasts: Array<Record<string, unknown>> = []
    const component = compileVueComponent('pages/gestao-erp/planos.vue', {
      ...pageHarness.bindings,
      refDebounced: (value: Vue.Ref<unknown>) => value,
      useRoute: () => ({ query: {} }),
      useToast: () => ({ add: (toast: Record<string, unknown>) => { toasts.push(toast) } }),
      definePageMeta() {}
    })
    const { app, root } = mountOfficePlans(component)
    t.after(() => app.unmount())
    await flushVue()

    assert.equal(pageHarness.command.recoveryKey.value, oldKey)
    assert.equal(pageHarness.command.pending.value, true)
    assert.equal(lookupCount, 0, 'the real caller restores the persisted key without probing until requested')
    assert.equal(postCount, 0, 'reload never blindly resends the mutation')
    let verifyButton = findRendered(root, node => node.tag === 'button' && renderedText(node).includes('Verificar resultado'))[0]
    assert.ok(verifyButton, 'the real SFC renders recovery for the restored key')
    assert.equal(verifyButton.props.disabled, false)
    ;(verifyButton.props.onClick as () => void)()
    await flushVue()
    assert.equal(lookupCount, 1, 'the explicit caller action performs one operation lookup')
    verifyButton = findRendered(root, node => node.tag === 'button' && renderedText(node).includes('Verificar resultado'))[0]!
    assert.equal(verifyButton.props.disabled, true)
    assert.equal(verifyButton.props['data-loading'], 'true')

    pageHarness.shared.identity.value.current_account = { id: 9, status: 'active', role: 'admin' }
    await flushVue()
    const createButton = findRendered(root, node => node.tag === 'button' && renderedText(node).includes('Novo plano'))[0]
    assert.ok(createButton)
    ;(createButton.props.onClick as () => void)()
    await flushVue()
    assert.ok(findRendered(root, node => node.tag === 'form').length > 0, 'the new Account editor is open')

    oldLookup.resolve({ data: { id: 1, name: 'Old Account offer' } })
    await flushVue()
    assert.ok(findRendered(root, node => node.tag === 'form').length > 0, 'the old completion leaves the new editor open')
    assert.deepEqual(toasts, [], 'the old completion does not toast in the new Account')
  })
})
