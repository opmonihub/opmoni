import assert from 'node:assert/strict'
import { test } from 'node:test'
import { effectScope, ref } from 'vue'
import { useModalFocusReturn } from '../app/composables/useModalFocusReturn.ts'

test('restores a programmatic modal opener after nested discard closes and ignores removed openers', () => {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'document')
  let focused = 0
  const opener = { isConnected: true, focus: () => { focused++ } }
  const document = { activeElement: opener, body: {} }
  Object.defineProperty(globalThis, 'document', { configurable: true, value: document })
  const scope = effectScope()
  try {
    const open = ref(false)
    const restore = scope.run(() => useModalFocusReturn(open))!
    open.value = true
    document.activeElement = { isConnected: true, focus() {} }
    open.value = false
    restore()
    assert.equal(focused, 1)
    open.value = true
    document.activeElement.isConnected = false
    open.value = false
    restore()
    assert.equal(focused, 1)
  } finally {
    scope.stop()
    if (original) Object.defineProperty(globalThis, 'document', original)
    else Reflect.deleteProperty(globalThis, 'document')
  }
})
