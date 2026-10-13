import { useMembers } from './useMembers'

export function useDirectory() {
  const nuxtApp = useNuxtApp()
  const { listDirectory } = useMembers()
  const { currentAccount } = useAuth()

  const key = computed(() => `directory-${currentAccount.value?.id ?? 'none'}`)

  const { data, error, refresh } = useAsyncData(
    key,
    () => listDirectory(),
    {
      default: () => [],
      getCachedData(cacheKey) {
        return nuxtApp.payload.data[cacheKey] ?? nuxtApp.static.data[cacheKey]
      }
    }
  )

  const memberOptions = computed(() => (data.value ?? []).map(member => ({ label: member.name, value: member.id })))

  function memberName(memberId: number | null): string {
    if (memberId === null) return 'Sem responsável'
    return memberOptions.value.find(option => option.value === memberId)?.label ?? `Membro ${String(memberId)}`
  }

  return { data, error, refresh, memberOptions, memberName }
}
