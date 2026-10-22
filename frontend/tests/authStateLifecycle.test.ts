import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { test } from 'node:test'
import { reactive, ref, toRef, type Ref } from 'vue'
import type { Identity } from '../shared/types/access.ts'

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith('#shared/'))
      return nextResolve(new URL(`../shared/${specifier.slice(8)}.ts`, import.meta.url).href, context)
    return nextResolve(specifier, context)
  }
})

const { useAuth } = await import('../app/composables/useAuth.ts')

interface ColorMode {
  preference: string
  value: string
  unknown: boolean
  forced: boolean
}

function session(api: (path: string) => Promise<unknown> = async () => {
  throw { statusCode: 401 }
}, refreshCsrf: (csrf: Ref<string | null>) => Promise<void> = async () => {}) {
  let currentContext = true
  let cacheCleared = false
  const csrf = ref<string | null>('expired-token')
  const payload = reactive<Record<string, unknown>>({
    'access-user': { id: 1 },
    'clients-selection': { id: 99 },
    'company-draft': { name: 'Unsaved' },
    'private-operation': { id: 42 },
    'color-mode-private-selection': { id: 99 },
    '__nuxt_devtools__': { timeSsrStart: 123 }
  })
  const states = new Map<string, Ref<unknown>>()
  const state = <T>(key: string, initial?: () => T): Ref<T | undefined> => {
    const value = toRef(payload, key)
    if (value.value === undefined && initial)
      value.value = initial()
    states.set(key, value)
    return value as Ref<T | undefined>
  }
  for (const key of Object.keys(payload))
    state(key)
  // The installed plugin initializes once; its composable only looks up this state.
  const colorMode = state<ColorMode>('color-mode', () => reactive({
    preference: 'dark',
    value: 'dark',
    unknown: false,
    forced: false
  })).value!
  const useColorMode = () => state<ColorMode>('color-mode').value!
  const assertContext = () => assert.equal(currentContext, true, 'Nuxt context must be restored before cache invalidation')
  Object.assign(globalThis, {
    useNuxtApp: () => {
      assertContext()
      return {
        runWithContext(callback: () => unknown) {
          const previous = currentContext
          currentContext = true
          try {
            return callback()
          } finally {
            currentContext = previous
          }
        }
      }
    },
    useState: state,
    useAccessApi: () => ({
      csrf,
      refreshCsrf: () => refreshCsrf(csrf),
      api: async (path: string) => {
        currentContext = false
        return api(path)
      }
    }),
    clearNuxtData: () => {
      assertContext()
      cacheCleared = true
    },
    clearNuxtState: (predicate: (key: string) => boolean) => {
      assertContext()
      for (const key of Object.keys(payload)) {
        if (predicate(key)) Reflect.deleteProperty(payload, key)
      }
    }
  })
  return { auth: useAuth(), states, csrf, colorMode, useColorMode, cacheCleared: () => cacheCleared }
}

function assertPrivateDataCleared(view: ReturnType<typeof session>) {
  for (const key of ['clients-selection', 'company-draft', 'private-operation', 'color-mode-private-selection'])
    assert.equal(view.states.get(key)!.value, undefined, `${key} must be invalidated`)
  assert.equal(view.cacheCleared(), true)
  assert.deepEqual(view.states.get('__nuxt_devtools__')!.value, { timeSsrStart: 123 })
}

function assertThemePreserved(view: ReturnType<typeof session>) {
  // A fresh consumer performs the same direct preference read as UserMenu.
  assert.equal(view.useColorMode().preference, 'dark')
  assert.equal(view.useColorMode(), view.colorMode)
}

