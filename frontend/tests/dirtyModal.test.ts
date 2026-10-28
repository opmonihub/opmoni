import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

interface ModalDraft {
  modelOpen: Vue.Ref<boolean>
  discardOpen: Vue.Ref<boolean>
  requestClose(): void
  confirmDiscard(): void
}

test('modal dismissal keeps dirty drafts, blocks pending saves and permits explicit discard', () => {
  const { useDirtyModal } = loadAppComposable<{ useDirtyModal: (open: Vue.Ref<boolean>, draft: () => unknown, busy: () => boolean) => ModalDraft }>('composables/useDirtyModal.ts')
  const open = Vue.ref(false), busy = Vue.ref(false), draft = Vue.reactive({ name: 'Original', modules: ['stock'] })
  const scope = Vue.effectScope()
  const modal = scope.run(() => useDirtyModal(open, () => draft, () => busy.value))!
  try {
    open.value = true
    draft.name = 'Unsaved'
    modal.modelOpen.value = false
    assert.equal(open.value, true)
    assert.equal(modal.discardOpen.value, true)
    modal.discardOpen.value = false
    assert.equal(draft.name, 'Unsaved')
    busy.value = true
    modal.requestClose()
    modal.confirmDiscard()
    assert.equal(open.value, true)
    busy.value = false
    modal.requestClose()
    modal.confirmDiscard()
    assert.equal(open.value, false)
    assert.equal(modal.discardOpen.value, false)
  } finally { scope.stop() }
})

test('programmatic authority loss closes a dirty modal without prompting or restoring the draft', () => {
  const { useDirtyModal } = loadAppComposable<{ useDirtyModal: (open: Vue.Ref<boolean>, draft: () => unknown, busy: () => boolean) => ModalDraft }>('composables/useDirtyModal.ts')
  const open = Vue.ref(false), draft = Vue.reactive({ name: 'Private' })
  const scope = Vue.effectScope()
  const modal = scope.run(() => useDirtyModal(open, () => draft, () => false))!
  try {
    open.value = true
    draft.name = 'Changed'
    modal.requestClose()
    assert.equal(modal.discardOpen.value, true)
    open.value = false
    draft.name = ''
    modal.confirmDiscard()
    assert.equal(open.value, false)
    assert.equal(modal.discardOpen.value, false)
    open.value = true
    modal.requestClose()
    assert.equal(open.value, false)
  } finally { scope.stop() }
})
