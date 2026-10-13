import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ref } from 'vue'
import { registerHooks } from 'node:module'
import type { Identity } from '../shared/types/access.ts'

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith('#shared/'))
      return nextResolve(new URL(`../shared/${specifier.slice(8)}.ts`, import.meta.url).href, context)
    return nextResolve(specifier, context)
  }
})

const member = { id: 12, name: 'Office', status: 'active' as const, role: 'operador' as const }
const identity: Identity = { id: 1, name: 'Operator', email: 'operator@example.test', is_super_admin: false, accounts: [member], current_account: member }

test('business requests use current membership, same-origin proxy and csrf for multipart writes without retries', async () => {
  const states = new Map<string, ReturnType<typeof ref>>([['access-user', ref(identity)]])
  const calls: { path: string, options: Record<string, unknown> }[] = []
  Object.assign(globalThis, {
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
