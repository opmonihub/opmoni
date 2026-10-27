import { computed, ref, watch, type Ref } from 'vue'

/** User dismissal asks about drafts; authority loss and confirmed saves close directly. */
export function useDirtyModal(open: Ref<boolean>, draft: () => unknown, busy: () => boolean) {
  const initial = ref('')
  const discardOpen = ref(false)
  watch(open, (value) => {
    discardOpen.value = false
    initial.value = value ? JSON.stringify(draft()) : ''
  }, { immediate: true, flush: 'sync' })

  function requestClose() {
    if (!open.value || busy()) return
    if (JSON.stringify(draft()) !== initial.value) discardOpen.value = true
    else open.value = false
  }

  function confirmDiscard() {
    if (busy()) return
    discardOpen.value = false
    open.value = false
  }

  const modelOpen = computed({
    get: () => open.value,
    set: (value: boolean) => { if (!value) requestClose() }
  })
  return { modelOpen, discardOpen, requestClose, confirmDiscard }
}
