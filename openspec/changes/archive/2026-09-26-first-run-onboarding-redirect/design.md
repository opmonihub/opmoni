## Context

Hoje o middleware `auth` (`frontend/app/middleware/auth.ts`) chama `fetchMe()` e, no 401/419, redireciona para `/login` já no SSR. O pulo para o `/onboarding` em base vazia acontece depois, client-side, no `onMounted` de `login.vue` — daí o flash e a dupla ida-e-volta. A peça que decide "base vazia" já existe: `registrationAvailable()` em `useAuth.ts`, que chama `GET /api/registration-status` (público, `throttle:30,1`, `no-store`; responde `registration_available = sem usuários E sem contas`). O plugin de API (`frontend/app/plugins/api.ts`) já ignora 401 de `/me` para não redirecionar checagens de sessão de visitante. Em produção o nginx manda `/` para o Nuxt e o SSR fala com a API via `NUXT_API_URL=http://nginx` — a decisão de rota é 100% da app.

## Goals / Non-Goals

**Goals:**
- Primeira resposta (SSR) já redirecionar para `/onboarding` quando a base está vazia, em qualquer rota protegida
- Fallback seguro: falha na checagem de status preserva o destino atual (`/login`)
- Comportamento verificável por teste automatizado no padrão `node:test` do frontend

**Non-Goals:**
- Sem mudanças no backend (endpoint e semântica já existem e estão testados em `AuthTest`)
- Sem mudanças de infra (`docker-stack.prod.yml`, `docker/nginx/*.conf` intocados)
- Sem tocar nos guards de `login.vue`/`onboarding.vue` (acesso direto ao `/login` com base vazia mantém o bounce client-side atual — decisão do usuário)
- Sem cache do resultado de `/registration-status`

## Decisions

### Decisão 1: a checagem mora no middleware `auth`, não na página de login nem no backend
- **Escolhido:** no branch `unauthenticated(error)` do middleware `auth`, chamar `registrationAvailable()` e decidir entre `/onboarding` e `/login`.
- **Por quê:** o middleware roda no SSR e cobre toda rota protegida num ponto único; a primeira resposta já vem redirecionada. O backend não participa do roteamento do `/` (o nginx manda para o Nuxt), e o modelo atual "login decide no cliente" é exatamente o que causa o flash.
- **Alternativas:** mover a checagem para o `onResponseError` do plugin de API (rejeitada: o plugin é por-request e sem contexto de rota, e o 401 de `/me` de páginas públicas precisa continuar ignorado); redirect no backend (rejeitada: o Laravel nunca vê o `/`).

### Decisão 2: fallback em erro é `/login`
- **Escolhido:** `registrationAvailable().catch(() => false)` — falha da checagem leva ao login, comportamento de hoje.
- **Por quê:** fail-safe conservador: API fora do ar não deve transformar o app em "onboarding para todo mundo".

### Decisão 3: sem cache do status de registro
- **Escolhido:** consultar o endpoint a cada navegação não autenticada em rota protegida.
- **Por quê:** a chamada só acontece no ramo 401/419 (visitante sem sessão), mesma frequência que o `onMounted` do login dispara hoje; endpoint público com throttle `30/min`. Cachear `true` introduz risco de mandar para o onboarding alguém que chegou depois do primeiro registro.
- **Alternativas:** `useState` com TTL (rejeitada: complexidade e staleness por ganho irrelevante).

### Decisão 4: teste espelha `apiPlugin.test.ts`
- **Escolhido:** novo `frontend/tests/authMiddleware.test.ts` com `node:test`, mockando globais (`defineNuxtRouteMiddleware`, `useAuth`, `useToast`, `navigateTo`) antes do import dinâmico do middleware.
- **Por quê:** é o padrão estabelecido dos testes irmãos (sem framework extra); cobre os quatro cenários da spec + o 403 existente.

## Risks / Trade-offs

- [Chamada extra a `/api/registration-status` por navegação não autenticada em rota protegida] → endpoint público, throttled `30,1`, sem custo para autenticados; frequência igual à que o login já gera hoje
- [Race: primeiro usuário registra entre o redirect e a chegada ao `/onboarding`] → guard existente do `onboarding.vue` devolve para `/login` e o `POST /register` responde 403 (já cobertos)
- [Crawler consumir o throttle e o fallback virar `/login`] → mesmo comportamento de hoje; sem regressão

## Migration Plan

- Deploy é o ciclo normal do frontend: rebuild da imagem (`opmoni/frontend:prod`) + `docker stack deploy` (ação manual, mediante autorização explícita, conforme AGENTS.md); rollback = rebuild do commit anterior. Sem migrations, sem variável de ambiente nova.
