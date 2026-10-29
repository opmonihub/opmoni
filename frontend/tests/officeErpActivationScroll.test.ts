import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { test } from 'node:test'

type ScrollState = {
  router: { options: { scrollBehaviorType: string }, currentRoute: { value: { fullPath: string } } }
  app: { hooks: { hookOnce: (name: string, callback: () => void) => void } }
}

let scrollState: ScrollState | undefined

export function useNuxtApp() {
  return scrollState?.app
}

export function useRouter() {
  return scrollState?.router
}

export function isChangingPage() {
  return false
}

function fakeDocument(onQuery: () => void) {
  return {
    querySelector() {
      onQuery()
      return null
    }
  }
}

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '#app/nuxt' || specifier === '#app/composables/router' || specifier === '#app/components/utils')
      return nextResolve(import.meta.url, context)

    return nextResolve(specifier, context)
  }
})

test('installed Nuxt default treats a same-path activation token as an anchor before scrollToTop', async () => {
  let queriedSelector = false
  scrollState = {
    router: { options: { scrollBehaviorType: 'auto' }, currentRoute: { value: { fullPath: '/erp/ativar#token=synthetic-fixture-value' } } },
    app: { hooks: { hookOnce() {} } }
  }

  const nuxtDefault = (await import('../node_modules/nuxt/dist/pages/runtime/router.options.js')).default as {
    scrollBehavior: (to: Record<string, unknown>, from: Record<string, unknown>, savedPosition?: unknown) => unknown
  }
  const samePathToken = {
    path: '/erp/ativar',
    hash: '#token=synthetic-fixture-value',
    meta: { scrollToTop: () => false }
  }
  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: fakeDocument(() => { queriedSelector = true })
  })
  const defaultPosition = nuxtDefault.scrollBehavior(samePathToken, { path: '/erp/ativar', hash: '' }) as { el?: string }

  assert.equal(defaultPosition.el?.startsWith('#token='), true, 'the installed default returns the fragment as an element selector')
  assert.equal(queriedSelector, true, 'the default attempts selector lookup before consulting scrollToTop')
})

test('activation token bypasses the selector lookup and other navigations delegate to Nuxt', async () => {
  const [options, nuxtDefault] = await Promise.all([
    import('../app/router.options.ts').then(module => module.default as {
      scrollBehavior: (to: Record<string, unknown>, from: Record<string, unknown>, savedPosition?: unknown) => unknown
    }),
    import('../node_modules/nuxt/dist/pages/runtime/router.options.js').then(module => module.default as {
      scrollBehavior: (to: Record<string, unknown>, from: Record<string, unknown>, savedPosition?: unknown) => unknown
    })
  ])
  scrollState = {
    router: { options: { scrollBehaviorType: 'auto' }, currentRoute: { value: { fullPath: '/erp/ativar#token=synthetic-fixture-value' } } },
    app: { hooks: { hookOnce() {} } }
  }
  let selectorQueries = 0
  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: fakeDocument(() => { selectorQueries++ })
  })

  const tokenRoutes = ['/erp/ativar', '/erp/convite', '/redefinir-senha'].map(path => ({ path, hash: path === '/redefinir-senha' ? '#delivery=42&%74oken=synthetic-fixture-value' : '#token=synthetic-fixture-value', meta: {}, matched: [] }))
  for (const tokenRoute of tokenRoutes) {
    assert.equal(options.scrollBehavior(tokenRoute, { path: tokenRoute.path, hash: '', meta: {}, matched: [] }), false)
    assert.equal(options.scrollBehavior(tokenRoute, { path: '/clients', hash: '', meta: {}, matched: [] }), false, `initial ${tokenRoute.path} navigation bypasses Nuxt too`)
  }
  assert.equal(selectorQueries, 0, 'recipient token fragments are rejected before Nuxt selector lookup')

  const ordinaryAnchor = { path: '/clients', hash: '#terms', meta: {}, matched: [] }
  const samePage = { path: '/clients', hash: '', meta: {}, matched: [] }
  const expectedAnchor = nuxtDefault.scrollBehavior(ordinaryAnchor, samePage)
  const actualAnchor = options.scrollBehavior(ordinaryAnchor, samePage)
  assert.deepEqual(actualAnchor, expectedAnchor)
  assert.equal(selectorQueries, 2, 'the ordinary anchor selector reaches both the default and the delegating wrapper')

  const installedBehavior = nuxtDefault.scrollBehavior
  const delegationResult = { left: 9, top: 19 }
  let delegatedArguments: unknown[] = []
  nuxtDefault.scrollBehavior = (...args) => {
    delegatedArguments = args
    return delegationResult
  }
  try {
    assert.equal(options.scrollBehavior(ordinaryAnchor, samePage), delegationResult)
    assert.deepEqual(delegatedArguments, [ordinaryAnchor, samePage, undefined], 'the wrapper passes ordinary navigation arguments to the installed default')
  } finally {
    nuxtDefault.scrollBehavior = installedBehavior
  }

  const savedPosition = { left: 5, top: 24 }
  const routeChange = { path: '/clients', hash: '', meta: {}, matched: [] }
  const previousAnchor = { path: '/clients', hash: '#terms', meta: {}, matched: [] }
  assert.deepEqual(
    options.scrollBehavior(routeChange, previousAnchor, savedPosition),
    nuxtDefault.scrollBehavior(routeChange, previousAnchor, savedPosition),
    'saved history positions follow the installed Nuxt default'
  )
})
