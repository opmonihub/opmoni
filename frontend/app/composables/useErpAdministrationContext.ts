export interface ErpAdministrationContextToken {
  key: string
  generation: number
}

/**
 * A request token for owner administration and office approval screens.
 * Session generation comes from the shared auth lifecycle; the remaining key
 * records the actor and every selected ERP/Account authority boundary.
 */
export function useErpAdministrationContext(extra: () => string | number | null = () => null) {
  const { user, currentCompany, currentAccount } = useAuth()
  const sessionGeneration = useState('access-session-generation', () => 0)
  const key = computed(() => JSON.stringify([
    user.value?.id ?? null,
    sessionGeneration.value,
    currentAccount.value?.id ?? null,
    currentAccount.value?.status ?? null,
    currentAccount.value?.role ?? null,
    currentCompany.value?.id ?? null,
    currentCompany.value?.status ?? null,
    currentCompany.value?.access_kind ?? null,
    currentCompany.value?.role ?? null,
    currentCompany.value?.delegated_office_id ?? null,
    [...(currentCompany.value?.permissions ?? [])].sort(),
    extra()
  ]))
  const generation = ref(0)
  watch(key, () => {
    generation.value++
  }, { flush: 'sync' })
  onScopeDispose(() => {
    generation.value++
  })

  const capture = (): ErpAdministrationContextToken => ({ key: key.value, generation: generation.value })
  const isCurrent = (token: ErpAdministrationContextToken) => token.key === key.value && token.generation === generation.value
  const isCompanyOwner = computed(() => currentCompany.value?.status === 'active'
    && currentCompany.value.access_kind === 'direct'
    && currentCompany.value.role === 'owner')
  const isAccountAdmin = computed(() => currentAccount.value?.status === 'active'
    && currentAccount.value.role === 'admin')

  return { key, capture, isCurrent, isCompanyOwner, isAccountAdmin }
}
