import { readonly, ref, watch } from 'vue'

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
