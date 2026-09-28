import type { Ref } from 'vue'
import { apiStatus } from '~/composables/useApiError'

export type RetryableLoadOptions = {
  /** Re-run the load. Whatever `useAsyncData.refresh` is, or a page's own loader. */
  refresh: () => Promise<unknown>
  /** The error the loader last surfaced. */
  error: Ref<unknown>
  /** The loader's busy flag. */
  loading: Ref<boolean>
  /** What could not be loaded, in the product's own words. */
  loadErrorTitle: string
  /** What could not be refreshed. Defaults to `loadErrorTitle`. */
  refreshErrorTitle?: string
  /**
   * A status that means "not shipped yet" rather than "broken" — 404 on an API the
   * backend has not written yet. The screen sits in its empty state, silently.
   */
  ignoreStatus?: number
  /**
   * Once failed, stay failed until the operator retries.
   *
   * `useAsyncData` clears `error` on the next success, so a page that keys its
   * alert straight off `error` will flash the error away and then flash the stale
   * content back in. Pages that render a fatal alert want the sticky form.
   */
  sticky?: boolean
}

/**
 * The load/refresh/error contract every data-backed page repeats: two failure
 * toasts with different wording, a `showError` the template can key off, and a
 * `retry` that clears the failure first.
 *
 * Pass the pieces `useAsyncData` already gave you — it does not own the fetch, so
 * the `getCachedData: () => undefined` decisions that are load-bearing in the
 * monitoring screens stay in the page where they are explained.
 *
 * ```ts
 * const { data, status, error, refresh } = await useAsyncData(key, load, { ... })
 * const { isLoading, showError, retry } = useRetryableLoad({
 *   refresh,
 *   error,
 *   loading: computed(() => status.value === 'pending'),
 *   loadErrorTitle: 'Não foi possível carregar o monitoramento',
 *   ignoreStatus: 404
 * })
 * ```
 */
export function useRetryableLoad(options: RetryableLoadOptions) {
  const toast = useToast()

  const failed = ref(false)
  const isLoading = computed(() => options.loading.value)
  const isFatal = computed(() => {
    if (!options.error.value) return false
    return options.ignoreStatus === undefined || apiStatus(options.error.value) !== options.ignoreStatus
  })
  const showError = computed(() => (options.sticky ? failed.value || isFatal.value : isFatal.value))

  watch(options.error, (value) => {
    if (value && isFatal.value) {
      failed.value = true
      toast.add({ title: options.loadErrorTitle, color: 'error' })
    }
  })

  async function retry() {
    failed.value = false
    await refresh()
  }

  async function refresh() {
    try {
      await options.refresh()
    } catch {
      toast.add({
        title: options.refreshErrorTitle ?? options.loadErrorTitle,
        color: 'error'
      })
    }
  }

  return { failed, isLoading, showError, retry, refresh }
}
