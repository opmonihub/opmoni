export default defineNuxtRouteMiddleware(async () => {
  const { fetchMe, user, isSuperAdmin } = useAuth()
  if (!user.value) {
    await fetchMe().catch(() => {})
  }
  if (!user.value) {
    return navigateTo('/login')
  }
  if (!isSuperAdmin.value) {
    return navigateTo('/')
  }
})
