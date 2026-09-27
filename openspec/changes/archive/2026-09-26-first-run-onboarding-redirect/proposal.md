## Why

Num primeiro deploy (base sem usuários), quem abre qualquer rota protegida cai na tela de login e só depois de hidratar a página o `onMounted` consulta `/api/registration-status` e pula para o onboarding — flash de tela errada, ida-e-volta dupla e primeira impressão confusa. A instalação inicial deve levar direto ao assistente de criação do primeiro usuário.

## What Changes

- O middleware `auth` do Nuxt, ao detectar visitante não autenticado (401/419 do `/me`), consulta `/api/registration-status` e redireciona direto para `/onboarding` quando a base não tem usuários nem contas; caso contrário, segue para `/login` como hoje.
- A decisão passa a acontecer já no SSR: a primeira resposta chega redirecionada, sem renderizar o login antes (vale igual em dev e em produção por trás do nginx/Traefik).
- Fallback preservado: se a checagem de status falhar, o destino continua sendo `/login`.
- Guards existentes mantidos: acesso direto ao `/login` com base vazia continua pulando para o onboarding (`onMounted` atual); `/onboarding` com base populada continua voltando para `/login`; 403 do middleware segue tratado como hoje.

## Capabilities

### New Capabilities
- (nenhuma)

### Modified Capabilities
- `tenant/auth`: novo requisito de roteamento — visitante não autenticado em base vazia (first-run) é levado direto ao onboarding pelo middleware `auth`, com fallback seguro para `/login`

## Impact

- Frontend: `frontend/app/middleware/auth.ts` (única mudança de código); novo teste `frontend/tests/authMiddleware.test.ts` no estilo `node:test` dos irmãos (ex.: `apiPlugin.test.ts`)
- Backend: nenhum — `GET /api/registration-status` (público, `throttle:30,1`, `no-store`) e a semântica de `registration_available = sem usuários E sem contas` já existem e estão cobertos por `AuthTest`
- Infra: nenhuma mudança — `docker-stack.prod.yml` e `docker/nginx/prod.conf` intocados (o `/` já vai ao Nuxt; o SSR chama a API via `NUXT_API_URL=http://nginx`)
