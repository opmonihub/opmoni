import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ref } from 'vue'
import { registerHooks } from 'node:module'
import type { Identity } from '../shared/types/access.ts'

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith('#shared/'))
      return nextResolve(new URL(`../shared/${specifier.slice(8)}.ts`, import.meta.url).href, context)
    if (specifier.startsWith('~/'))
      return nextResolve(new URL(`../app/${specifier.slice(2)}.ts`, import.meta.url).href, context)
    return nextResolve(specifier, context)
  }
})

const member = { id: 12, name: 'Office', status: 'active' as const, role: 'operador' as const }
const identity: Identity = { id: 1, name: 'Operator', email: 'operator@example.test', is_super_admin: false, accounts: [member], current_account: member, companies: [], current_company: null }

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

test('business requests use current membership, same-origin proxy and csrf for multipart writes without retries', async () => {
  const states = new Map<string, ReturnType<typeof ref>>([['access-user', ref(identity)]])
  const calls: { path: string, options: Record<string, unknown> }[] = []
  Object.assign(globalThis, {
    useNuxtApp: () => (globalThis as { __testNuxtApp?: object }).__testNuxtApp ??= {},
    useState: (key: string, initial: () => unknown) => {
      if (!states.has(key)) states.set(key, ref(initial()))
      return states.get(key)
    },
    useRequestEvent: () => undefined,
    useRequestFetch: () => async (path: string, options: Record<string, unknown>) => {
      calls.push({ path, options })
      return path.endsWith('/csrf') ? { csrf_token: 'csrf-test' } : { ok: true }
    }
  })
  const { useAccessApi } = await import('../app/composables/useAccessApi.ts')
  const { api } = useAccessApi()
  const body = new FormData()
  body.set('certificate', new Blob(['test']), 'certificate.pfx')
  await api('/office-certificate', { method: 'POST', body, headers: { 'X-Custom': 'preserved' } })
  const write = calls.at(-1)!
  assert.equal(write.path, '/api/v1/office-certificate')
  assert.equal(write.options.body, body)
  assert.equal(write.options.retry, 0)
  const headers = new Headers(write.options.headers as HeadersInit)
  assert.equal(headers.get('X-Account-Id'), '12')
  assert.equal(new Headers({ ...(write.options.headers as Record<string, string>) }).get('X-Account-Id'), '12', 'Nitro SSR header spreading must preserve account assertion')
  assert.equal(headers.get('X-CSRF-Token'), 'csrf-test')
  assert.equal(headers.get('X-Custom'), 'preserved')
  assert.equal(headers.get('Content-Type'), null, 'fetch owns the multipart boundary')
  await api('/fiscal/documents/9/xml', { responseType: 'blob', params: { format: 'xml' } })
  assert.equal(calls.at(-1)!.options.responseType, 'blob')
  assert.deepEqual(calls.at(-1)!.options.params, { format: 'xml' })
  await api('/work/tasks/3', { method: 'DELETE' })
  assert.equal(calls.at(-1)!.options.method, 'DELETE')
  await api('/work/tasks/3', { method: 'PUT', body: { title: 'Updated' } })
  assert.equal(calls.at(-1)!.options.method, 'PUT')
  states.get('access-user')!.value = { ...identity, current_account: null }
  await assert.rejects(api('/clients'), /account|escritório/i)
  await api('/serpro/connection')
  assert.equal(new Headers(calls.at(-1)!.options.headers as HeadersInit).get('X-Account-Id'), null)
  await api('/admin/plans')
  assert.equal(new Headers(calls.at(-1)!.options.headers as HeadersInit).get('X-Account-Id'), null)
})

test('tenant permissions require a matching active membership even for platform administrators', async () => {
  const { tenantPermissions } = await import('../shared/utils/business.ts')
  assert.equal(tenantPermissions(identity).canManageClients, true)
  assert.equal(tenantPermissions(identity).canManageMembers, false)
  const outsider = { ...identity, is_super_admin: true, accounts: [] }
  assert.equal(tenantPermissions(outsider).canManageWork, false)
  assert.equal(tenantPermissions({ ...identity, current_account: { ...member, status: 'suspended' } }).canManageClients, false)
})

test('global navigation admits platform administration without an office and protects tenant modules', async () => {
  let active = { ...identity, is_super_admin: true, accounts: [], current_account: null } as Identity
  Object.assign(globalThis, {
    defineNuxtRouteMiddleware: (handler: unknown) => handler,
    useAuth: () => ({ user: ref(active), resolved: ref(true), refresh: async () => active }),
    useAccessApi: () => ({ api: async () => ({ registration_available: false }) }),
    navigateTo: (path: string) => path
  })
  const middleware = (await import('../app/middleware/access.global.ts')).default
  assert.equal(await middleware({ path: '/admin/planos' } as never, {} as never), undefined)
  assert.equal(await middleware({ path: '/monitoring' } as never, {} as never), '/acesso-indisponivel')
  active = identity
  assert.equal(await middleware({ path: '/work/processos/10' } as never, {} as never), undefined)
  assert.equal(await middleware({ path: '/admin/planos' } as never, {} as never), '/')
})

