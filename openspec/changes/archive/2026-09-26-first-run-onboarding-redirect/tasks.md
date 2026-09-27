## 1. Testes do middleware (node:test)

- [x] 1.1 Criar `frontend/tests/authMiddleware.test.ts` no padrão de `apiPlugin.test.ts` (globais mockadas via `Object.assign(globalThis, ...)`: `defineNuxtRouteMiddleware`, `useAuth`, `useToast`, `navigateTo`), cobrindo: 401 de `/me` + `registration_available: true` → `/onboarding`; 401 + `false` → `/login`; 401 + falha do `/registration-status` → `/login`; usuário já em estado → sem redirect; 403 → toast de acesso negado + `/`. Verificar com `node --test tests/authMiddleware.test.ts` que os casos novos falham antes da implementação (TDD)

## 2. Implementação no middleware auth

- [x] 2.1 Em `frontend/app/middleware/auth.ts`, desestruturar `registrationAvailable` do `useAuth()` e, no branch `unauthenticated(error)` (401/419), consultar `registrationAvailable().catch(() => false)` e redirecionar para `/onboarding` quando resolver `true`, mantendo `/login` caso contrário; ramos de 403 e `throw error` intocados. Verificar com `node --test tests/authMiddleware.test.ts` passando

## 3. Verificação final

- [x] 3.1 Rodar a suíte completa (`node --test tests/`), `pnpm lint` e `pnpm typecheck` no `frontend/` — tudo verde; backend intocado, sem `pint`/testes PHP necessários
- [ ] 3.2 Verificação manual opcional (só em dev e se solicitado): com a base sem usuários, abrir `http://localhost:3000/` e conferir que a primeira resposta já cai em `/onboarding`, sem renderizar o login
