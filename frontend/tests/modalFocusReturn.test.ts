import assert from 'node:assert/strict'
import { test } from 'node:test'
import { effectScope, ref } from 'vue'
import { useModalFocusReturn } from '../app/composables/useModalFocusReturn.ts'

test('restores a programmatic modal opener after nested discard closes and ignores removed openers', () => {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'document')
  let focused = 0
  const opener = { isConnected: true, focus: () => {
    focused++
  } }
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

test('a programmatic editor opened from a menu returns to its persistent row trigger', () => {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'document')
  let focused = 0
  const trigger = {
    isConnected: true,
    focus() {
      focused++
    }
  }
  const menu = { getAttribute: (name: string) => name === 'aria-labelledby' ? 'row-trigger' : null }
  const item = { isConnected: false, closest: () => menu, focus() {} }
  const document = { activeElement: item, body: {}, getElementById: (id: string) => id === 'row-trigger' ? trigger : null }
  Object.defineProperty(globalThis, 'document', { configurable: true, value: document })
  const scope = effectScope()
  try {
    const open = ref(true)
    const restore = scope.run(() => useModalFocusReturn(open))!
    open.value = false
    restore()
    assert.equal(focused, 1)
  } finally {
    scope.stop()
    if (original) Object.defineProperty(globalThis, 'document', original)
    else Reflect.deleteProperty(globalThis, 'document')
  }
})
