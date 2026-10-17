import type { ErpPaginated } from '#shared/types/erpCatalogs'
import { erpCatalogListQuery } from '#shared/utils/erpCatalogs'

export function useErpCatalogList<T>(path: string) {
  const { api } = useAccessApi()
  const search = ref('')
  const page = ref(1)
  const pending = ref(false)
  const state = ref<ErpPaginated<T> | null>(null)
  const error = ref('')

  async function refresh() {
    pending.value = true
    error.value = ''
    try {
      const qs = erpCatalogListQuery(search.value, page.value)
      state.value = await api<ErpPaginated<T>>(`${path}?${qs}`)
    } catch (err) {
      error.value = apiFailure(err).message
    } finally {
      pending.value = false
    }
  }

  watch([search, page], () => {
    void refresh()
  }, { immediate: true })

  return { search, page, pending, state, error, refresh }
}
