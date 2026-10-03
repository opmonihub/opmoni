import assert from 'node:assert/strict'
import { it } from 'node:test'

it('captures Nuxt composables while the API plugin context is active', async () => {
  let contextActive = true
  let cookieReads = 0
  let onRequest: ((context: { options: { headers?: HeadersInit } }) => void) | undefined
  let onResponseError: ((context: { response: { status: number, url: string } }) => Promise<void>) | undefined
  const redirects: string[] = []
  let authClears = 0
  const xsrfCookie: { value: string | null } = { value: null }

  Object.assign(globalThis, {
    defineNuxtPlugin: (factory: () => unknown) => factory,
    apiOrigin: () => 'http://localhost:8000',
    navigateTo: (path: string) => { redirects.push(path) },
    useAuth: () => ({ clearAuth: () => { authClears++ } }),
    useRuntimeConfig: () => ({
      apiUrl: 'http://backend:8000',
      public: {
        apiUrl: 'http://localhost:8000',
        siteUrl: 'http://localhost:3000'
      }
    }),
    useCookie: () => {
      assert.equal(contextActive, true, 'useCookie must run during plugin setup')
      cookieReads++

      return xsrfCookie
    },
    $fetch: {
      create: (options: { onRequest: typeof onRequest, onResponseError: typeof onResponseError }) => {
        onRequest = options.onRequest
        onResponseError = options.onResponseError

        return () => undefined
      }
    }
  })

  const plugin = (await import('../app/plugins/api.ts')).default
  const result = plugin({ runWithContext: (callback: () => unknown) => callback() })

  assert.ok(result)
  assert.equal(cookieReads, 1)
  assert.ok(onRequest)
  assert.ok(onResponseError)

  contextActive = false
  xsrfCookie.value = 'csrf-token'
  const options: { headers?: HeadersInit } = {}
  onRequest({ options })

  assert.equal(new Headers(options.headers).get('X-XSRF-TOKEN'), 'csrf-token')

  await onResponseError({ response: { status: 401, url: 'http://localhost:8000/api/me' } })
  assert.deepEqual(redirects, [], 'guest session checks must not redirect away from onboarding')
  assert.equal(authClears, 1, 'a 401 on /me still drops the cached user')

  await onResponseError({ response: { status: 401, url: 'http://localhost:8000/api/clients' } })
  assert.deepEqual(redirects, ['/login'], 'protected API failures still redirect to login')
  // Sessão expirada com auth.user em memória: sem limpar, /login vê o usuário
  // e devolve para /, e toda chamada protegida segue em 401.
  assert.equal(authClears, 2, 'an expired session drops the cached user before redirecting')

  // ofetch can hand back an empty/relative url; parsing must not throw a
  // TypeError that swallows the HTTP error and skips the redirect.
  await onResponseError({ response: { status: 419, url: '' } })
  assert.deepEqual(redirects, ['/login', '/login'], 'an unparseable url still redirects')
  assert.equal(authClears, 3)

  await onResponseError({ response: { status: 500, url: '' } })
  assert.deepEqual(redirects, ['/login', '/login'], 'non-session errors never redirect')
  assert.equal(authClears, 3, 'non-session errors keep the cached user')
})
