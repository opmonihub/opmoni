import type { ErpPaginated } from '#shared/types/erpCatalogs'
import { erpCatalogListQuery } from '#shared/utils/erpCatalogs'

export function useErpCatalogList<T>(path: string) {
  const { api } = useAccessApi()
  const search = ref('')
  const page = ref(1)
  const pending = ref(false)
  const state = ref<ErpPaginated<T> | null>(null)
  const error = ref('')
  let generation = 0

  onScopeDispose(() => {
    generation++
  })

  async function refresh() {
    const version = ++generation
    pending.value = true
    error.value = ''
    try {
      const qs = erpCatalogListQuery(search.value, page.value)
      const result = await api<ErpPaginated<T>>(`${path}?${qs}`)
      if (version === generation)
        state.value = result
    } catch (err) {
      if (version === generation)
        error.value = apiFailure(err).message
    } finally {
      if (version === generation)
        pending.value = false
    }
  }

  watch([search, page], ([nextSearch], previous) => {
    if (previous && nextSearch !== previous[0] && page.value !== 1) {
      page.value = 1
      return
    }
    void refresh()
  }, { immediate: true, flush: 'sync' })

  return { search, page, pending, state, error, refresh }
}
