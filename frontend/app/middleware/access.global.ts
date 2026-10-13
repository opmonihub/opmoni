import { accessDestination } from '#shared/utils/access'

export default defineNuxtRouteMiddleware(async (to) => {
  const { user, resolved, refresh } = useAuth()
  const { api } = useAccessApi()
  const publicPage = ['/login', '/onboarding', '/primeiro-acesso'].includes(to.path)
  if (!resolved.value) {
    try {
      await refresh()
    } catch {
      if (!publicPage)
        return navigateTo('/login')
      return
    }
  }
  if (!user.value) {
    if (to.path === '/primeiro-acesso')
      return
    let available = false
    try {
      available = (await api<{
        registration_available: boolean
      }>('/registration-status')).registration_available
    } catch { /* Availability failure falls back to login. */ }
    const destination = available ? '/onboarding' : '/login'
    if (to.path !== destination)
      return navigateTo(destination)
    return
  }
  if (to.path === '/admin' || to.path.startsWith('/admin/'))
    return user.value.is_super_admin ? undefined : navigateTo(accessDestination(user.value))
  const destination = accessDestination(user.value)
  if (publicPage || (to.path !== destination && (destination !== '/' || ['/selecionar-escritorio', '/acesso-indisponivel'].includes(to.path))))
    return navigateTo(destination)
  const dashboardPage = to.path === '/' || ['/inbox', '/customers', '/settings', '/fiscal', '/monitoring', '/work', '/equipe'].some(path => to.path === path || to.path.startsWith(`${path}/`))
  if (!dashboardPage && !['/selecionar-escritorio', '/acesso-indisponivel'].includes(to.path))
    return navigateTo(destination)
})
