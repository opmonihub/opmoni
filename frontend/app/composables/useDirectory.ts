import { useMembers } from './useMembers'

export function useDirectory() {
  const nuxtApp = useNuxtApp()
  const { listDirectory } = useMembers()
  const { registry } = useListNavigationRegistry()
  const authority = computed(() => registry.value?.authority ?? '')

  const key = computed(() => `directory-${authority.value}`)

  const { data, error, refresh } = useAsyncData(
    key,
    (_nuxtApp, { signal }) => listDirectory({}, { signal }),
    {
      default: () => [],
      getCachedData(cacheKey) {
        return nuxtApp.payload.data[cacheKey] ?? nuxtApp.static.data[cacheKey]
      }
    }
  )

  watch(authority, () => {
    data.value = []
  }, { flush: 'sync' })

  const memberOptions = computed(() => (data.value ?? []).map(member => ({ label: member.name, value: member.id })))

  function memberName(memberId: number | null): string {
    if (memberId === null) return 'Sem responsável'
    return memberOptions.value.find(option => option.value === memberId)?.label ?? `Membro ${String(memberId)}`
  }

  return { data, error, refresh, memberOptions, memberName }
}
