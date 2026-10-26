import { watch } from 'vue'

/** Protected Admin results and read feedback must not survive a changed authority. */
export function useAdminReadAuthority(invalidate: () => void) {
  const { registry } = useListNavigationRegistry()
  watch(() => registry.value?.authority, invalidate, { flush: 'sync' })
}
