import { accessDestination, focusAccessDestination } from '#shared/utils/access'

export default defineNuxtPlugin(() => {
  const { user, refresh } = useAuth()
  const router = useRouter()
  const toast = useToast()
  let pending = false
  window.addEventListener('focus', async () => {
    if (pending || !user.value)
      return
    pending = true
    const previous = user.value.current_account?.id
    try {
      await refresh()
      if (!user.value) {
        window.location.assign('/login')
        return
      }
      if (previous !== user.value.current_account?.id) {
        window.location.assign(accessDestination(user.value))
        return
      }
      const target = focusAccessDestination(user.value, router.currentRoute.value.path)
      if (target)
        await router.replace(target)
    } catch {
      toast.add({ title: 'Não foi possível atualizar seu acesso.', description: 'Tente novamente ao retornar à janela.', color: 'error' })
    } finally {
      pending = false
    }
  })
})
