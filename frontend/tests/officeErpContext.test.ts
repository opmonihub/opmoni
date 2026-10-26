import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
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
  capability_reasons: Record<string, string[]>
  capability_key: string
  reasons: Record<string, string[]>
  allowance: { enabled: boolean, licence_limit: number | null, module_keys: string[] }
  usage: { reserved: number, occupied: number }
}
type Context = {
  capabilities: Vue.Ref<Capabilities | null>
  capture(): { key: string, generation: number }
  isCurrent(token: { key: string, generation: number }): boolean
  invalidate(): void
  refresh(): Promise<void>
}

const fullCapabilities = (capabilityKey: string): Capabilities => ({
  can_read: true, can_maintain: true, can_configure: true, can_sell_new: true,
  can_provision: true, can_start_trial: true, can_manage_trial_link: true,
  capability_reasons: { can_configure: [], can_sell_new: [], can_provision: [], can_start_trial: [], can_manage_trial_link: [] },
  capability_key: capabilityKey, reasons: {}, allowance: { enabled: true, licence_limit: 12, module_keys: ['purchases'] },
  usage: { reserved: 1, occupied: 4 }
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

function mountContext(options: { account?: Account | null, actorId?: number, session?: number, api?: (path: string) => Promise<unknown> } = {}) {
  const identity = Vue.ref<Identity>({ id: options.actorId ?? 41, current_account: options.account === undefined ? { id: 8, status: 'active', role: 'admin' } : options.account })
  const session = Vue.ref(options.session ?? 3)
  const states = new Map<string, Vue.Ref<unknown>>()
  const app = {}
  const api = options.api ?? (async () => ({ data: fullCapabilities('cap-v1') }))
  const loaded = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpContext.ts', {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, readonly: Vue.readonly, onScopeDispose: Vue.onScopeDispose,
    useNuxtApp: () => app,
    useState: <T>(key: string, init: () => T) => {
      if (key === 'access-session-generation') return session as Vue.Ref<T>
      if (!states.has(key)) states.set(key, Vue.ref(init()))
      return states.get(key) as Vue.Ref<T>
    },
    useAuth: () => ({ user: identity, currentAccount: Vue.computed(() => identity.value.current_account) }),
    useAccessApi: () => ({ api })
  })
  const scope = Vue.effectScope()
  const context = scope.run(() => (loaded.useOfficeErpContext as () => Context)())!
  return { context, identity, session, api, createConsumer: () => scope.run(() => (loaded.useOfficeErpContext as () => Context)())!, stop: () => scope.stop() }
}

it('coalesces pending confirmation across consumers but rechecks after settlement', async (t) => {
  let calls = 0
  const response = deferred<unknown>()
  const harness = mountContext({ api: async () => {
    calls++
    return response.promise
  } })
  t.after(harness.stop)
  const second = harness.createConsumer()
  const firstRead = harness.context.refresh()
  const secondRead = second.refresh()
  assert.equal(calls, 1)
  response.resolve({ data: fullCapabilities('shared') })
  await Promise.all([firstRead, secondRead])
  assert.equal(harness.context.capabilities.value?.capability_key, 'shared')
  assert.equal(second.capabilities.value?.capability_key, 'shared')
  await second.refresh()
  assert.equal(calls, 2)
})

it('an older authority completion cannot clear a newer shared flight', async (t) => {
  const old = deferred<unknown>()
  const current = deferred<unknown>()
  let calls = 0
  const harness = mountContext({ api: () => ++calls === 1 ? old.promise : current.promise })
  t.after(harness.stop)
  const second = harness.createConsumer()
  const oldRead = harness.context.refresh()
  harness.identity.value.current_account = { id: 12, status: 'active', role: 'admin' }
  const currentRead = second.refresh()
  old.resolve({ data: fullCapabilities('old') })
  await oldRead
  const sharedRead = harness.context.refresh()
  assert.equal(calls, 2)
  current.resolve({ data: fullCapabilities('current') })
  await Promise.all([currentRead, sharedRead])
  assert.equal(harness.context.capabilities.value?.capability_key, 'current')
})

