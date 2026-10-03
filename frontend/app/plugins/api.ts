export default defineNuxtPlugin((nuxtApp) => {
  const config = useRuntimeConfig()
  const incoming = import.meta.server ? useRequestHeaders(['cookie']) : null
  // Criado no setup do plugin: .value acompanha o cookie após /sanctum/csrf-cookie
  const xsrfToken = useCookie<string | null>('XSRF-TOKEN')
  const baseURL = `${apiOrigin()}/api`

  /**
   * `response.url` is empty on some ofetch errors and relative when a request
   * never left the client. A bare `new URL()` throws a TypeError there, which
   * replaced the real HTTP error and skipped the redirect below. Unparseable
   * means "not the /api/me exemption" — the redirect still runs.
   */
  function safePathname(url: string): string {
    try {
      return new URL(url).pathname
    } catch {
      return ''
    }
  }

  const api = $fetch.create({
    baseURL,
    credentials: 'include',
    headers: { Accept: 'application/json' },
    onRequest({ options }) {
      const headers = new Headers(options.headers as HeadersInit)

      if (import.meta.server) {
        if (incoming?.cookie) headers.set('cookie', incoming.cookie)
        const site = config.public.siteUrl || 'http://localhost:3000'
        headers.set('origin', site)
        headers.set('referer', `${site}/`)
      }

      if (xsrfToken.value) {
        headers.set('X-XSRF-TOKEN', decodeURIComponent(xsrfToken.value))
      }

      options.headers = headers
    },
    async onResponseError({ response }) {
      // ofetch callbacks perdem o async context do Nuxt — wrap obrigatório.
      // Só sessão inválida/expirada: 403 de Gate/tenant fica para a página
      // (toast, empty state) — redirecionar aqui derruba fluxos legítimos.
      if (response.status !== 401 && response.status !== 419) return
      // Sessão expirada com auth.user ainda em memória: o middleware auth pula o
      // /me e o /login devolve para /, então toda chamada protegida segue em 401.
      nuxtApp.runWithContext(() => useAuth().clearAuth())
      // /me também é consultado pelas páginas públicas para detectar uma sessão existente.
      // O middleware auth já trata o 401 de /me nas páginas protegidas.
      if (response.status === 401 && safePathname(response.url) === '/api/me') return
      await nuxtApp.runWithContext(() => navigateTo('/login'))
    }
  })

  return { provide: { api } }
})
