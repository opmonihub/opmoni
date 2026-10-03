export function useSupportMode() {
  const { user, isSuperAdmin, accounts, currentAccount } = useAuth()

  return computed(() => {
    const current = currentAccount.value
    if (!user.value || !isSuperAdmin.value || !current) {
      return false
    }
    const link = accounts.value.find(account => account.id === current.id)
    if (!link) {
      return true
    }
    return link.is_member === false
  })
}
