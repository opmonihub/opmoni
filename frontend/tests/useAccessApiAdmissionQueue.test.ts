import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import ts from 'typescript'
import { createFetch } from '../node_modules/.pnpm/ofetch@1.5.1/node_modules/ofetch/dist/index.mjs'
import * as Vue from 'vue'
import { createAccessApiAdmissionQueue, snapshotAccessApiBody, snapshotAccessApiQuery, useAccessApiAdmissionQueue } from '../app/utils/accessApiAdmissionQueue.ts'
import type { Identity } from '../shared/types/access.ts'
import * as business from '../shared/utils/business.ts'
import * as erp from '../shared/utils/erp.ts'
import * as ingress from '../shared/utils/ingress.ts'

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

function identity(companyId = 3): Identity {
  const company = { id: companyId, name: `Company ${companyId}`, status: 'active' as const, access_kind: 'direct' as const, role: 'owner', permissions: ['*'], delegated_office_id: null }
  return {
    id: 7,
    name: 'Actor',
    email: 'actor@example.test',
    is_super_admin: false,
    accounts: [{ id: 1, name: 'Office', status: 'active', role: 'admin' }],
    current_account: { id: 1, name: 'Office', status: 'active', role: 'admin' },
    companies: [company],
    current_company: company
  }
}

function accessApi(request: (...args: unknown[]) => Promise<unknown>, nuxtApp = {}, server = false, event: unknown = null) {
  const states = new Map<string, Vue.Ref<unknown>>([
    ['access-user', Vue.ref<Identity | null>(identity())],
    ['access-csrf', Vue.ref<string | null>(null)],
    ['access-session-generation', Vue.ref(0)]
  ])
  const bindings = {
    useNuxtApp: () => nuxtApp,
    useRequestFetch: () => request,
    useRequestEvent: () => event,
    useState: (key: string, initial: () => unknown) => {
      if (!states.has(key)) states.set(key, Vue.ref(initial()))
      return states.get(key)
    }
  }
  const source = readFileSync(new URL('../app/composables/useAccessApi.ts', import.meta.url), 'utf8').replaceAll('import.meta.server', String(server))
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const module: { exports: Record<string, unknown> } = { exports: {} }
  const imports: Record<string, unknown> = {
    '#shared/utils/business': business,
    '#shared/utils/erp': erp,
    '#shared/utils/ingress': ingress,
    '~/utils/accessApiAdmissionQueue': { snapshotAccessApiBody, snapshotAccessApiQuery, useAccessApiAdmissionQueue }
  }
  new Function('require', 'module', 'exports', ...Object.keys(bindings), output)(
    (path: string) => imports[path], module, module.exports, ...Object.values(bindings)
  )
  const statesView = states as Map<string, Vue.Ref<unknown>>
  return {
    api: (module.exports.useAccessApi as () => { api: (path: string, options?: Record<string, unknown>) => Promise<unknown> })().api,
    states: statesView,
    app: nuxtApp
  }
}

test('access admission queue serializes dispatch and keeps Nuxt apps isolated', async () => {
  const queue = createAccessApiAdmissionQueue()
  const first = deferred<string>()
  const order: string[] = []
  const a = queue.run(async () => {
    order.push('first:start')
    await first.promise
    order.push('first:end')
    return 'a'
  })
  const b = queue.run(() => {
    order.push('second')
    return 'b'
  })
  await Promise.resolve()
  assert.deepEqual(order, ['first:start'])
  first.resolve('released')
  assert.deepEqual(await Promise.all([a, b]), ['a', 'b'])
  assert.deepEqual(order, ['first:start', 'first:end', 'second'])

  const one = {}
  const two = {}
  assert.equal(useAccessApiAdmissionQueue(one), useAccessApiAdmissionQueue(one))
  assert.notEqual(useAccessApiAdmissionQueue(one), useAccessApiAdmissionQueue(two))
})

