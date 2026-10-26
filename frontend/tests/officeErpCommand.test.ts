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
  capability_key: string
  capability_reasons: Record<'can_configure' | 'can_sell_new' | 'can_provision' | 'can_start_trial' | 'can_manage_trial_link', string[]>
  reasons: Record<string, string[]>
  allowance: { enabled: boolean, licence_limit: number | null, module_keys: string[] }
  usage: { reserved: number, occupied: number }
}
type Result<T>
  = | { status: 'confirmed', data: T }
    | { status: 'unknown', key: string }
    | { status: 'failed', failure: { code: string, message: string, errors?: Record<string, string[]>, http_status?: number } }
    | { status: 'stale' }
type Command = {
  pending: Vue.Ref<boolean>
  recoveryKey: Vue.Ref<string | null>
  execute<T>(action: string, path: string, body: Record<string, unknown>, method?: 'POST' | 'PATCH' | 'PUT'): Promise<Result<T>>
  recover<T>(key: string): Promise<Result<T>>
}

const caps: Capabilities = {
  can_read: true,
  can_maintain: true,
  can_configure: true,
  can_sell_new: true,
  can_provision: true,
  can_start_trial: false,
  can_manage_trial_link: true,
  capability_key: 'cap-1',
  capability_reasons: { can_configure: [], can_sell_new: [], can_provision: [], can_start_trial: [], can_manage_trial_link: [] },
  reasons: {},
  allowance: { enabled: true, licence_limit: 8, module_keys: ['purchases'] },
  usage: { reserved: 0, occupied: 2 }
}
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}
type Shared = { identity: Vue.Ref<Identity>, session: Vue.Ref<number>, states: Map<string, Vue.Ref<unknown>>, storage: Map<string, string>, calls: Array<{ path: string, init?: Record<string, unknown> }>, uuid: number }
function fixture(options: { api?: (path: string, init?: Record<string, unknown>) => Promise<unknown>, csrf?: () => Promise<void>, shared?: Shared, capabilities?: Capabilities } = {}) {
  const shared = options.shared ?? { identity: Vue.ref<Identity>({ id: 41, current_account: { id: 8, status: 'active', role: 'admin' } }), session: Vue.ref(3), states: new Map<string, Vue.Ref<unknown>>(), storage: new Map<string, string>(), calls: [] as Array<{ path: string, init?: Record<string, unknown> }>, uuid: 0 }
  const { identity, session, states, storage, calls } = shared
  const api = options.api ?? (async (path, init) => {
    calls.push({ path, init })
    if (path === '/erp-commercial/context') return { data: options.capabilities ?? caps }
    if (path.startsWith('/erp-commercial/operations/')) return { data: { outcome: 'created', id: 72 } }
    return { data: { id: 72 } }
  })
  const bindings = {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    readonly: Vue.readonly,
    onScopeDispose: Vue.onScopeDispose,
    useNuxtApp: () => ({}),
    useState: <T>(key: string, init: () => T) => {
      if (key === 'access-session-generation') return session as Vue.Ref<T>
      if (!states.has(key)) states.set(key, Vue.ref(init()))
      return states.get(key) as Vue.Ref<T>
    },
    useAuth: () => ({ user: identity, currentAccount: Vue.computed(() => identity.value.current_account) }),
    useAccessApi: () => ({
      api: async (path: string, init?: Record<string, unknown>) => {
        calls.push({ path, init })
        return api(path, init)
      },
      refreshCsrf: async () => { await options.csrf?.() }
    }),
    crypto: { randomUUID: () => (++shared.uuid === 1 ? '7cbbdbb6-7f88-4479-a78a-471e4cb16c78' : `7cbbdbb6-7f88-4479-a78a-${String(shared.uuid).padStart(12, '0')}`) },
    sessionStorage: {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => { storage.set(key, value) },
      removeItem: (key: string) => { storage.delete(key) }
    }
  }
  const contextModule = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpContext.ts', bindings)
  const loaded = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpCommand.ts', {
    ...bindings,
    useOfficeErpContext: contextModule.useOfficeErpContext
  })
  const scope = Vue.effectScope()
  const context = scope.run(() => (contextModule.useOfficeErpContext as () => { refresh(): Promise<void> })())!
  const command = scope.run(() => (loaded.useOfficeErpCommand as () => Command)())!
  return { command, context, identity, session, calls, storage, shared, stop: () => scope.stop() }
}

