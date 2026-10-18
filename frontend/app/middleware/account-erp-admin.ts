export default defineNuxtRouteMiddleware(async () => {
  const { user, refresh, currentAccount } = useAuth()
  if (!user.value) {
    await refresh().catch(() => null)
  }
  if (currentAccount.value?.status !== 'active' || currentAccount.value.role !== 'admin') {
    return navigateTo('/')
  }
})
