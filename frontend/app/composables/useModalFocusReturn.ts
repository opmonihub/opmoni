import { watch, type Ref } from 'vue'

/** Programmatic dialogs have no native trigger; restore it after nested overlays leave. */
export function useModalFocusReturn(open: Ref<boolean>) {
  let opener: HTMLElement | null = null
  watch(open, (value) => {
    if (value && typeof document !== 'undefined' && document.activeElement !== document.body) {
      const active = document.activeElement as HTMLElement | null
      const menuTriggerId = active?.closest?.('[role="menu"]')?.getAttribute('aria-labelledby')
      // Menu items unmount on selection; their labelled trigger remains in the row.
      opener = menuTriggerId ? document.getElementById(menuTriggerId) ?? active : active
    }
  }, { immediate: true, flush: 'sync' })

  return () => {
    if (!open.value && opener?.isConnected && typeof opener.focus === 'function')
      opener.focus({ preventScroll: true })
  }
}
