import assert from 'node:assert/strict'
import { test } from 'node:test'
import { registerHooks } from 'node:module'
import { ref, nextTick, effectScope } from 'vue'
import { accessDestination, apiFailure, passwordLength } from './shared/utils/access.ts'
import type { Identity, Membership } from './shared/types/access.ts'

const office = (id: number, status: Membership['status'] = 'active'): Membership => ({ id, name: 'Office', role: 'admin', status })
const identity = (accounts: Membership[], current_account: Membership | null = null): Identity => ({ id: 1, name: 'Owner', email: 'owner@example.test', is_super_admin: false, accounts, current_account })
test('multi-office login enters home without a profile-card gate', () => assert.equal(accessDestination(identity([office(1), office(2)], office(1))), '/'))
test('missing authorized context never renders tenant home', () => assert.equal(accessDestination(identity([office(1), office(2)])), '/acesso-indisponivel'))
test('selected active office survives reload routing', () => assert.equal(accessDestination(identity([office(1), office(2)], office(2))), '/'))
test('zero active offices remain unavailable', () => assert.equal(accessDestination(identity([office(1, 'suspended')])), '/acesso-indisponivel'))
test('suspended selection remains unavailable with alternate memberships', () => assert.equal(accessDestination(identity([office(1, 'suspended'), office(2)], office(1, 'suspended'))), '/acesso-indisponivel'))
test('temporary service failure is not invalid credentials', () => assert.match(apiFailure({ statusCode: 503 }).message, /temporariamente/))
test('rate limits have distinct retry feedback', () => assert.match(apiFailure({ statusCode: 429 }).message, /tentativas/))

test('Unicode password bounds count characters and preserve exact whitespace', () => {
  assert.equal(passwordLength('🔐'.repeat(8)), 8)
  assert.equal(passwordLength('🔐'.repeat(128)), 128)
  assert.equal(passwordLength('🔐'.repeat(129)), 129)
  assert.equal(passwordLength('  senha  '), 9)
})

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith('#shared/'))
      return nextResolve(new URL(`./shared/${specifier.slice(8)}.ts`, import.meta.url).href, context)
    return nextResolve(specifier, context)
  }
})

test('logout completes when the server session already expired and preserves state on service failure', async () => {
  const { useAuth } = await import('./app/composables/useAuth.ts')
  const states = new Map<string, ReturnType<typeof ref>>()
  let failure: unknown = { statusCode: 401 }
  let response: (path: string) => Promise<unknown> = async () => {
    throw failure
  }
  let cleared = 0
  Object.assign(globalThis, {
    useState: (key: string, initial: () => unknown) => {
      if (!states.has(key)) states.set(key, ref(initial()))
      return states.get(key)
    },
    useAccessApi: () => ({ api: (path: string) => response(path), csrf: ref('old-token'), refreshCsrf: async () => {} }),
    clearNuxtData: () => { cleared++ },
    clearNuxtState: () => {},
    navigateTo: async () => {}
  })
  const auth = useAuth()
  auth.user.value = identity([office(1)], office(1))
  await auth.logout()
  assert.equal(auth.user.value, null)
  assert.equal(auth.resolved.value, true)
  assert.equal(cleared, 1)
  auth.user.value = identity([office(1)], office(1))
  failure = { statusCode: 503 }
  await assert.rejects(auth.logout())
  assert.equal(auth.user.value?.id, 1)
  assert.equal(cleared, 1)
  let finishLogout: () => void = () => {}
  response = async path => path === '/logout'
    ? await new Promise<void>((resolve) => { finishLogout = resolve })
    : identity([office(1)], office(1))
  const pendingLogout = auth.logout()
  await auth.refresh()
  finishLogout()
  await pendingLogout
  assert.equal(auth.user.value, null, 'a focus refresh must not cancel successful logout')

  auth.user.value = identity([office(1)], office(1))
  const newIdentity = { ...identity([office(2)], office(2)), id: 2 }
  response = async path => path === '/logout'
    ? await new Promise<void>((resolve) => { finishLogout = resolve })
    : newIdentity
  const staleLogout = auth.logout()
  await auth.authenticate('/login', {})
  finishLogout()
  await staleLogout
  assert.equal(auth.user.value?.id, 2, 'a stale logout response must not clear a newer login')
})

test('focus returns a reactivated office to home and preserves active settings routes', async () => {
  const { focusAccessDestination } = await import('./shared/utils/access.ts')
  const active = identity([office(1)], office(1))
  const suspended = identity([office(1, 'suspended')], office(1, 'suspended'))
  assert.equal(focusAccessDestination(active, '/acesso-indisponivel'), '/')
  assert.equal(focusAccessDestination(active, '/settings/profile'), null)
  assert.equal(focusAccessDestination(suspended, '/settings/profile'), '/acesso-indisponivel')
  assert.equal(focusAccessDestination({ ...suspended, is_super_admin: true }, '/admin/contas'), null)
  assert.equal(focusAccessDestination(suspended, '/admin/contas'), '/acesso-indisponivel')
})

test('account status changes reset pagination and clear filters loads only once', async () => {
  const { useAccountFilters } = await import('./app/composables/useAccountFilters.ts')
  const scope = effectScope()
  const requests: { q: string, status: string, page: number }[] = []
  const filters = scope.run(() => useAccountFilters(() => {
    requests.push({ q: filters.q.value, status: filters.status.value, page: filters.page.value })
  }))!
  try {
    filters.page.value = 3
    await nextTick()
    requests.length = 0
    filters.status.value = 'suspended'
    await nextTick()
    assert.deepEqual(requests, [{ q: '', status: 'suspended', page: 1 }])
    filters.q.value = 'Office'
    requests.length = 0
    filters.clearFilters()
    await nextTick()
    assert.deepEqual(requests, [{ q: '', status: 'all', page: 1 }])
    requests.length = 0
    filters.clearFilters()
    await nextTick()
    assert.equal(requests.length, 1)
  } finally {
    scope.stop()
  }
})
