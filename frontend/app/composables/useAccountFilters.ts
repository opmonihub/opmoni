import { watch, type Ref } from 'vue'
import { adminListNavigationOptions } from '~/utils/adminListFilters'

export function useAccountFilters(load: () => unknown, authority?: Readonly<Ref<unknown>>) {
  const navigation = useListNavigationState('admin:contas', adminListNavigationOptions(['all', 'active', 'suspended']))
  const q = listNavigationField(navigation, 'q')
  const status = listNavigationField(navigation, 'filter')
  const page = listNavigationField(navigation, 'page')

  watch(status, () => {
    page.value = 1
  }, { flush: 'sync' })
  watch([page, status, ...(authority ? [authority] : [])], load)

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