test('separate API transports for separate Nuxt apps dispatch independently', async () => {
  const startedOne = deferred<undefined>()
  const startedTwo = deferred<undefined>()
  const releaseOne = deferred<undefined>()
  const releaseTwo = deferred<undefined>()
  const one = accessApi(async () => {
    startedOne.resolve(undefined)
    await releaseOne.promise
    return 'one'
  }, {})
  const two = accessApi(async () => {
    startedTwo.resolve(undefined)
    await releaseTwo.promise
    return 'two'
  }, {})
  const first = one.api('/me')
  const second = two.api('/me')
  await Promise.all([startedOne.promise, startedTwo.promise])
  releaseOne.resolve(undefined)
  releaseTwo.resolve(undefined)
  assert.deepEqual(await Promise.all([first, second]), ['one', 'two'])
})

test('aborted or stale queued work is rejected without transport dispatch', async () => {
  const queue = createAccessApiAdmissionQueue()
  const hold = deferred<undefined>()
  const first = queue.run(() => hold.promise)
  await Promise.resolve()
  const controller = new AbortController()
  const cancelled = queue.run(() => assert.fail('aborted work must not run'), { signal: controller.signal })
  controller.abort()
  await assert.rejects(cancelled, { name: 'AbortError' })
  let current = true
  const stale = queue.run(() => assert.fail('stale work must not run'), { isCurrent: () => current })
  current = false
  hold.resolve(undefined)
  await first
  await assert.rejects(stale, { name: 'AbortError' })
})

test('a rejected transport releases the next queued dispatch', async () => {
  const queue = createAccessApiAdmissionQueue()
  const failFirst = deferred<undefined>()
  const first = queue.run(() => failFirst.promise)
  const second = queue.run(() => 'second')
  failFirst.reject(new Error('transport failure'))
  await assert.rejects(first, /transport failure/)
  assert.equal(await second, 'second')
})

test('API abort while queued prevents dispatch and releases the next request', async () => {
  const firstResponse = deferred<unknown>()
  const calls: string[] = []
  const transport = accessApi(async (path) => {
    calls.push(String(path))
    if (String(path).endsWith('/erp/contacts')) return firstResponse.promise
    return { ok: true }
  })
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const controller = new AbortController()
  const aborted = transport.api('/erp/products', { signal: controller.signal })
  const next = transport.api('/erp/units')
  controller.abort()
  await assert.rejects(aborted, { name: 'AbortError' })
  firstResponse.resolve({ data: [] })
  await Promise.all([first, next])
  assert.deepEqual(calls, ['/api/v1/erp/contacts', '/api/v1/erp/units'])
})

test('active API transport receives the caller abort signal', async () => {
  const controller = new AbortController()
  const started = deferred<AbortSignal | undefined>()
  const transport = accessApi((_path, options = {}) => {
    const signal = (options as Record<string, unknown>).signal as AbortSignal | undefined
    started.resolve(signal)
    return new Promise((_, reject) => {
      signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')), { once: true })
    })
  })
  const pending = transport.api('/erp/contacts', { signal: controller.signal })
  assert.equal(await started.promise, controller.signal)
  controller.abort()
  await assert.rejects(pending, { name: 'AbortError' })
})

test('API snapshots tenant headers and rejects mutations made stale during CSRF preflight', async () => {
  const csrfResponse = deferred<unknown>()
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const transport = accessApi(async (path, options = {}) => {
    const url = String(path)
    const opts = options as Record<string, unknown>
    calls.push({ path: url, options: opts })
    if (url.endsWith('/csrf')) return csrfResponse.promise
    return { ok: true }
  })
  const mutation = transport.api('/erp/products', {
    method: 'POST',
    headers: { 'X-Trace-Id': 'preserved' },
    body: { name: 'queued' }
  })
  await Promise.resolve()
  assert.deepEqual(calls.map(call => call.path), ['/api/v1/csrf'])

  transport.states.get('access-user')!.value = identity(4)
  transport.states.get('access-session-generation')!.value = 1
  csrfResponse.resolve({ csrf_token: 'fresh-token' })
  await assert.rejects(mutation, { name: 'AbortError' })
  assert.deepEqual(calls.map(call => call.path), ['/api/v1/csrf'])
  assert.equal(transport.states.get('access-csrf')!.value, null, 'a late CSRF result from the old context must not replace current state')
})

