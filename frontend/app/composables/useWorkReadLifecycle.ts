/** Work's shared month can change before Suspense disposes the outgoing page. */
export function useWorkReadLifecycle(path: string) {
  const router = useRouter()
  const lifecycle = useOfficeReadLifecycle()
  // Nuxt's useRoute intentionally waits for the new page; Vue Router already
  // identifies the destination while both the old and new pages are mounted.
  const enabled = computed(() => router.currentRoute.value.path.replace(/\/$/, '') === path)
  function own(resource: { clear: () => void, refresh: () => Promise<unknown> }, reset?: () => void) {
    lifecycle.own(resource, reset)
    watch(enabled, (active) => {
      if (active) return
      reset?.()
      resource.clear()
    }, { immediate: true, flush: 'sync' })
  }
  return { ...lifecycle, enabled, own }
}
