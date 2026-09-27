import assert from 'node:assert/strict'
import { it } from 'node:test'

interface FetchFailure {
  status?: number
  statusCode?: number
}

interface MiddlewareMocks {
  userValue?: unknown
  fetchMeError?: FetchFailure
  registrationResult?: boolean | Error
}

const redirects: string[] = []
const toasts: Array<Record<string, string>> = []

async function runMiddleware(mocks: MiddlewareMocks): Promise<unknown> {
  redirects.length = 0
  toasts.length = 0

  // Mesmo padrão de apiPlugin.test.ts: globais do Nuxt mockados antes do
  // import dinâmico. O middleware resolve useAuth/useToast/navigateTo no
  // momento da chamada, então trocar os mocks entre casos funciona mesmo
  // com o módulo já em cache.
  Object.assign(globalThis, {
    defineNuxtRouteMiddleware: (handler: unknown) => handler,
    useAuth: () => ({
      user: { value: mocks.userValue ?? null },
      fetchMe: async (): Promise<void> => {
        if (mocks.fetchMeError) throw mocks.fetchMeError
      },
      registrationAvailable: async (): Promise<boolean> => {
        if (mocks.registrationResult instanceof Error) throw mocks.registrationResult
        return mocks.registrationResult ?? false
      }
    }),
    useToast: () => ({ add: (toast: Record<string, string>) => toasts.push(toast) }),
    navigateTo: (path: string) => {
      redirects.push(path)
    }
  })

  const middleware = (await import('../app/middleware/auth.ts')).default as unknown as () => Promise<unknown>
  return middleware()
}

it('redirects an unauthenticated visitor to onboarding when the database has no users', async () => {
  await runMiddleware({ fetchMeError: { statusCode: 401 }, registrationResult: true })
  assert.deepEqual(redirects, ['/onboarding'])
})

it('redirects an unauthenticated visitor to login when the database already has users', async () => {
  await runMiddleware({ fetchMeError: { statusCode: 401 }, registrationResult: false })
  assert.deepEqual(redirects, ['/login'])
})

it('falls back to login when the registration status check fails', async () => {
  await runMiddleware({ fetchMeError: { statusCode: 401 }, registrationResult: new Error('status indisponível') })
  assert.deepEqual(redirects, ['/login'])
})

it('treats an expired session (419) the same as a missing one (401)', async () => {
  await runMiddleware({ fetchMeError: { status: 419 }, registrationResult: false })
  assert.deepEqual(redirects, ['/login'])
})

it('does not redirect a visitor with an active session', async () => {
  const result = await runMiddleware({ userValue: { id: 1 }, registrationResult: true })
  assert.deepEqual(redirects, [])
  assert.equal(result, undefined)
})

it('keeps the 403 path: toast and redirect home', async () => {
  await runMiddleware({ fetchMeError: { statusCode: 403 } })
  assert.deepEqual(redirects, ['/'])
  assert.equal(toasts.length, 1)
  assert.equal(toasts[0]?.title, 'Acesso negado')
})
