import { watch, type Ref } from 'vue'
import { adminListNavigationOptions } from '~/utils/adminListFilters'

export function useAccountFilters(load: () => unknown, authority?: Readonly<Ref<unknown>>) {
  const route = useRoute()
  const navigation = useListNavigationState('admin:contas', adminListNavigationOptions(['all', 'active', 'suspended'], ['id', 'name', 'status'], () => route.query ?? {}))
  const q = listNavigationField(navigation, 'q')
  const status = listNavigationField(navigation, 'filter')
  const page = listNavigationField(navigation, 'page')
  const sort = listNavigationField(navigation, 'sort')
  const direction = listNavigationField(navigation, 'direction')

  watch([status, sort, direction], () => {
    page.value = 1
  }, { flush: 'sync' })
  watch([page, status, sort, direction, ...(authority ? [authority] : [])], load)

  function filter() {
    if (page.value === 1)
      load()
    else
      page.value = 1
  }

  function clearFilters() {
    const changed = status.value !== 'all' || page.value !== 1 || sort.value !== ''
    q.value = ''
    sort.value = ''
    direction.value = 'asc'
    status.value = 'all'
    page.value = 1
    if (!changed)
      load()
  }

  return { q, status, page, sort, direction, navigation, filter, clearFilters }
}