test('anonymous login bootstrap remains valid and can fetch its CSRF token', async () => {
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const transport = accessApi(async (path, options = {}) => {
    calls.push({ path: String(path), options: options as Record<string, unknown> })
    return String(path).endsWith('/csrf') ? { csrf_token: 'bootstrap-token' } : { user: 'signed-in' }
  })
  transport.states.get('access-user')!.value = null
  await transport.api('/login', { method: 'POST', body: { email: 'actor@example.test' } })
  assert.deepEqual(calls.map(call => call.path), ['/api/v1/csrf', '/api/v1/login'])
  const headers = calls[1]!.options.headers as Record<string, string>
  assert.equal(headers['x-csrf-token'], 'bootstrap-token')
})

test('API queue preserves explicit headers and dispatches the captured company context', async () => {
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const transport = accessApi(async (path, options = {}) => {
    calls.push({ path: String(path), options: options as Record<string, unknown> })
    return { ok: true }
  })
  await transport.api('/erp/products', { headers: { 'X-Trace-Id': 'trace-1' } })
  assert.equal(calls.length, 1)
  const headers = calls[0]!.options.headers as Record<string, string>
  assert.equal(headers['x-company-id'], '3')
  assert.equal(headers['x-account-id'], undefined)
  assert.equal(headers['x-trace-id'], 'trace-1')
})

test('API queue discards an already queued read after the selected company changes', async () => {
  const firstResponse = deferred<unknown>()
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const transport = accessApi(async (path, options = {}) => {
    calls.push({ path: String(path), options: options as Record<string, unknown> })
    if (String(path).endsWith('/erp/contacts')) return firstResponse.promise
    return { ok: true }
  })
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const queued = transport.api('/erp/products')
  transport.states.get('access-user')!.value = identity(4)
  firstResponse.resolve({ data: [] })

  await first
  await assert.rejects(queued, { name: 'AbortError' })
  assert.deepEqual(calls.map(call => call.path), ['/api/v1/erp/contacts'])
  const firstHeaders = calls[0]!.options.headers as Record<string, string>
  assert.equal(firstHeaders['x-company-id'], '3')
})

test('queued API calls independently fence actor, account, and session generation changes', async () => {
  const mutations = [
    ['actor', (state: Map<string, Vue.Ref<unknown>>) => {
      state.get('access-user')!.value = { ...identity(), id: 8 }
    }],
    ['account', (state: Map<string, Vue.Ref<unknown>>) => {
      const next = identity()
      next.accounts = [...next.accounts, { id: 2, name: 'Other office', status: 'active', role: 'admin' }]
      next.current_account = next.accounts[1]!
      state.get('access-user')!.value = next
    }],
    ['session generation', (state: Map<string, Vue.Ref<unknown>>) => {
      state.get('access-session-generation')!.value = 1
    }]
  ] as const

  for (const [label, change] of mutations) {
    const firstResponse = deferred<unknown>()
    const calls: string[] = []
    const transport = accessApi(async (path) => {
      calls.push(String(path))
      if (String(path).endsWith('/erp/contacts')) return firstResponse.promise
      return { ok: true }
    })
    const first = transport.api('/erp/contacts')
    await Promise.resolve()
    const queued = transport.api('/erp/products')
    change(transport.states)
    firstResponse.resolve({ data: [] })
    await first
    await assert.rejects(queued, { name: 'AbortError' }, label)
    assert.deepEqual(calls, ['/api/v1/erp/contacts'], label)
  }
})

