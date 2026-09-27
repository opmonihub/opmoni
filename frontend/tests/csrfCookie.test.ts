import assert from 'node:assert/strict'
import { it } from 'node:test'
import { useAuth } from '../app/composables/useAuth.ts'

it('refreshes the CSRF cookie before sending an authenticated write', async () => {
  const events: string[] = []

  Object.assign(globalThis, {
    apiOrigin: () => 'http://localhost:8000',
    useState: (_key: string, initialize: () => unknown) => ({ value: initialize() }),
    computed: (evaluate: () => unknown) => ({ get value() { return evaluate() } }),
    useRuntimeConfig: () => ({ public: { apiUrl: 'http://localhost:8000' } }),
    refreshCookie: (name: string) => events.push(`refresh:${name}`),
    $fetch: async () => {
      events.push('csrf')
    },
    useNuxtApp: () => ({
      $api: async (path: string) => {
        events.push(`api:${path}`)

        if (path === '/me') {
          return {
            id: 1,
            name: 'Operador',
            email: 'operador@example.com',
            is_super_admin: false,
            accounts: [],
            current_account: null
          }
        }
      }
    })
  })

  const { login } = useAuth()
  await login('operador@example.com', 'secret-password')

  assert.deepEqual(events, [
    'csrf',
    'refresh:XSRF-TOKEN',
    'api:/login',
    'api:/me'
  ])
})
