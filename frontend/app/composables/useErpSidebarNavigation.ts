import { nextTick, onScopeDispose } from 'vue'
import type { Ref } from 'vue'

export function useErpSidebarNavigation(open: Ref<boolean>) {
  let focusAfterNavigation = false
  let disposed = false
  async function focusSidebarTrigger() {
    if (!focusAfterNavigation || disposed)
      return
    focusAfterNavigation = false
    await nextTick()
    if (disposed || open.value)
      return
    const trigger = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-erp-sidebar-trigger]'))
      .find(button => button.getClientRects().length > 0)
    trigger?.focus()
  }
  const removeHook = useNuxtApp().hook('page:finish', focusSidebarTrigger)
  const removeAfterEach = useRouter().afterEach(async (to, from, failure) => {
    if (failure || to.path !== from.path || to.hash === from.hash)
      return
    await focusSidebarTrigger()
  })

  function closeForNavigation() {
    focusAfterNavigation = open.value
    open.value = false
  }

  onScopeDispose(() => {
    disposed = true
    focusAfterNavigation = false
    removeHook()
    removeAfterEach()
  })

  return { closeForNavigation }
}
