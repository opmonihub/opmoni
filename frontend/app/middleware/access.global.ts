import { accessDestination } from '#shared/utils/access'
import { isOfficeErpAdmin } from '~/utils/sidebarNav'

export default defineNuxtRouteMiddleware(async (to) => {
  const { user, resolved, refresh } = useAuth()
  const { api } = useAccessApi()
  const ownerActivationPage = to.path === '/erp/ativar'
  const publicPage = ['/login', '/onboarding', '/primeiro-acesso', '/erp/cadastro', '/erp/ativar'].includes(to.path)
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
    if (to.path === '/primeiro-acesso' || to.path === '/erp/cadastro')
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
  if (ownerActivationPage)
    return
  const destination = accessDestination(user.value)
  const erpDestination = destination === '/erp'
  const officeErpPath = to.path === '/gestao-erp' || to.path.startsWith('/gestao-erp/')
  const officeErpAdmin = isOfficeErpAdmin(user.value.current_account)
  if (publicPage || (to.path !== destination && (
    (destination !== '/' && !erpDestination)
    || (destination === '/' && ['/selecionar-escritorio', '/acesso-indisponivel'].includes(to.path))
    || (erpDestination && to.path !== '/erp' && !to.path.startsWith('/erp/'))
  )))
    return navigateTo(destination)
  const dashboardPage = to.path === '/' || (officeErpPath && officeErpAdmin) || ['/inbox', '/customers', '/settings', '/fiscal', '/monitoring', '/work', '/equipe'].some(path => to.path === path || to.path.startsWith(`${path}/`))
  const erpPage = to.path === '/erp' || to.path.startsWith('/erp/')
  if (!dashboardPage && !erpPage && !['/selecionar-escritorio', '/acesso-indisponivel'].includes(to.path))
    return navigateTo(destination)
})
