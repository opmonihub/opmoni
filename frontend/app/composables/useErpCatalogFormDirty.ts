import { toRaw, watch } from 'vue'

export function useErpCatalogFormDirty(
  state: object,
  isEditable: () => boolean,
  report: (dirty: boolean) => void
) {
  let baseline = ''
  let ready = false

  function snapshot(): string {
    return JSON.stringify(toRaw(state)) ?? ''
  }

  function updateDirty() {
    report(ready && isEditable() && snapshot() !== baseline)
  }

  function markClean() {
    baseline = snapshot()
    ready = true
    report(false)
  }

  watch(state, updateDirty, { deep: true, flush: 'post' })
  watch(isEditable, updateDirty, { flush: 'post' })

  return { markClean }
}