test('queued JSON body and nested query and params preserve invocation-time intent', async () => {
  const firstResponse = deferred<unknown>()
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const transport = accessApi(async (path, options = {}) => {
    calls.push({ path: String(path), options: options as Record<string, unknown> })
    if (String(path).endsWith('/erp/contacts')) return firstResponse.promise
    return { ok: true }
  })
  transport.states.get('access-csrf')!.value = 'existing-token'
  const body = { amount: 12, lines: [{ quantity: 1 }] }
  const query = { filters: ['open'], nested: { page: 1 } }
  const params = { sort: ['created_at'], nested: { direction: 'asc' } }
  const headers = new Headers({ 'Idempotency-Key': 'stable-key' })
  const options: Record<string, unknown> = { method: 'POST', body, headers, credentials: 'include' }
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const write = transport.api('/erp/sales', options)
  const read = transport.api('/erp/sales', { query, params })
  options.method = 'DELETE'
  options.body = { amount: 99 }
  options.credentials = 'omit'
  headers.set('Idempotency-Key', 'mutated-key')
  body.amount = 99
  body.lines[0]!.quantity = 8
  query.filters.push('closed')
  query.nested.page = 9
  params.sort[0] = 'updated_at'
  params.nested.direction = 'desc'
  firstResponse.resolve({ data: [] })
  await Promise.all([first, write, read])

  const dispatched = calls[1]!
  assert.equal(dispatched.path, '/api/v1/erp/sales')
  assert.equal(dispatched.options.method, 'POST')
  assert.deepEqual(dispatched.options.body, { amount: 12, lines: [{ quantity: 1 }] })
  assert.equal(dispatched.options.credentials, 'include')
  const sentHeaders = new Headers(dispatched.options.headers as HeadersInit)
  assert.equal(sentHeaders.get('Idempotency-Key'), 'stable-key')
  assert.equal(JSON.stringify(calls[2]!.options.query), '{"filters":["open"],"nested":{"page":1}}')
  assert.equal(JSON.stringify(calls[2]!.options.params), '{"sort":["created_at"],"nested":{"direction":"asc"}}')
})

test('queued Date and toJSON values keep their serialized invocation-time value and idempotency key', async () => {
  class MutableJsonValue {
    value: string
    constructor(value: string) { this.value = value }
    toJSON(key: string) { return { key, value: this.value } }
  }
  class ScalarJsonValue {
    value: string
    constructor(value: string) { this.value = value }
    toJSON() { return this.value }
  }
  const firstResponse = deferred<unknown>()
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const transport = accessApi(async (path, options = {}) => {
    calls.push({ path: String(path), options: options as Record<string, unknown> })
    if (String(path).endsWith('/erp/contacts')) return firstResponse.promise
    return { ok: true }
  })
  transport.states.get('access-csrf')!.value = 'existing-token'
  const date = new Date('2026-10-01T12:00:00.000Z')
  const jsonValue = new MutableJsonValue('before')
  const scalar = new ScalarJsonValue('scalar-before')
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const key = new Headers({ 'Idempotency-Key': 'date-key' })
  const write = transport.api('/erp/sales', {
    method: 'POST',
    body: { effective_at: date, nested: jsonValue },
    headers: key
  })
  const scalarWrite = transport.api('/erp/notes', { method: 'POST', body: scalar })
  date.setUTCFullYear(2030)
  jsonValue.value = 'after'
  scalar.value = 'scalar-after'
  key.set('Idempotency-Key', 'changed-key')
  firstResponse.resolve({ ok: true })
  await Promise.all([first, write, scalarWrite])

  assert.equal(JSON.stringify(calls[1]!.options.body), JSON.stringify({
    effective_at: '2026-10-01T12:00:00.000Z',
    nested: { key: 'nested', value: 'before' }
  }))
  assert.equal(new Headers(calls[1]!.options.headers as HeadersInit).get('Idempotency-Key'), 'date-key')
  assert.equal(JSON.stringify(calls[2]!.options.body), '"scalar-before"')
})

