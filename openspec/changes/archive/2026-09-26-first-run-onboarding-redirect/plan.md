# First-run Onboarding Redirect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o middleware `auth` do Nuxt levar um visitante não autenticado direto ao `/onboarding` (sem renderizar o login antes) quando a base não tem usuários nem contas.

**Architecture:** Mudança única no branch 401/419 do middleware `auth` do frontend: quando `fetchMe()` falha por sessão ausente, consultar `GET /api/registration-status` (já existente) e decidir entre `/onboarding` e `/login`. A decisão roda no SSR, então a primeira resposta já vem redirecionada — vale igual em dev e em produção por trás do nginx/Traefik. Sem mudanças no backend, no nginx ou no `docker-stack.prod.yml`.

**Tech Stack:** Nuxt 4 / Vue 3 (route middleware, auto-imports), node:test (Node v26 roda `.ts` nativo), pnpm 12.5.1.

**Spec:** `openspec/changes/first-run-onboarding-redirect/` — `proposal.md`, `specs/tenant/auth/spec.md` (delta com 4 cenários), `design.md`, `tasks.md`. O plano argumenta a partir da spec; executores leem ambos.

## Global Constraints

- Comandos do frontend rodam em `frontend/` e o gerenciador é `pnpm` (nunca `npm`).
- Testes do frontend: `node --test tests/<arquivo>` (não existe script `test` no `package.json`).
- A suíte tem 2 falhas PRÉ-EXISTENTES, não relacionadas a este change. O gate é "nenhuma falha nova", não "suíte verde":
  - `tests/csrfCookie.test.ts` — `ReferenceError: apiOrigin is not defined`
  - `tests/deadlinePresentation.test.ts` — `ERR_MODULE_NOT_FOUND: Cannot find package '~'`
  - Baseline no momento deste plano: `tests 70 / pass 68 / fail 2`.
- Estilo de código: aspas simples, sem ponto-e-vírgula, indentação de 2 espaços — espelhar `tests/apiPlugin.test.ts` e `app/middleware/auth.ts`.
- Sem novas dependências; sem mudanças no backend (logo sem `pint`/PHPUnit); sem mudanças de infra.
- NÃO mexer nos guards `onMounted` de `login.vue`/`onboarding.vue` (decisão registrada no design) nem no plugin `app/plugins/api.ts`.

## File Structure

- Create: `frontend/tests/authMiddleware.test.ts` — teste `node:test` do middleware com os globais do Nuxt mockados em `globalThis` (mesmo padrão de `tests/apiPlugin.test.ts`)
- Modify: `frontend/app/middleware/auth.ts:15-28` — o bloco `defineNuxtRouteMiddleware` ganha a consulta de `registrationAvailable` no branch de sessão ausente

Por que só isso: a decisão "base vazia" já existe no backend (`GET /api/registration-status` responde `registration_available` = sem usuários E sem contas, público, `throttle:30,1`) e no composable (`useAuth().registrationAvailable()` em `app/composables/useAuth.ts:78-82`). Falta apenas o middleware usá-la no lugar certo.

---

### Task 1: Middleware auth redireciona first-run para onboarding (ciclo TDD completo)

**Files:**
- Create: `frontend/tests/authMiddleware.test.ts`
- Modify: `frontend/app/middleware/auth.ts:15-28`

**Interfaces:**
- Consumes: `useAuth()` (já existe em `app/composables/useAuth.ts`) → `{ fetchMe(): Promise<MeResponse>, registrationAvailable(): Promise<boolean>, user: Ref<AuthUser | null> }`; globais Nuxt `defineNuxtRouteMiddleware`, `useToast`, `navigateTo` (auto-imports — no teste são mockados em `globalThis` antes do import dinâmico)
- Produces: comportamento do middleware `auth` — nenhum consumidor novo; as rotas protegidas continuam declarando `definePageMeta({ middleware: 'auth' })` como hoje

- [ ] **Step 1: Escrever o teste que falha**

Criar `frontend/tests/authMiddleware.test.ts` com este conteúdo exato:

```ts
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
```

- [ ] **Step 2: Rodar o teste e conferir que falha**

Run (a partir de `frontend/`): `node --test tests/authMiddleware.test.ts`
Expected: `pass 5 / fail 1` — falha APENAS o caso "database has no users", com `AssertionError` mostrando `actual: [ '/login' ]` vs `expected: [ '/onboarding' ]` (o middleware atual manda tudo para `/login` sem consultar o status de registro)

- [ ] **Step 3: Implementar a mudança no middleware**

Em `frontend/app/middleware/auth.ts`, o bloco atual é:

```ts
export default defineNuxtRouteMiddleware(async () => {
  const { fetchMe, user } = useAuth()
  if (user.value) return
  try {
    await fetchMe()
  } catch (error) {
    if (unauthenticated(error)) return navigateTo('/login')
    if (forbidden(error)) {
      const toast = useToast()
      toast.add({ title: 'Acesso negado', description: 'Você não tem permissão para acessar esta área.', color: 'error' })
      return navigateTo('/')
    }
    throw error
  }
})
```

Substituir por (mudam a linha da desestruturação e o branch `unauthenticated`; `forbidden` e `throw` ficam intocados):

```ts
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
```

- [ ] **Step 4: Rodar o teste e conferir que passa**

Run (a partir de `frontend/`): `node --test tests/authMiddleware.test.ts`
Expected: `pass 6 / fail 0`

- [ ] **Step 5: Gate da suíte cheia — nenhuma falha nova**

Run (a partir de `frontend/`): `node --test tests/`
Expected: `tests 76 / pass 74 / fail 2` — exatamente as duas falhas pré-existentes listadas nas Global Constraints (`csrfCookie.test.ts` e `deadlinePresentation.test.ts`); qualquer terceira falha é regressão deste change

- [ ] **Step 6: Lint e tipos**

Run (a partir de `frontend/`): `pnpm lint` e depois `pnpm typecheck`
Expected: ambos completam sem erro

- [ ] **Step 7: Commit**

```bash
git add app/middleware/auth.ts tests/authMiddleware.test.ts
git commit -m "feat(auth): redirect first-run visitors straight to onboarding"
```

**Verificação manual opcional** (só se o usuário pedir — destrutiva para o banco de DEV): `cd backend && php artisan migrate:fresh --force` zera o postgres de dev; com o ambiente de dev no ar (`docker compose up` na raiz, UI em `http://localhost:3000`), abrir `/` deve cair direto em `/onboarding`, sem a tela de login piscar.
