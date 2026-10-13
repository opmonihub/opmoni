import type { FetchOptions, ResponseType } from 'ofetch'

export default defineNuxtPlugin((nuxtApp) => {
  const { api } = useAccessApi()
  const sessionGeneration = useState('access-session-generation', () => 0)
  async function businessApi<T, R extends ResponseType = ResponseType>(path: string, options: FetchOptions<R> = {}): Promise<T> {
    const version = sessionGeneration.value
    try {
      return await api<T, R>(path, options)
    } catch (error) {
      const status = (error as { statusCode?: number }).statusCode
      if (status === 401 && version === sessionGeneration.value) {
        await nuxtApp.runWithContext(async () => {
          useAuth().invalidateSession()
          await navigateTo('/login')
        })
      }
      throw error
    }
  }
  return { provide: { api: businessApi } }
})
