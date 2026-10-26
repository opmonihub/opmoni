import { computed, readonly, ref, watch } from 'vue'
import type { Identity } from '#shared/types/access'

/** Invalidate protected state synchronously; keep a monotonic stamp for command feedback. */
export function useAdminReadAuthority(invalidate: () => void) {
  const { registry } = useListNavigationRegistry()
  const generation = ref(0)
  watch(() => registry.value?.authority, () => {
    generation.value++
    invalidate()
  }, { flush: 'sync' })
  return readonly(generation)
}

/** Route middleware can be leaving while authority-triggered watchers are still queued. */
export function useAdminReadAllowed() {
  const actor = useState<Identity | null>('access-user', () => null)
  return computed(() => !!actor.value?.id && actor.value.is_super_admin)
}