test('business authentication failures clear only the session that issued the request', async () => {
  const generation = ref(1)
  let invalidate = 0
  let redirects = 0
  let rejectRequest: (failure: unknown) => void = () => {}
  Object.assign(globalThis, {
    defineNuxtPlugin: (setup: unknown) => setup,
    useState: () => generation,
    useAccessApi: () => ({ api: async () => await new Promise((_, reject) => { rejectRequest = reject }) }),
    useAuth: () => ({ invalidateSession: () => { invalidate++ } }),
    navigateTo: () => { redirects++ }
  })
  const setup = (await import('../app/plugins/api.ts')).default
  const plugin = await setup({ runWithContext: (callback: () => unknown) => callback() } as never)
  const stale = plugin.provide.api('/clients')
  generation.value++
  rejectRequest({ statusCode: 401 })
  await assert.rejects(stale)
  assert.equal(invalidate, 0)
  const current = plugin.provide.api('/clients')
  rejectRequest({ statusCode: 401 })
  await assert.rejects(current)
  assert.equal(invalidate, 1)
  assert.equal(redirects, 1)
})

test('real auth transitions share transport admission and fence stale work before fresh context requests', async () => {
  const makeIdentity = (actorId: number, accountId: number, companyId: number): Identity => {
    const account = { id: accountId, name: `Office ${accountId}`, status: 'active' as const, role: 'admin' as const }
    const company = { id: companyId, name: `Company ${companyId}`, status: 'active' as const, access_kind: 'direct' as const, role: 'owner', permissions: ['*'], delegated_office_id: null }
    return { ...identity, id: actorId, accounts: [account], current_account: account, companies: [company], current_company: company }
  }
  const cases = [
    { name: 'login', path: '/login', initial: null, next: makeIdentity(2, 20, 4) },
    { name: 'account switch', path: '/account/switch', initial: makeIdentity(1, 12, 3), next: makeIdentity(1, 20, 3) },
    { name: 'company switch', path: '/erp/company/switch', initial: makeIdentity(1, 12, 3), next: makeIdentity(1, 12, 4) },
    { name: 'logout', path: '/logout', initial: makeIdentity(1, 12, 3), next: null }
  ] as const

  for (const transition of cases) {
    const states = new Map<string, ReturnType<typeof ref>>([
      ['access-user', ref(transition.initial)],
      ['access-csrf', ref<string | null>('old-token')],
      ['access-session-generation', ref(0)],
      ['access-resolved', ref(false)],
      ['access-generation', ref(0)]
    ])
    const held = deferred<unknown>()
    const calls: { path: string, options: Record<string, unknown> }[] = []
    const app = { runWithContext: (callback: () => unknown) => callback() }
    Object.assign(globalThis, {
      useNuxtApp: () => app,
      useState: (key: string, initialValue: () => unknown) => {
        if (!states.has(key)) states.set(key, ref(initialValue()))
        return states.get(key)
      },
      useRequestEvent: () => undefined,
      useRequestFetch: () => async (path: string, options: Record<string, unknown>) => {
        calls.push({ path, options })
        if (path.endsWith('/erp/contacts')) return held.promise
        if (path.endsWith('/csrf')) return { csrf_token: 'new-token' }
        if (path.endsWith(transition.path)) return transition.next ?? {}
        if (path.endsWith('/failing')) throw new Error('one attempt only')
        return { ok: true }
      },
      clearNuxtData: () => {},
      clearNuxtState: () => {},
      navigateTo: () => undefined
    })
    const { useAccessApi } = await import('../app/composables/useAccessApi.ts')
    const { useAuth } = await import('../app/composables/useAuth.ts')
    const access = useAccessApi()
    Object.assign(globalThis, { useAccessApi: () => access })
    const auth = useAuth()
    const active = access.api('/erp/contacts')
    await Promise.resolve()
    let changing: Promise<unknown>
    if (transition.name === 'login') changing = auth.authenticate('/login', { email: 'user@example.test' })
    else if (transition.name === 'account switch') changing = auth.switchAccount(20)
    else if (transition.name === 'company switch') changing = auth.switchCompany(4)
    else changing = auth.logout()
    const stale = access.api('/erp/products')
    held.resolve({ ok: true })
    await active
    await changing
    await assert.rejects(stale, { name: 'AbortError' }, `${transition.name} must reject queued stale work`)

    assert.deepEqual(auth.user.value, transition.next, `${transition.name} must finish committing identity`)
    assert.equal(access.csrf.value, transition.name === 'logout' ? null : 'new-token', `${transition.name} must finish its CSRF transition`)
    assert.equal(calls.some(call => call.path.endsWith('/erp/products')), false, `${transition.name} must not send stale work`)
    const transitionCall = calls.find(call => call.path.endsWith(transition.path))!
    assert.ok(transitionCall, `${transition.name} request must use the shared transport queue`)
    if (transition.next) {
      await access.api('/clients')
      let freshCall = calls.at(-1)!
      let headers = new Headers(freshCall.options.headers as HeadersInit)
      assert.equal(headers.get('X-CSRF-Token'), 'new-token')
      assert.equal(headers.get('X-Account-Id'), String(transition.next.current_account!.id))
      await access.api('/erp/units')
      freshCall = calls.at(-1)!
      headers = new Headers(freshCall.options.headers as HeadersInit)
      assert.equal(headers.get('X-CSRF-Token'), 'new-token')
      assert.equal(headers.get('X-Company-Id'), String(transition.next.current_company!.id))
    } else {
      await access.api('/registration-status')
      const freshCall = calls.at(-1)!
      const headers = new Headers(freshCall.options.headers as HeadersInit)
      assert.equal(headers.get('X-CSRF-Token'), null)
      assert.equal(headers.get('X-Account-Id'), null)
      assert.equal(headers.get('X-Company-Id'), null)
    }

    await assert.rejects(access.api('/admin/failing'), /one attempt only/)
    assert.equal(calls.filter(call => call.path.endsWith('/failing')).length, 1, 'failed work must not be replayed')
  }
})
