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
  const api = options.api ?? (async () => ({ data: fullCapabilities('cap-v1') }))
  const loaded = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpContext.ts', {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, readonly: Vue.readonly, onScopeDispose: Vue.onScopeDispose,
    useNuxtApp: () => ({}),
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
  return { context, identity, session, api, stop: () => scope.stop() }
}

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
