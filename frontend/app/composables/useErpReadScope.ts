import { onScopeDispose } from 'vue'

/** Controllers belong to logical page reads, never commands or operation recovery. */
export function useErpReadScope() {
  const controllers = new Map<string, AbortController>()
  function cancel(key?: string) {
    if (key !== undefined) {
      controllers.get(key)?.abort()
      controllers.delete(key)
      return
    }
    for (const controller of controllers.values()) controller.abort()
    controllers.clear()
  }
  function start(key: string) {
    cancel(key)
    const controller = new AbortController()
    controllers.set(key, controller)
    return controller.signal
  }
  onScopeDispose(() => cancel())
  return { start, cancel }
}
