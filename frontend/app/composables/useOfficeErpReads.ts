import { onScopeDispose, watch } from 'vue'
import type { Ref } from 'vue'

/** Own only safe page reads. Shared authority confirmation and commands have separate owners. */
export function useOfficeErpReads(identity: Ref<string>) {
  const controllers = new Map<string, AbortController>()
  let disposed = false
  function cancel(name?: string) {
    if (name) {
      controllers.get(name)?.abort()
      controllers.delete(name)
      return
    }
    for (const controller of controllers.values()) controller.abort()
    controllers.clear()
  }
  watch(identity, () => cancel(), { flush: 'sync' })
  onScopeDispose(() => {
    disposed = true
    cancel()
  })
  return {
    cancel,
    begin(name: string) {
      controllers.get(name)?.abort()
      const controller = new AbortController()
      if (disposed) controller.abort()
      else controllers.set(name, controller)
      return { signal: controller.signal, current: () => !controller.signal.aborted }
    }
  }
}
