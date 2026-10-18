import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

describe('ERP mobile sidebar navigation focus', () => {
  it('closes the menu and focuses the new page trigger only after navigation finishes', async () => {
    let finish: () => Promise<void> = async () => {}
    let unhooked = false
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
  })
})
