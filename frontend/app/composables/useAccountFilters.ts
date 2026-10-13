import { ref, watch } from 'vue'

export function useAccountFilters(load: () => unknown) {
  const q = ref('')
  const status = ref('all')
  const page = ref(1)

  watch(status, () => {
    page.value = 1
  }, { flush: 'sync' })
  watch([page, status], load)

  function filter() {
    if (page.value === 1)
      load()
    else
      page.value = 1
  }

  function clearFilters() {
    const changed = status.value !== 'all' || page.value !== 1
    q.value = ''
    status.value = 'all'
    page.value = 1
    if (!changed)
      load()
  }

  return { q, status, page, filter, clearFilters }
}
