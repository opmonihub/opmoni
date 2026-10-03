export default defineNuxtRouteMiddleware(async () => {
  const { fetchMe, user, canManageMembers } = useAuth()
  if (!user.value) {
    await fetchMe().catch(() => {})
  }
  if (!user.value) {
    return navigateTo('/login')
  }
  if (!canManageMembers.value) {
    return navigateTo('/')
  }
})