describe('useOfficeErpContext', () => {
  it('loads office capabilities through the tenant API and captures actor, Account and capability identity', async (t) => {
    const paths: string[] = []
    const harness = mountContext({ api: async (path) => {
      paths.push(path)
      return { data: fullCapabilities('cap-office-8-v1') }
    } })
    t.after(harness.stop)

    await harness.context.refresh()

    assert.deepEqual(paths, ['/erp-commercial/context'])
    assert.equal(harness.context.capabilities.value?.capability_key, 'cap-office-8-v1')
    const token = harness.context.capture()
    assert.equal(harness.context.isCurrent(token), true)
    assert.equal(typeof token.key, 'string')
    assert.equal(Number.isSafeInteger(token.generation), true)
  })

  it('rejects a server context response that omits the new admission capabilities', async (t) => {
    const incomplete = { ...fullCapabilities('incomplete'), can_provision: undefined }
    const harness = mountContext({ api: async () => ({ data: incomplete }) })
    t.after(harness.stop)

    await harness.context.refresh()

    assert.equal(harness.context.capabilities.value, null)
  })

  it('invalidates old tokens and clears private capabilities when Account, session or role changes', async (t) => {
    const harness = mountContext()
    t.after(harness.stop)
    await harness.context.refresh()
    const old = harness.context.capture()

    harness.identity.value = { id: 41, current_account: { id: 9, status: 'active', role: 'admin' } }
    assert.equal(harness.context.isCurrent(old), false)
    await Vue.nextTick()
    assert.equal(harness.context.capabilities.value, null)
    await harness.context.refresh()
    const accountToken = harness.context.capture()
    harness.session.value++
    assert.equal(harness.context.isCurrent(accountToken), false)
    await Vue.nextTick()
    assert.equal(harness.context.capabilities.value, null)
    await harness.context.refresh()
    const sessionToken = harness.context.capture()
    harness.identity.value.current_account!.role = 'operador'
    await Vue.nextTick()
    assert.equal(harness.context.isCurrent(sessionToken), false)
    assert.equal(harness.context.capabilities.value, null)
  })

  it('discards a delayed context response after the selected office changes', async (t) => {
    const response = deferred<unknown>()
    const harness = mountContext({ api: () => response.promise })
    t.after(harness.stop)
    const refresh = harness.context.refresh()
    harness.identity.value.current_account = { id: 12, status: 'active', role: 'admin' }
    await Vue.nextTick()
    response.resolve({ data: fullCapabilities('stale-office-capability') })
    await refresh

    assert.equal(harness.context.capabilities.value, null)
    assert.equal(harness.context.isCurrent({ key: 'obsolete', generation: 0 }), false)
  })

  it('invalidates tokens when server capabilities or Account status changes', async (t) => {
    let capabilityKey = 'cap-v1'
    const harness = mountContext({ api: async () => ({ data: fullCapabilities(capabilityKey) }) })
    t.after(harness.stop)
    await harness.context.refresh()
    const first = harness.context.capture()

    capabilityKey = 'cap-v2'
    await harness.context.refresh()
    assert.equal(harness.context.isCurrent(first), false)
    assert.equal(harness.context.capabilities.value?.capability_key, 'cap-v2')
    const second = harness.context.capture()
    harness.identity.value.current_account!.status = 'suspended'
    assert.equal(harness.context.isCurrent(second), false)
    await Vue.nextTick()
    assert.equal(harness.context.capabilities.value, null)
  })

  it('keeps a context invalidated when authority changes twice before Vue flushes', async (t) => {
    const harness = mountContext()
    t.after(harness.stop)
    await harness.context.refresh()
    const token = harness.context.capture()
    harness.identity.value.current_account = { id: 9, status: 'active', role: 'admin' }
    harness.identity.value.current_account = { id: 8, status: 'active', role: 'admin' }

    assert.equal(harness.context.isCurrent(token), false)
    assert.equal(harness.context.capabilities.value, null)
  })

  it('keeps state isolated between Nuxt app scopes', async (t) => {
    const first = mountContext({ api: async () => ({ data: fullCapabilities('first') }) })
    const second = mountContext({ api: async () => ({ data: fullCapabilities('second') }) })
    t.after(first.stop)
    t.after(second.stop)
    await first.context.refresh()
    await second.context.refresh()

    assert.equal(first.context.capabilities.value?.capability_key, 'first')
    assert.equal(second.context.capabilities.value?.capability_key, 'second')
    first.context.invalidate()
    assert.equal(first.context.capabilities.value, null)
    assert.equal(second.context.capabilities.value?.capability_key, 'second')
  })
})

it('publishes owner and capabilities atomically to synchronous consumers', async (t) => {
  const harness = mountContext()
  t.after(harness.stop)
  const publications: Array<string | null> = []
  const stop = Vue.watch(harness.context.capabilities, value => publications.push(value?.capability_key ?? null), { flush: 'sync' })
  t.after(stop)
  await harness.context.refresh()
  assert.deepEqual(publications, ['cap-v1'])
})

it('a capability publication consumer joins the current confirmation instead of dispatching twice', async (t) => {
  let calls = 0
  const harness = mountContext({ api: async () => {
    calls++
    return { data: fullCapabilities('published') }
  } })
  t.after(harness.stop)
  const consumer = harness.createConsumer()
  let joined: Promise<void> | undefined
  const stop = Vue.watch(consumer.capabilities, () => {
    joined = consumer.refresh()
  }, { flush: 'sync' })
  t.after(stop)
  await harness.context.refresh()
  await joined
  assert.equal(calls, 1)
  assert.equal(consumer.capabilities.value?.capability_key, 'published')
})

it('explicit invalidation fences an older flight even under unchanged authority', async (t) => {
  const old = deferred<unknown>()
  const fresh = deferred<unknown>()
  let calls = 0
  const harness = mountContext({ api: () => ++calls === 1 ? old.promise : fresh.promise })
  t.after(harness.stop)
  const oldRead = harness.context.refresh()
  harness.context.invalidate()
  const newRead = harness.context.refresh()
  old.resolve({ data: fullCapabilities('invalid') })
  await oldRead
  const joined = harness.createConsumer().refresh()
  assert.equal(calls, 2)
  assert.equal(harness.context.capabilities.value, null)
  fresh.resolve({ data: fullCapabilities('fresh') })
  await Promise.all([newRead, joined])
  assert.equal(harness.context.capabilities.value?.capability_key, 'fresh')
})

it('multiple authority watchers invalidate once before a reentrant new-authority confirmation', async (t) => {
  let calls = 0
  const response = deferred<unknown>()
  const harness = mountContext({ api: async () => {
    calls++
    return calls === 1 ? { data: fullCapabilities('old') } : response.promise
  } })
  t.after(harness.stop)
  harness.createConsumer()
  await harness.context.refresh()
  let transition: Promise<void> | undefined
  const stop = Vue.watch(harness.context.capabilities, (value) => {
    if (!value) transition = harness.context.refresh()
  }, { flush: 'sync' })
  t.after(stop)
  harness.identity.value.current_account = { id: 12, status: 'active', role: 'admin' }
  const joined = harness.context.refresh()
  assert.equal(calls, 2)
  response.resolve({ data: fullCapabilities('current') })
  await Promise.all([transition, joined])
  assert.equal(harness.context.capabilities.value?.capability_key, 'current')
})