test('snapshot preserves an own JSON __proto__ key without changing the copied prototype', async () => {
  const firstResponse = deferred<unknown>()
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const transport = accessApi(async (path, options = {}) => {
    calls.push({ path: String(path), options: options as Record<string, unknown> })
    if (String(path).endsWith('/erp/contacts')) return firstResponse.promise
    return { ok: true }
  })
  transport.states.get('access-csrf')!.value = 'existing-token'
  const body = JSON.parse('{"__proto__":{"flag":true},"amount":12}') as Record<string, unknown>
  const originalPrototype = Object.getPrototypeOf(body)
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const write = transport.api('/erp/sales', { method: 'POST', body })
  firstResponse.resolve({ ok: true })
  await Promise.all([first, write])

  const captured = calls[1]!.options.body as Record<string, unknown>
  assert.equal(JSON.stringify(captured), '{"__proto__":{"flag":true},"amount":12}')
  assert.equal(Object.hasOwn(captured, '__proto__'), true)
  assert.equal(Object.getPrototypeOf(captured), Object.prototype)
  assert.equal(Object.getPrototypeOf(body), originalPrototype)
})

test('captured JSON values preserve installed ofetch body and query wire encoding', async () => {
  class ScalarJsonValue {
    value: string
    constructor(value: string) { this.value = value }
    toJSON() { return this.value }
  }
  const firstResponse = deferred<Response>()
  const sent: Array<{ url: string, body: BodyInit | null | undefined, headers: Headers }> = []
  const request = createFetch({
    fetch: async (input, init) => {
      const url = String(input)
      sent.push({ url, body: init?.body, headers: new Headers(init?.headers) })
      if (url.endsWith('/erp/contacts')) return firstResponse.promise
      return Response.json({ ok: true })
    }
  })
  const transport = accessApi(request as unknown as (...args: unknown[]) => Promise<unknown>)
  transport.states.get('access-csrf')!.value = 'existing-token'
  const date = new Date('2026-10-01T12:00:00.000Z')
  const scalar = new ScalarJsonValue('scalar-before')
  const reactiveAmount = Vue.ref(12)
  const reactiveQuery = Vue.reactive({ amount: reactiveAmount, date })
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const write = transport.api('/erp/notes', { method: 'POST', body: scalar })
  const read = transport.api('/erp/sales', { query: { effective_at: date, scalar, nested: { value: scalar }, reactive: reactiveQuery } })
  date.setUTCFullYear(2030)
  reactiveAmount.value = 99
  scalar.value = 'scalar-after'
  firstResponse.resolve(Response.json({ ok: true }))
  await Promise.all([first, write, read])

  assert.equal(sent[1]!.body, '"scalar-before"', 'top-level toJSON scalar must remain JSON-quoted by ofetch')
  assert.equal(sent[1]!.headers.get('content-type'), 'application/json')
  const query = new URL(sent[2]!.url, 'http://local').searchParams
  assert.equal(query.get('effective_at'), '"2026-10-01T12:00:00.000Z"')
  assert.equal(query.get('scalar'), '"scalar-before"')
  assert.equal(query.get('nested'), '{"value":"scalar-before"}')
  assert.equal(query.get('reactive'), '{"amount":12,"date":"2026-10-01T12:00:00.000Z"}')
})

test('queued Vue reactive body preserves ref unwrapping at the installed ofetch boundary', async () => {
  const firstResponse = deferred<Response>()
  const sent: Array<{ url: string, body: BodyInit | null | undefined }> = []
  const request = createFetch({
    fetch: async (input, init) => {
      const url = String(input)
      sent.push({ url, body: init?.body })
      if (url.endsWith('/erp/contacts')) return firstResponse.promise
      return Response.json({ ok: true })
    }
  })
  const transport = accessApi(request as unknown as (...args: unknown[]) => Promise<unknown>)
  transport.states.get('access-csrf')!.value = 'existing-token'
  const amount = Vue.ref(12)
  const date = new Date('2026-10-01T12:00:00.000Z')
  const body = Vue.reactive({ amount, effective_at: date })
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const write = transport.api('/erp/sales', { method: 'POST', body })
  amount.value = 99
  date.setUTCFullYear(2030)
  firstResponse.resolve(Response.json({ ok: true }))
  await Promise.all([first, write])

  assert.equal(sent[1]!.body, '{"amount":12,"effective_at":"2026-10-01T12:00:00.000Z"}')
})

