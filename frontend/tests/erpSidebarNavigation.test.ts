import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type RouteSnapshot = { path: string, hash: string }
type AfterEachHook = (to: RouteSnapshot, from: RouteSnapshot, failure?: unknown) => Promise<void>

describe('ERP mobile sidebar navigation focus', () => {
  it('closes the menu and focuses the new page trigger only after navigation finishes', async () => {
    let finish: () => Promise<void> = async () => {}
    let unhooked = false
    let routerUnhooked = false
    let focused = 0
    const trigger = {
      focus() { focused++ },
      getClientRects: () => [1]
    }
    const { useErpSidebarNavigation } = loadAppComposable<{
      useErpSidebarNavigation(open: Vue.Ref<boolean>): { closeForNavigation(): void }
    }>('composables/useErpSidebarNavigation.ts', {
      useNuxtApp: () => ({
        hook(name: string, handler: () => Promise<void>) {
          assert.equal(name, 'page:finish')
          finish = handler
          return () => {
            unhooked = true
          }
        }
      }),
      useRouter: () => ({
        afterEach(_handler: AfterEachHook) {
          return () => {
            routerUnhooked = true
          }
        }
      }),
      document: { querySelectorAll: () => [trigger] }
    })
    const scope = Vue.effectScope()
    const open = Vue.ref(true)
    const navigation = scope.run(() => useErpSidebarNavigation(open))!
    navigation.closeForNavigation()
    assert.equal(open.value, false)
    assert.equal(focused, 0, 'the old page trigger must not receive focus')
    await finish()
    assert.equal(focused, 1)
    await finish()
    assert.equal(focused, 1, 'unrelated subsequent navigation must retain its normal focus')
    navigation.closeForNavigation()
    await finish()
    assert.equal(focused, 1, 'desktop navigation with a closed sidebar must not steal focus')
    open.value = true
    navigation.closeForNavigation()
    scope.stop()
    await finish()
    assert.equal(focused, 1, 'disposed layouts must not focus another page')
    assert.equal(unhooked, true)
    assert.equal(routerUnhooked, true)
  })

  it('restores focus after same-page hash navigation, but leaves page transitions to page:finish', async () => {
    let finish: () => Promise<void> = async () => {}
    let afterEach: AfterEachHook = async () => {}
    let focused = 0
    const trigger = {
      focus() { focused++ },
      getClientRects: () => [1]
    }
    const { useErpSidebarNavigation } = loadAppComposable<{
      useErpSidebarNavigation(open: Vue.Ref<boolean>): { closeForNavigation(): void }
    }>('composables/useErpSidebarNavigation.ts', {
      useNuxtApp: () => ({
        hook(_name: string, handler: () => Promise<void>) {
          finish = handler
          return () => {}
        }
      }),
      useRouter: () => ({
        afterEach(handler: AfterEachHook) {
          afterEach = handler
          return () => {}
        }
      }),
      document: { querySelectorAll: () => [trigger] }
    })
    const scope = Vue.effectScope()
    const open = Vue.ref(true)
    const navigation = scope.run(() => useErpSidebarNavigation(open))!
    try {
      const route = { path: '/erp/financeiro', hash: '#payments' }
      const previous = { path: '/erp/financeiro', hash: '#obligations' }
      navigation.closeForNavigation()
      await afterEach(route, previous)
      await Vue.nextTick()
      assert.equal(focused, 1, 'hash-only navigation focuses the persistent sidebar trigger')

      open.value = true
      navigation.closeForNavigation()
      const previousPage = { path: '/erp/compras', hash: '' }
      const nextPage = { path: '/erp/compras/xml', hash: '' }
      await afterEach(nextPage, previousPage)
      await Vue.nextTick()
      assert.equal(focused, 1, 'a different page path waits for Nuxt page:finish')
      open.value = false
      await finish()
      assert.equal(focused, 2)
    } finally {
      scope.stop()
    }
  })
})
