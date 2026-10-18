import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { test } from 'node:test'
import { ref, type Ref } from 'vue'

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith('#shared/'))
      return nextResolve(new URL(`../shared/${specifier.slice(8)}.ts`, import.meta.url).href, context)
    return nextResolve(specifier, context)
  }
})

const { useAuth } = await import('../app/composables/useAuth.ts')

function session() {
  let currentContext = true
  let cacheCleared = false
  const states = new Map<string, Ref<unknown>>([
    ['access-user', ref({ id: 1 })],
    ['clients-selection', ref({ id: 99 })],
    ['__nuxt_devtools__', ref({ timeSsrStart: 123 })]
  ])
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
    useState: (key: string, initial: () => unknown) => {
      if (!states.has(key)) states.set(key, ref(initial()))
      return states.get(key)
    },
    useAccessApi: () => ({
      csrf: ref('expired-token'),
      refreshCsrf: async () => {},
      api: async () => {
        currentContext = false
        throw { statusCode: 401 }
      }
    }),
    clearNuxtData: () => {
      assertContext()
      cacheCleared = true
    },
    clearNuxtState: (predicate: (key: string) => boolean) => {
      assertContext()
      for (const [key, state] of states) {
        if (predicate(key)) state.value = undefined
      }
    }
  })
  return { auth: useAuth(), states, cacheCleared: () => cacheCleared }
}

test('an asynchronous SSR 401 clears tenant state using the captured Nuxt request context', async () => {
  const view = session()
  assert.equal(await view.auth.refresh(), null)
  assert.equal(view.auth.user.value, null)
  assert.equal(view.auth.resolved.value, true)
  assert.equal(view.states.get('clients-selection')!.value, undefined)
  assert.equal(view.cacheCleared(), true)
})

test('session invalidation clears tenant state while preserving Nuxt DevTools runtime state', () => {
  const view = session()
  view.auth.invalidateSession()
  assert.equal(view.states.get('clients-selection')!.value, undefined)
  assert.deepEqual(view.states.get('__nuxt_devtools__')!.value, { timeSsrStart: 123 })
  assert.equal(view.auth.user.value, null)
})
