import { accessDestination } from '#shared/utils/access'
import { isOfficeErpAdmin } from '~/utils/sidebarNav'
import { captureAccessContinuationFromRoute } from '~/utils/accessContinuationFragment'

export default defineNuxtRouteMiddleware(async (to) => {
  const continuationKind = to.path === '/erp/convite'
    ? 'member-invitation'
    : to.path === '/redefinir-senha' ? 'password-recovery' : null
  if (import.meta.client && continuationKind && to.hash && captureAccessContinuationFromRoute(continuationKind, to.hash))
    return navigateTo(to.fullPath.split('#', 1)[0]!, { replace: true })

  const { user, resolved, refresh } = useAuth()
  const { api } = useAccessApi()
  const ownerActivationPage = to.path === '/erp/ativar'
  const publicTrialSignup = /^\/erp\/testar\/[^/]+$/.test(to.path)
  const accessContinuation = ['/erp/convite', '/redefinir-senha'].includes(to.path)
  const publicPage = ['/login', '/onboarding', '/primeiro-acesso', '/erp/cadastro', '/erp/ativar', '/erp/convite', '/redefinir-senha'].includes(to.path) || publicTrialSignup
  if (!resolved.value) {
    try {
      await refresh()
    } catch {
      if (!publicPage)
        return navigateTo('/login')
      return
    }
  }
  if (ownerActivationPage || publicTrialSignup || accessContinuation)
    return
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
