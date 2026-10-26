type OwnedRead = { clear: () => void, refresh: () => Promise<unknown> }

/** One page owns these safe reads; registry authority is independent of criteria. */
export function useOfficeReadLifecycle() {
  const { registry } = useListNavigationRegistry()
  const authority = computed(() => registry.value?.authority ?? '')
  const reads = new Set<{ resource: OwnedRead, reset?: () => void }>()
  let disposed = false
  watch(authority, (stamp) => {
    for (const { resource, reset } of reads) {
      reset?.()
      resource.clear()
    }
    void nextTick(() => {
      if (disposed || authority.value !== stamp) return
      for (const { resource } of reads) void resource.refresh()
    })
  }, { flush: 'sync' })
  onScopeDispose(() => {
    disposed = true
    reads.clear()
  })
  function own(resource: OwnedRead, reset?: () => void) {
    reads.add({ resource, reset })
  }
  return { own, authority }
}