function identity(accountId: number, companyId: number): Identity {
  const account = { id: accountId, name: 'Office', status: 'active' as const, role: 'admin' as const }
  const company = {
    id: companyId,
    name: 'Company',
    status: 'active' as const,
    access_kind: 'direct' as const,
    role: 'admin',
    permissions: [],
    delegated_office_id: null
  }
  return {
    id: 1,
    name: 'User',
    email: 'user@example.test',
    is_super_admin: false,
    accounts: [account],
    current_account: account,
    companies: [company],
    current_company: company
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

test('an asynchronous SSR 401 clears tenant state using the captured Nuxt request context', async () => {
  const view = session()
  assert.equal(await view.auth.refresh(), null)
  assert.equal(view.auth.user.value, null)
  assert.equal(view.auth.resolved.value, true)
  assert.equal(view.states.get('clients-selection')!.value, undefined)
  assert.equal(view.cacheCleared(), true)
  assert.equal(view.csrf.value, null)
  assert.equal(view.states.get('access-generation')!.value, 2)
  assert.equal(view.states.get('access-session-generation')!.value, 1)
  assertPrivateDataCleared(view)
  assertThemePreserved(view)
})

test('session invalidation clears tenant state while preserving Nuxt DevTools runtime state', () => {
  const view = session()
  view.auth.invalidateSession()
  assert.equal(view.states.get('clients-selection')!.value, undefined)
  assert.deepEqual(view.states.get('__nuxt_devtools__')!.value, { timeSsrStart: 123 })
  assert.equal(view.auth.user.value, null)
  assert.equal(view.auth.resolved.value, true)
  assert.equal(view.csrf.value, null)
  assert.equal(view.states.get('access-generation')!.value, 1)
  assert.equal(view.states.get('access-session-generation')!.value, 1)
  assertPrivateDataCleared(view)
  assertThemePreserved(view)
})

for (const target of ['Company', 'Account'] as const) {
  test(`${target} switch preserves a fresh theme consumer while CSRF rotation is pending`, async () => {
    const rotationStarted = deferred<undefined>()
    const rotation = deferred<undefined>()
    const nextIdentity = identity(target === 'Account' ? 2 : 1, 2)
    const view = session(async (path) => {
      assert.equal(path, target === 'Company' ? '/erp/company/switch' : '/account/switch')
      return nextIdentity
    }, async (csrf) => {
      rotationStarted.resolve(undefined)
      await rotation.promise
      csrf.value = 'rotated-token'
    })
    assertThemePreserved(view)
    let completed = false
    const switching = (target === 'Company' ? view.auth.switchCompany(2) : view.auth.switchAccount(2))
      .then(() => { completed = true })
    await rotationStarted.promise
    try {
      assert.equal(completed, false)
      assert.deepEqual(view.auth.user.value, nextIdentity)
      assert.equal(view.auth.currentCompany.value?.id, 2)
      assert.equal(view.auth.currentAccount.value?.id, target === 'Account' ? 2 : 1)
      assert.equal(view.csrf.value, null)
      assert.equal(view.states.get('access-generation')!.value, 1)
      assert.equal(view.states.get('access-session-generation')!.value, 1)
      assertPrivateDataCleared(view)
      assertThemePreserved(view)
    } finally {
      rotation.resolve(undefined)
      await switching
    }
    assert.equal(completed, true)
    assert.equal(view.csrf.value, 'rotated-token')
    assertThemePreserved(view)
  })
}

for (const unauthorized of [false, true]) {
  test(`logout ${unauthorized ? 'with a 401' : 'on success'} preserves theme while clearing session and private state`, async () => {
    const view = session(async (path) => {
      assert.equal(path, '/logout')
      if (unauthorized)
        throw { statusCode: 401 }
      return {}
    })
    await view.auth.logout()
    assert.equal(view.auth.user.value, null)
    assert.equal(view.auth.resolved.value, true)
    assert.equal(view.csrf.value, null)
    assert.equal(view.states.get('access-generation')!.value, 1)
    assert.equal(view.states.get('access-session-generation')!.value, 1)
    assertPrivateDataCleared(view)
    assertThemePreserved(view)
  })
}

test('a superseded refresh still returns null for its 401 while preserving the newer identity', async () => {
  const older = deferred<unknown>()
  let requests = 0
  const newerIdentity = { id: 2 }
  const view = session(async () => {
    requests++
    return requests === 1 ? older.promise : newerIdentity
  })
  const olderRefresh = view.auth.refresh()
  await Promise.resolve()
  assert.equal(requests, 1)
  assert.deepEqual(await view.auth.refresh(), newerIdentity)
  older.reject({ statusCode: 401 })
  assert.equal(await olderRefresh, null)
  assert.deepEqual(view.auth.user.value, newerIdentity)
  assert.equal(view.states.get('access-generation')!.value, 2)
  assert.equal(view.states.get('access-session-generation')!.value, 0)
  assert.equal(view.csrf.value, 'expired-token')
  assert.equal(view.cacheCleared(), false)
  assert.deepEqual(view.states.get('clients-selection')!.value, { id: 99 })
  assertThemePreserved(view)
})
