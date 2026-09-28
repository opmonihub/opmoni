import type { Ref } from 'vue'

/**
 * The child half of the navbar actions in `pages/monitoring.vue`. The shell
 * bumps a counter; the page that is mounted answers it and reports whether it
 * is busy, so the button spins for the list it actually refreshes.
 */
export function useMonitoringActions(options: {
  refresh: () => unknown
  loading: Ref<boolean>
  associate?: () => void
}) {
  const refreshRequest = useState('monitoring-refresh', () => 0)
  const associateRequest = useState('monitoring-associate', () => 0)
  const refreshing = useState('monitoring-refreshing', () => false)

  watch(refreshRequest, () => {
    void options.refresh()
  })

  if (options.associate) {
    const associate = options.associate
    watch(associateRequest, () => associate())
  }

  watch(options.loading, (value) => {
    refreshing.value = value
  }, { immediate: true })

  onScopeDispose(() => {
    refreshing.value = false
  })
}
