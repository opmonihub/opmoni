function unauthenticated(error: unknown) {
  if (!error || typeof error !== 'object') return false
  const failure = error as { status?: number, statusCode?: number }
  const status = Number(failure.statusCode ?? failure.status)
  return status === 401 || status === 419
}

function forbidden(error: unknown) {
  if (!error || typeof error !== 'object') return false
  const failure = error as { status?: number, statusCode?: number }
  const status = Number(failure.statusCode ?? failure.status)
  return status === 403
}

export default defineNuxtRouteMiddleware(async () => {
  const { fetchMe, registrationAvailable, user } = useAuth()
  if (user.value) return
  try {
    await fetchMe()
  } catch (error) {
    if (unauthenticated(error)) {
      // Base sem usuários/contas: primeiro acesso vai direto ao onboarding
      // (criação do primeiro usuário) em vez de passar pelo login.
      const onboarding = await registrationAvailable().catch(() => false)
      return navigateTo(onboarding ? '/onboarding' : '/login')
    }
    if (forbidden(error)) {
      const toast = useToast()
      toast.add({ title: 'Acesso negado', description: 'Você não tem permissão para acessar esta área.', color: 'error' })
      return navigateTo('/')
    }
    throw error
  }
})