describe('useOfficeErpCommand', () => {
  it('sends PUT with the operation key and recovers an ambiguous result without resending', async (t) => {
    let loseResponse = true
    const harness = fixture({ api: async (path, init) => {
      harness.calls.push({ path, init })
      if (path === '/erp-commercial/context') return { data: caps }
      if (path === '/erp-commercial/settings/trial-link' && loseResponse) {
        loseResponse = false
        throw new Error('connection dropped')
      }
      if (path.startsWith('/erp-commercial/operations/')) return { data: { id: 72, enabled: true } }
      return { data: { id: 72, enabled: true } }
    } })
    t.after(harness.stop)

    const result = await harness.command.execute('trial_link.update', '/erp-commercial/settings/trial-link', { erp_plan_id: 9, enabled: true }, 'PUT')
    assert.equal(result.status, 'unknown')
    assert.equal(harness.command.pending.value, true)
    const write = harness.calls.find(call => call.path === '/erp-commercial/settings/trial-link')!
    assert.equal(write.init?.method, 'PUT')
    const key = (write.init?.headers as Record<string, string>)['Idempotency-Key']
    assert.equal(key, '7cbbdbb6-7f88-4479-a78a-471e4cb16c78')

    assert.deepEqual(await harness.command.recover<{ id: number, enabled: boolean }>(key), {
      status: 'confirmed', data: { id: 72, enabled: true }
    })
    assert.equal(harness.calls.filter(call => call.path === '/erp-commercial/settings/trial-link').length, 2, 'one mutation is recorded at both API harness layers')
    assert.equal(harness.calls.at(-1)?.path, `/erp-commercial/operations/${key}`)
    assert.equal(harness.command.pending.value, false)
  })

  it('admits one frozen payload and idempotency key while CSRF is pending', async (t) => {
    const csrf = deferred<undefined>()
    const harness = fixture({ csrf: () => csrf.promise })
    t.after(harness.stop)
    const body = { name: 'Plano inicial', invitation_token: 'SSR-secret-token', nested: { price: '100.00' } }
    const first = harness.command.execute('plan.create', '/erp-commercial/plans', body)
    body.nested.price = '999.00'
    const duplicate = await harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'different' })
    assert.equal(duplicate.status, 'unknown')
    assert.doesNotMatch(JSON.stringify([...harness.shared.states].map(([key, state]) => [key, state.value])), /SSR-secret-token|invitation_token/)
    assert.equal(harness.command.pending.value, true)
    csrf.resolve(undefined)
    assert.deepEqual(await first, { status: 'confirmed', data: { id: 72 } })
    const write = harness.calls.find(call => call.path === '/erp-commercial/plans')!
    assert.deepEqual(write.init?.body, { name: 'Plano inicial', invitation_token: 'SSR-secret-token', nested: { price: '100.00' } })
    assert.equal((write.init?.headers as Record<string, string>)['Idempotency-Key'], '7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    assert.equal(harness.command.pending.value, false)
  })

  it('keeps a lost response unknown, persists no body secrets and recovers the same operation key', async (t) => {
    let failWrite = true
    const harness = fixture({ api: async (path, init) => {
      harness.calls.push({ path, init })
      if (path === '/erp-commercial/context') return { data: caps }
      if (path === '/erp-commercial/plans' && failWrite) {
        failWrite = false
        throw new Error('connection dropped')
      }
      if (path === '/erp-commercial/operations/7cbbdbb6-7f88-4479-a78a-471e4cb16c78') return { data: { id: 72, status: 'created' } }
      return { data: { id: 72 } }
    } })
    t.after(harness.stop)

    const result = await harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'Offer', invitation_token: 'do-not-store' })
    assert.deepEqual(result, { status: 'unknown', key: '7cbbdbb6-7f88-4479-a78a-471e4cb16c78' })
    assert.equal(harness.command.pending.value, true)
    assert.equal(harness.command.recoveryKey.value, '7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    const saved = [...harness.storage.values()].join('\n')
    assert.match(saved, /7cbbdbb6-7f88-4479-a78a-471e4cb16c78/)
    assert.doesNotMatch(saved, /invitation_token|do-not-store|Offer/)
    assert.deepEqual(await harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'second' }), result)

    const recovered = await harness.command.recover<{ id: number, status: string }>('7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    assert.deepEqual(recovered, { status: 'confirmed', data: { id: 72, status: 'created' } })
    assert.equal(harness.calls.at(-1)?.path, '/erp-commercial/operations/7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    assert.equal(harness.command.pending.value, false)
    assert.equal(harness.command.recoveryKey.value, null)
  })

  it('preserves validation, authority, foreign-resource and conflict HTTP distinctions', async (t) => {
    const statusByAction: Record<string, number> = { validation: 422, authority: 403, foreign: 404, conflict: 409 }
    const harness = fixture({ api: async (path, init) => {
      harness.calls.push({ path, init })
      if (path === '/erp-commercial/context') return { data: caps }
      const action = String((init?.body as Record<string, unknown>)?.case)
      const statusCode = statusByAction[action]!
      throw { statusCode, data: { code: `${action}_code`, message: `${action} message`, errors: action === 'validation' ? { name: ['invalid'] } : undefined } }
    } })
    t.after(harness.stop)

    for (const [action, httpStatus] of Object.entries(statusByAction)) {
      const result = await harness.command.execute(action, '/erp-commercial/plans', { case: action })
      assert.equal(result.status, 'failed')
      if (result.status === 'failed') {
        assert.equal(result.failure.http_status, httpStatus)
        assert.equal(result.failure.code, `${action}_code`)
      }
    }
  })

  it('does not send after the Account changes during CSRF refresh and returns stale', async (t) => {
    const csrf = deferred<undefined>()
    const harness = fixture({ csrf: () => csrf.promise })
    t.after(harness.stop)
    const command = harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'A' })
    harness.identity.value.current_account = { id: 9, status: 'active', role: 'admin' }
    await Vue.nextTick()
    csrf.resolve(undefined)

    assert.deepEqual(await command, { status: 'stale' })
    assert.equal(harness.calls.some(call => call.path === '/erp-commercial/plans'), false)
  })

  it('releases only the old reservation when the authenticated session generation changes during CSRF', async (t) => {
    const csrf = deferred<undefined>()
    const harness = fixture({ csrf: () => csrf.promise })
    t.after(harness.stop)
    const command = harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'old session' })
    await Vue.nextTick()
    harness.session.value++
    await Vue.nextTick()
    csrf.resolve(undefined)

    assert.deepEqual(await command, { status: 'stale' })
    assert.equal(harness.command.pending.value, false)
    assert.deepEqual(await harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'new session' }), { status: 'confirmed', data: { id: 72 } })
  })

  it('discards a delayed success after the Account changes during transport', async (t) => {
    const response = deferred<unknown>()
    const harness = fixture({ api: async (path, init) => {
      harness.calls.push({ path, init })
      if (path === '/erp-commercial/context') return { data: caps }
      return response.promise
    } })
    t.after(harness.stop)
    const command = harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'A' })
    await Vue.nextTick()
    harness.identity.value.current_account = { id: 9, status: 'active', role: 'admin' }
    await Vue.nextTick()
    response.resolve({ data: { id: 99 } })

    assert.deepEqual(await command, { status: 'stale' })
    assert.equal(harness.command.pending.value, false)
  })

  it('returns unknown for malformed successful mutation envelopes and keeps the key recoverable', async (t) => {
    const harness = fixture({ api: async (path, init) => {
      harness.calls.push({ path, init })
      if (path === '/erp-commercial/context') return { data: caps }
      return { success: true }
    } })
    t.after(harness.stop)
    assert.deepEqual(await harness.command.execute('plan.create', '/erp-commercial/plans', { name: 'A' }), {
      status: 'unknown', key: '7cbbdbb6-7f88-4479-a78a-471e4cb16c78'
    })
    assert.equal(harness.command.pending.value, true)
  })

  it('keeps a newly admitted command reserved when an old deferred context read resolves after Account switch', async (t) => {
    const oldContext = deferred<unknown>()
    const csrf = deferred<undefined>()
    const seed = fixture()
    const shared = seed.shared
    seed.stop()
    const old = fixture({ shared, api: async path => path === '/erp-commercial/context' ? oldContext.promise : { data: {} } })
    const current = fixture({ shared, csrf: () => csrf.promise })
    t.after(old.stop)
    t.after(current.stop)
    const oldCommand = old.command.execute('plan.create', '/erp-commercial/plans', { name: 'old' })
    current.identity.value.current_account = { id: 9, status: 'active', role: 'admin' }
    await Vue.nextTick()
    const currentCommand = current.command.execute('plan.create', '/erp-commercial/plans', { name: 'current' })
    await Vue.nextTick()

    oldContext.resolve({ data: caps })
    assert.deepEqual(await oldCommand, { status: 'stale' })
    assert.equal(current.command.pending.value, true)
    csrf.resolve(undefined)
    assert.deepEqual(await currentCommand, { status: 'confirmed', data: { id: 72 } })
    assert.equal(current.calls.filter(call => call.path === '/erp-commercial/plans').length, 2, 'one mutation is observed at both API harness layers')
  })

  it('restores only a matching server capability operation after a fresh Nuxt state reload', async (t) => {
    const first = fixture({ api: async (path) => {
      if (path === '/erp-commercial/context') return { data: caps }
      throw new Error('connection dropped')
    } })
    t.after(first.stop)
    const unknown = await first.command.execute('plan.create', '/erp-commercial/plans', { password: 'never persisted' })
    assert.equal(unknown.status, 'unknown')
    assert.equal(first.command.recoveryKey.value, '7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    const stored = [...first.storage.values()].join('\n')
    assert.doesNotMatch(stored, /password|never persisted/)
    const mismatchedStorage = new Map(first.storage)
    const changedServerSession = fixture({ capabilities: { ...caps, capability_key: 'different-server-session-capability' }, shared: { identity: Vue.ref({ id: 41, current_account: { id: 8, status: 'active', role: 'admin' } }), session: Vue.ref(0), states: new Map(), storage: mismatchedStorage, calls: [], uuid: 11 } })
    t.after(changedServerSession.stop)
    assert.deepEqual(await changedServerSession.command.recover('7cbbdbb6-7f88-4479-a78a-471e4cb16c78'), { status: 'stale' })
    assert.equal(changedServerSession.command.recoveryKey.value, null)

    const reloaded = fixture({ shared: { identity: Vue.ref({ id: 41, current_account: { id: 8, status: 'active', role: 'admin' } }), session: Vue.ref(0), states: new Map(), storage: first.storage, calls: [], uuid: 10 } })
    t.after(reloaded.stop)
    await reloaded.context.refresh()
    await Vue.nextTick()
    assert.equal(reloaded.command.recoveryKey.value, '7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    await reloaded.command.recover('7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    assert.equal(reloaded.command.recoveryKey.value, null)
    assert.equal(reloaded.calls.filter(call => call.path.endsWith('/7cbbdbb6-7f88-4479-a78a-471e4cb16c78')).length, 2, 'one operation lookup is observed at both API harness layers')
    assert.deepEqual(await reloaded.command.recover('7cbbdbb6-7f88-4479-a78a-471e4cb16c78'), { status: 'stale' })

    const foreign = fixture({ shared: { identity: Vue.ref({ id: 42, current_account: { id: 8, status: 'active', role: 'admin' } }), session: Vue.ref(0), states: new Map(), storage: first.storage, calls: [], uuid: 20 } })
    t.after(foreign.stop)
    assert.equal(foreign.command.recoveryKey.value, null)
    assert.deepEqual(await foreign.command.recover('7cbbdbb6-7f88-4479-a78a-471e4cb16c78'), { status: 'stale' })
  })
})