test('queued form-urlencoded body preserves fields instead of exposing the snapshot hook', async () => {
  class FormFields {
    value: string
    constructor(value: string) { this.value = value }
    toJSON() { return `json:${this.value}` }
  }
  const firstResponse = deferred<Response>()
  const sent: Array<{ url: string, body: BodyInit | null | undefined, headers: Headers }> = []
  const request = createFetch({
    fetch: async (input, init) => {
      const url = String(input)
      sent.push({ url, body: init?.body, headers: new Headers(init?.headers) })
      if (url.endsWith('/erp/contacts')) return firstResponse.promise
      return Response.json({ ok: true })
    }
  })
  const transport = accessApi(request as unknown as (...args: unknown[]) => Promise<unknown>)
  transport.states.get('access-csrf')!.value = 'existing-token'
  const body = new FormFields('before')
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const write = transport.api('/erp/form-test', {
    method: 'POST',
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  })
  body.value = 'after'
  firstResponse.resolve(Response.json({ ok: true }))
  await Promise.all([first, write])

  assert.equal(sent[1]!.body, 'value=before')
  assert.equal(sent[1]!.headers.get('content-type'), 'application/x-www-form-urlencoded')
})

test('queued body snapshots match ofetch exact form content-type and string rules', async () => {
  const firstResponse = deferred<Response>()
  const sent: Array<{ url: string, body: BodyInit | null | undefined }> = []
  const request = createFetch({
    fetch: async (input, init) => {
      const url = String(input)
      sent.push({ url, body: init?.body })
      if (url.endsWith('/erp/contacts')) return firstResponse.promise
      return Response.json({ ok: true })
    }
  })
  const transport = accessApi(request as unknown as (...args: unknown[]) => Promise<unknown>)
  transport.states.get('access-csrf')!.value = 'existing-token'
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const rawString = transport.api('/erp/raw', {
    method: 'POST', body: 'raw-value', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  })
  const parameterized = transport.api('/erp/parameterized', {
    method: 'POST', body: { field: 'parameterized' }, headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }
  })
  const cased = transport.api('/erp/cased', {
    method: 'POST', body: { field: 'cased' }, headers: { 'Content-Type': 'Application/X-Www-Form-Urlencoded' }
  })
  firstResponse.resolve(Response.json({ ok: true }))
  await Promise.all([first, rawString, parameterized, cased])

  assert.equal(sent[1]!.body, 'raw-value')
  assert.equal(sent[2]!.body, '{"field":"parameterized"}')
  assert.equal(sent[3]!.body, '{"field":"cased"}')
})

test('pre-aborted mutations do not enqueue CSRF or mutation requests', async () => {
  const calls: string[] = []
  const transport = accessApi(async (path) => {
    calls.push(String(path))
    return { csrf_token: 'unexpected' }
  })
  const controller = new AbortController()
  controller.abort()
  await assert.rejects(transport.api('/erp/products', { method: 'POST', body: {}, signal: controller.signal }), { name: 'AbortError' })
  assert.deepEqual(calls, [])
})

