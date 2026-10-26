import { ref, watch, type Ref } from 'vue'
import { useListNavigationState } from '~/composables/useListNavigationState'

type Criteria = Record<string, string | number>

/** Keep each operational destination's supported criteria separate from command state. */
export function useErpListNavigation<T extends Criteria>(key: string, filters: T, authority: () => string, areas: readonly string[] = ['list']) {
  const defaults = { ...filters }
  const states = Object.fromEntries(areas.map(area => [area, useListNavigationState(`${key}:${area}`, {
    defaults: () => ({ ...defaults }),
    authority,
    normalize(value: unknown): T {
      const source = typeof value === 'object' && value !== null ? value as Criteria : {}
      return Object.fromEntries(Object.entries(defaults).map(([field, fallback]) => {
        const candidate = source[field]
        if (typeof fallback === 'number')
          return [field, typeof candidate === 'number' && Number.isSafeInteger(candidate) && candidate > 0 && (field !== 'per_page' || candidate <= 100) ? candidate : fallback]
        if (field.endsWith('_id') && typeof candidate === 'number' && Number.isSafeInteger(candidate) && candidate > 0) return [field, String(candidate)]
        return [field, typeof candidate === 'string' && candidate.length <= 500 ? candidate : fallback]
      })) as T
    }
  })])) as Record<string, Ref<T>>
  const selected = ref(areas[0]!)
  let restoring = false
  const criteria = () => JSON.stringify(Object.entries(filters).filter(([field]) => field !== 'page'))
  let previousCriteria = criteria()
  function restore(area = selected.value) {
    if (!states[area]) return
    selected.value = area
    restoring = true
    Object.assign(filters, states[area]!.value)
    previousCriteria = criteria()
    restoring = false
  }
  watch(filters, () => {
    if (restoring) return
    const nextCriteria = criteria()
    if (nextCriteria !== previousCriteria && 'page' in filters) {
      previousCriteria = nextCriteria
      restoring = true
      Object.assign(filters, { page: 1 })
      restoring = false
    }
    states[selected.value]!.value = { ...filters }
  }, { deep: true, flush: 'sync' })
  watch(() => states[selected.value]!.value, () => restore(), { deep: true, flush: 'sync' })
  restore()
  return { restore }
}
