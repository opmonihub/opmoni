import assert from 'node:assert/strict'
import { test } from 'node:test'

test('activation token route suppresses fragment scrolling while keeping Nuxt default scroll behavior', async () => {
  const options = (await import('../app/router.options.ts')).default as {
    routes: (routes: Array<{ path: string, name?: string, meta?: Record<string, unknown> }>) => Array<{ path: string, name?: string, meta?: Record<string, unknown> }>
    scrollBehavior?: unknown
  }
  const existingScrollPolicy = () => true
  const activationRoute = { path: '/erp/ativar', name: 'erp-ativar', meta: { layout: 'access', scrollToTop: existingScrollPolicy } }
  const ordinaryRoute = { path: '/clients', name: 'clients', meta: { layout: 'default' } }
  const similarlyNamedRoute = { path: '/erp/ativar-extra', name: 'erp-ativar-extra', meta: { layout: 'default' } }
  const routes = options.routes([activationRoute, ordinaryRoute, similarlyNamedRoute])
  const activation = routes[0]!
  const scrollToTop = activation.meta?.scrollToTop as (to: { path: string, hash: string }, from: { path: string, hash: string }) => boolean | undefined
  const from = { path: '/', hash: '' }
  const tokenRoute = { path: '/erp/ativar', hash: '#token=synthetic-fixture-value' }

  assert.equal(scrollToTop(tokenRoute, from), false)
  assert.equal(tokenRoute.hash, '#token=synthetic-fixture-value', 'the page still receives the fragment for its existing exchange flow')
  assert.equal(scrollToTop({ path: '/erp/ativar', hash: '#terms' }, from), true, 'existing route scroll policy remains active for ordinary anchors')
  assert.equal(activation.meta?.layout, 'access')
  assert.equal(routes[1], ordinaryRoute)
  assert.equal(routes[2], similarlyNamedRoute, 'nearby routes keep their original metadata and behavior')
  assert.equal(options.scrollBehavior, undefined, 'Nuxt built-in anchor handling and saved-position restoration remain installed')
})
