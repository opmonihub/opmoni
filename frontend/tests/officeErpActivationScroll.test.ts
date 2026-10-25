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

test('installed Nuxt default still treats a same-path activation token as an anchor before scrollToTop', async () => {
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

  assert.equal(defaultPosition.el?.startsWith('#token='), true, 'the installed default returns the sensitive fragment as a selector')
  assert.equal(queriedSelector, true, 'the installed default attempts a selector lookup before consulting scrollToTop')
})

test('activation token route suppresses fragment scrolling while retaining normal Nuxt scroll behavior', async () => {
  const options = (await import('../app/router.options.ts')).default as {
    routes: (routes: Array<{ path: string, name?: string, meta?: Record<string, unknown> }>) => Array<{ path: string, name?: string, meta?: Record<string, unknown> }>
    scrollBehavior: (to: Record<string, unknown>, from: Record<string, unknown>, savedPosition?: unknown) => unknown
  }
  const existingScrollPolicy = () => true
  const activationRoute = { path: '/erp/ativar', name: 'erp-ativar', meta: { layout: 'access', scrollToTop: existingScrollPolicy } }
  const ordinaryRoute = { path: '/clients', name: 'clients', meta: { layout: 'default' } }
  const similarlyNamedRoute = { path: '/erp/ativar-extra', name: 'erp-ativar-extra', meta: { layout: 'default' } }
  const routes = options.routes([activationRoute, ordinaryRoute, similarlyNamedRoute])
  const activation = routes[0]!
  const scrollToTop = activation.meta?.scrollToTop as (to: { path: string, hash: string }, from: { path: string, hash: string }) => boolean | undefined
  const from = { path: '/', hash: '' }
  const tokenRoute = { path: '/erp/ativar', hash: '#token=synthetic-fixture-value', meta: activation.meta, matched: [] }

  assert.equal(scrollToTop(tokenRoute, from), false)
  assert.equal(tokenRoute.hash, '#token=synthetic-fixture-value', 'the page still receives the fragment for its existing exchange flow')
  assert.equal(scrollToTop({ path: '/erp/ativar', hash: '#terms' }, from), true, 'existing route scroll policy remains active for ordinary anchors')
  assert.equal(activation.meta?.layout, 'access')
  assert.equal(routes[1], ordinaryRoute)
  assert.equal(routes[2], similarlyNamedRoute, 'nearby routes keep their original metadata and behavior')
  const scrollStateForApp = {
    router: { options: { scrollBehaviorType: 'auto' }, currentRoute: { value: { fullPath: '/erp/ativar#token=synthetic-fixture-value' } } },
    app: { hooks: { hookOnce() {} } }
  }
  scrollState = scrollStateForApp
  let queriedSelector = false
  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: fakeDocument(() => { queriedSelector = true })
  })

  assert.equal(options.scrollBehavior(tokenRoute, { path: '/erp/ativar', hash: '', meta: activation.meta, matched: [] }), false)
  assert.equal(queriedSelector, false, 'the activation token is rejected before a selector lookup')
  assert.deepEqual(options.scrollBehavior({ path: '/clients', hash: '', meta: {} }, { path: '/', hash: '', meta: {}, matched: [] }, { left: 5, top: 24 }), { left: 5, top: 24 }, 'saved history positions remain available')
  assert.deepEqual(options.scrollBehavior({ path: '/clients', hash: '#terms', meta: {}, matched: [] }, { path: '/clients', hash: '', meta: {}, matched: [] }), { el: '#terms', top: 0, behavior: 'auto' }, 'ordinary same-page anchors retain Nuxt behavior')
})