test('aborting a queued CSRF preflight settles immediately and preserves unrelated and fresh work', async () => {
  const hold = deferred<unknown>()
  const calls: string[] = []
  const transport = accessApi(async (path) => {
    const url = String(path)
    calls.push(url)
    if (url.endsWith('/erp/contacts')) return hold.promise
    if (url.endsWith('/csrf')) return { csrf_token: 'fresh-token' }
    return { ok: true }
  })
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const controller = new AbortController()
  const cancelled = transport.api('/erp/products', { method: 'POST', body: {}, signal: controller.signal })
  const unrelated = transport.api('/erp/units')
  controller.abort()
  await assert.rejects(cancelled, { name: 'AbortError' })
  assert.deepEqual(calls, ['/api/v1/erp/contacts'], 'cancelled preflight must not wait to dispatch')
  hold.resolve({ ok: true })
  await Promise.all([first, unrelated])
  assert.deepEqual(calls, ['/api/v1/erp/contacts', '/api/v1/erp/units'])
  await transport.api('/erp/products', { method: 'POST', body: {} })
  assert.deepEqual(calls.slice(-2), ['/api/v1/csrf', '/api/v1/erp/products'])
})

test('cancellation during active CSRF preflight reaches transport and permits fresh work', async () => {
  const csrfStarted = deferred<AbortSignal | undefined>()
  const calls: string[] = []
  const transport = accessApi(async (path, options = {}) => {
    const url = String(path)
    calls.push(url)
    if (url.endsWith('/csrf')) {
      const signal = (options as Record<string, unknown>).signal as AbortSignal | undefined
      csrfStarted.resolve(signal)
      return new Promise((_, reject) => signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')), { once: true }))
    }
    return { ok: true }
  })
  const controller = new AbortController()
  const cancelled = transport.api('/erp/products', { method: 'POST', body: {}, signal: controller.signal })
  assert.equal(await csrfStarted.promise, controller.signal)
  controller.abort()
  await assert.rejects(cancelled, { name: 'AbortError' })
  assert.deepEqual(calls, ['/api/v1/csrf'])
  transport.states.get('access-csrf')!.value = 'fresh-token'
  await transport.api('/erp/products', { method: 'POST', body: {} })
  assert.equal(calls.at(-1), '/api/v1/erp/products')
})

test('queued multipart PUT retains filename, blob, method adaptation, and SSR response cookies', async () => {
  const hold = deferred<unknown>()
  const calls: Array<{ path: string, options: Record<string, unknown> }> = []
  const appended: Array<[string, string]> = []
  const event = { node: { res: { appendHeader: (name: string, value: string) => appended.push([name, value]) } } }
  const transport = accessApi(async (path, options = {}) => {
    const call = { path: String(path), options: options as Record<string, unknown> }
    calls.push(call)
    if (call.path.endsWith('/erp/contacts')) return hold.promise
    const hook = call.options.onResponse as ((context: { response: Response }) => void)[]
    for (const run of hook) run({ response: { headers: { getSetCookie: () => ['session=rotated; Path=/', 'csrf=rotated; Path=/'] } } as unknown as Response })
    return { ok: true }
  }, {}, true, event)
  transport.states.get('access-csrf')!.value = 'cached-token'
  const first = transport.api('/erp/contacts')
  await Promise.resolve()
  const form = new FormData()
  form.set('certificate', new Blob(['test']), 'cert.pfx')
  const update = transport.api('/erp/certificates', { method: 'PUT', body: form })
  hold.resolve({ ok: true })
  await Promise.all([first, update])
  assert.equal(calls[1]!.options.method, 'POST')
  const adapted = calls[1]!.options.body as FormData
  assert.equal(adapted.get('_method'), 'PUT')
  assert.equal((adapted.get('certificate') as File).name, 'cert.pfx')
  assert.deepEqual(appended, [['set-cookie', 'session=rotated; Path=/'], ['set-cookie', 'csrf=rotated; Path=/']])
})

test('API queue rechecks context in the dispatch microtask', async () => {
  const calls: string[] = []
  const transport = accessApi(async (path) => {
    calls.push(String(path))
    return { ok: true }
  })
  const pending = transport.api('/erp/products')
  transport.states.get('access-user')!.value = identity(4)
  transport.states.get('access-session-generation')!.value = 1
  await assert.rejects(pending, { name: 'AbortError' })
  assert.deepEqual(calls, [])
})
