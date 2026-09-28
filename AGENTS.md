# AGENTS.md — opmoni

Monorepo sem scripts na raiz (não há `package.json` nem `composer.json` na raiz — cada pacote tem o seu). Produto: dashboard multi-tenant para escritório contábil brasileiro — carteira fiscal (CPF/CNPJ, certificado A1, procuração e-CAC, distribuição DF-e) e rotinas mensais de trabalho.

- `backend/` — Laravel 13, PHP `^8.3` (imagem `php:8.4-fpm`), Sanctum 4. Regras autoritativas em `backend/AGENTS.md` (guidelines geradas pelo Laravel Boost; o `.ai/rules` que ele manda ler **não existe** — as regras reais estão nos comentários dos arquivos e aqui).
- `frontend/` — Nuxt 4 + Vue 3 + Nuxt UI, `pnpm@12.5.1` pinado. Sem i18n, sem Pinia, sem `server/api/` (a API é o Laravel).
- `docker/` — `nginx/{dev,prod}.conf` decidem o roteamento HTTP.
- `openspec/` — fluxo spec-driven: `specs/<capacidade>/spec.md` são as specs vigentes, `changes/<id>/` são propostas em andamento (`proposal.md`, `design.md`, `tasks.md` com checkbox). Skills em `.agents/skills/openspec-*`; CLI `openspec list|view|change|archive`.
- `CONTEXT.md` — glossário do domínio (**Account**, Membro, Admin, Operador, User, Painel Global, Acesso de suporte). Leia antes de nomear qualquer coisa.
- `DESIGN.md` — tokens de design do Nuxt UI. `PRODUCT.md` — o produto. `docs/superpowers/plans/` — planos de implementação com constraints globais.
- `.ref/` — referência somente-leitura (~1 GB, fora do git). Não edite, não importe às cegas.
- ⚠️ `README.md` da raiz e `frontend/README.md` estão desatualizados (falam em backend `:8000`, "esqueleto: só User", `server/api/`). Não confie neles.

## Backend (`cd backend`)

### Comandos

- Setup: `composer setup` (= `composer install` + copia `.env.example` + `key:generate` + `migrate --force` + `npm install` + `npm run build`).
- Dev: `composer dev` (= `php artisan dev`, multiplex de server+queue+vite) só **fora** do Docker. No Docker o backend é PHP-FPM e a fila é o serviço `queue` — não use `php artisan serve`.
- Teste: `php artisan test --compact --filter=NomeDoTeste` ou `vendor/bin/phpunit <path>`. Suite: `composer test` (faz `config:clear` antes).
- Estilo: `vendor/bin/pint --dirty --format agent` depois de editar PHP. Nunca `--test`.
- Geradores: `php artisan make:* --no-interaction`.
- Dados de dev: `php artisan dev:seed-clients` (`--account=`, `--count=`) e `php artisan dev:seed-work` (`--account=`, `--month=Y-m`) — carteira sintética com A1/e-CAC/tags e o mês de trabalho. **Não têm guarda de ambiente**; ambos abortam se não houver conta.

### Tenancy — leia antes de tocar em qualquer model/query

Não há banco nem conexão por tenant. Tudo é **uma coluna `account_id`** + a trait `app/Concerns/BelongsToAccount.php`, que:

- registra um global scope `account` **condicional** — se `resolve(CurrentTenant::class)->accountId` for `null` (console, queue, seed), o scope **não filtra nada**;
- preenche `account_id` no `creating` a partir de `CurrentTenant` ou do usuário sanctum;
- sobrescreve `resolveRouteBinding()` para restricted binding por conta (id alheio → 404), com fallback no usuário da sessão porque o binding roda **antes** do middleware `tenant`.

`app/Tenant/CurrentTenant.php` é um singleton mutável de uma propriedade. É setado pelo middleware `tenant` (alias em `bootstrap/app.php`, lê `users.current_account_id`) e **nunca é resetado** — no `queue:work` (long-lived, `--max-time=3600`) o valor do job anterior sobrevive. Por isso o código fiscal seta `account_id` explicitamente ao escrever.

Modelos que **não** usam a trait: `Account`, `AccountUser`, `Plan`, `Subscription`, `SerproConnection`, `SupportAccessLog`, `User`. Model novo que pertence a uma conta deve usar.

### Papéis

`account_user.role` é string sem enum: `admin`, `operador`, `user`. As policies só ramificam `['admin','operador']` para escrita e `!== null` para leitura — **`user` é read-only na prática e não aparece em nenhuma policy** (só na validação `in:admin,operador,user` do `AccountMemberController`).

`super_admin` **não é um papel**: é `users.is_super_admin` (bool), com middleware homônimo e painel em `/admin`. `App\Policies\Concerns\HasTenantRole::tenantRole()` devolve `'admin'` para super_admin — ou seja, **em modo suporte o super_admin tem poder total de escrita na conta alheia**, e cada write passa por `SupportAudit::logWrite()`.

⚠️ `AuthController::register()` marca o primeiro usuário registrado como `is_super_admin = true` (o registro só fica disponível enquanto `User` e `Account` estão vazios).

Limites de plano: `App\Services\PlanLimits::assertCanCreate($account, 'users'|'clients'|'monitorings')` — chave ausente = ilimitado.

### Fiscal / DF-e (`app/Services/Fiscal/`)

Pipeline: `fiscal:capture` (horário, `withoutOverlapping`) → `CaptureFiscalDocumentsJob` (`tries=1`, `timeout=85`) → `FiscalCaptureService` (lock `Cache::lock("fiscal:capture:{clientId}:{source}", 180)`) → `NfeDistributionConnector` (SOAP, **mTLS com o A1 do cliente**) → `FiscalDocumentWriter` (**único caminho de escrita** — o conector nunca escreve no banco).

Invariantes verificados por teste — não quebre:

1. **Nenhuma assinatura XMLDSig.** O serviço não assina e o XSD rejeita assinatura injetada com `cStat 215`. Autenticação é o A1 no transporte.
2. **A posição nunca é incrementada localmente.** `ultNSU` novo é sempre o valor devolvido pelo serviço.
3. **Nada é manifestado.** O conector consulta e lê; `210200` nunca é enviado.
4. **Rejeição é estado, não exceção** (`137`, `656`…): volta como `PullResult` com `blockedUntil`; `FiscalException` é só "sem resposta nenhuma". `FiscalSkipReason` separa `blocked` (fisco) de `locked` (outro job) — juntá-los mente para o operador.
5. `FiscalCursor` (único por `(client_id, source)`) dá idempotência: o lote é gravado **antes** de avançar a posição. `last_run_at` é escrito **antes** da chamada e `last_seen_at` **depois** — essa assimetria é o que `historyIsInterrupted()` detecta.
6. **Nunca logar** senha do certificado, XML bruto ou `docZip`; `last_error` leva só `class_basename` + frase fixa (200 chars).
7. TLS verificado com bundle ICP-Brasil vendorizado em `backend/resources/icp-brasil/ca-bundle.crt` (`.crt` de propósito — o `.gitignore` esconde `*.pem`).
8. Layout `1.01` pinado; `config('fiscal.environment')` tem default **`producao`** e **nenhuma chave `FISCAL_*` aparece no `.env.example`** — quem não setar `FISCAL_ENVIRONMENT=homologacao` em dev aponta para o SEFAZ de produção.
9. `CNPJ_WS_TOKEN` está no `.env.example` mas **nenhum código lê** — `CnpjWsLookup` usa o tier público sem auth, cache 24h e rate 3/min por conta.

### SERPRO / Integra Contador — atenção

A camada de transporte **existe** (`SerproClient`, `SerproTokenProvider`, `SerproEnvelope`, `SerproRequestTag`, `SerproCertificateMaterializer`, `SerproFailure`, `SerproConnection`, `config/integra-contador.php`) e tem testes, mas **nenhum controller, rota ou job a chama** — `SerproClient` só é alcançável de teste.

`frontend/app/composables/useSerpro.ts` chama **10 endpoints `/serpro/*` que não existem no backend**, e `SerproMonitoring` é CRUD placeholder (só `name`). Isso é o change `add-integra-contador-sync` em andamento (21 de 71 tasks), não bug — **não "conserte" o frontend para batidar com o backend**.

Detalhe que não se deduz do código: abrir a mensagem de um contribuinte é ato jurídico (ciência da intimação — D19 no `design.md`). Por isso `readMessage()` é função separada de `listObligation()` no composable. **Não una as duas.**

### Testes

- `phpunit.xml` força `sqlite :memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`. Nenhum teste precisa de Redis, NATS ou rede. **Nunca aponte isso para um banco com dado.**
- `RefreshDatabase` é **opt-in por classe** (`tests/TestCase.php` não o traz). 63 arquivos, 489 métodos.
- Nomes de teste: `test_` + snake_case em português com vocabulário de domínio (`test_dispatches_one_job_per_capturable_client`). Crie com `php artisan make:test --phpunit Nome` (sem `Feature/` no nome).
- Grupo `serpro-trial` está **excluído** por default — `SerproTrialContractTest` só roda com `SERPRO_TRIAL_TOKEN` e trata 429 como skip.
- `phpunit.pgsql-scratch.xml` é a exceção: roda `FiscalDocumentWriterPostgresTest` contra um Postgres descartável e faz `migrate:fresh` — **apaga o banco nomeado**. Crie `fiscal_writer_scratch` antes. Existe porque sqlite não aplica `varchar(28)` e um `digVal` de 32 chars passava na suite e rebentava em produção.
- `ClientCertificateVaultLegacyPfxTest` gera o fixture RC2 com `openssl -legacy` em runtime e se auto-pula se o ambiente não reproduzir. O `.pfx` não é versionado porque o `.gitignore` esconde `*.pfx`.
- A1: upload valida `max:2048` (2 MiB) e só `pfx|p12`; cifragem é **encrypt-then-base64** (`Crypt::encryptString(base64_encode(...))`) — inverter devolve `false` e o certificado some em silêncio. Senha errada vs. PFX RC2 legacy é distinguido pela fila de erro do OpenSSL.
- `NATS_URL` está no compose e no stack e o serviço roda com JetStream e healthcheck, mas **nenhuma linha do backend referencia NATS**. Não assuma que existe pub/sub.

## Frontend (`cd frontend`)

- `corepack enable` uma vez; depois `pnpm install` (nunca `npm install` aqui — o backend usa `npm`, o frontend usa `pnpm`).
- Dev `pnpm dev` (:3000) · build `pnpm build` · preview `pnpm preview` · lint `pnpm lint` · types `pnpm typecheck` · **`pnpm test`**.
- A imagem prod roda `node .output/server/index.mjs`, **não** `nuxt preview` — `nuxt preview --host/--port` não existe no Nuxt 4 (o flag vira rootdir e o preview morre com "Cannot find nitro.json").
- Entradas reais: `app/app.vue`, `app/pages/`, `app/layouts/`, `app/plugins/`, `app/middleware/`, `app/composables/`, `app/utils/`, `app/types/`, `nuxt.config.ts`. `srcDir` não é configurado (default Nuxt 4 = `app/`).

### API — um plugin só

`app/plugins/api.ts` é o **único** plugin. Cria `$api` via `$fetch.create` com `baseURL = ${apiOrigin()}/api` — **todo call site passa o path sem `/api`** (`$api('/clients')`). Ele também:

- lê o cookie `XSRF-TOKEN` **uma vez no setup** (ref reativa leria fora do contexto Nuxt) e manda `X-XSRF-TOKEN` com `credentials: 'include'`;
- no SSR repassa o `cookie` da request e força `origin`/`referer` do `siteUrl` (Sanctum stateful domain);
- no `onResponseError` redireciona para `/login` em 401/419, **exceto** 401 em `/api/me` (páginas públicas sondam sessão existente). **403 não redireciona** — Gate/tenant é caso da página. O wrap em `nuxtApp.runWithContext()` é obrigatório (ofetch perde o async context do Nuxt).

`useAuth().ensureCsrf()` chama `/sanctum/csrf-cookie` com `$fetch` cru (não `$api`) para não entrar no redirect do plugin. `logout()` limpa o estado local em `finally` — sem isso um 419 entra em loop.

### Rotas e auth

Middleware é **named, nunca global**: `auth` e `super-admin` em `app/middleware/`, aplicados por `definePageMeta` em cada página. `admin/serpro.vue` **não** declara nada e depende do shell `/admin`. `inbox.vue` não tem `definePageMeta` nenhum. Ao criar página, declare o `middleware` explicitamente.

`useAuth` centraliza sessão e expõe `can()`, `canManageClients/Work/Departments` (`admin`|`operador`) e `canManageMembers` (`admin`) — cada um espelha uma policy do backend. No 403 o middleware `auth` mostra toast e vai para `/`; o `super-admin` não mostra toast, então `auth` tem que vir antes na lista.

### Testes — `node --test`, não vitest

`"test": "node --test tests/"`. **Não existe vitest, jest, @vue/test-utils, jsdom nem MSW** — `package.json` não tem nenhuma dependência de teste. 162 testes em 27 arquivos, ~13s.

O truque: os testes importam o fonte **com extensão explícita** (`from '../app/utils/workCalendar.ts'`) e o Node executa por **type-stripping nativo** (Node ≥ 22.18; CI fixa Node 22). Sem bundler, sem alias, sem build.

Consequências ao escrever teste:

- Só é importável o que tem **import de runtime com extensão e sem alias**. Por isso `app/utils/*` importa `'./x.ts'` e `'../types/y.ts'`, enquanto `app/composables/*` importa `'./x'` e `'~/types/y'` — composables com import de runtime **não são testáveis** (os puros são).
- Nuxt auto-imports são mockados como **globais no `globalThis`** antes de um `await import()` dinâmico: `Object.assign(globalThis, { defineNuxtPlugin: f => f, useCookie: () => x, navigateTo: ... })`. Trocar os mocks entre casos funciona re-importando o módulo já cacheado.
- **SFC `.vue` não é importável** (não há compilador de SFC no toolchain de teste). O único teste que renderiza Vue usa `components/work/WorkToolbarTeleport.ts`, um `.ts` com render function, e um `RendererOptions` escrito à mão.
- `apiRouting.test.ts` lê `docker/nginx/{dev,prod}.conf` com `readFile` e afirma a ordem dos `location` — mexer no roteamento quebra o teste de propósito.

## CI

`.github/` existe **só dentro de `frontend/`** e roda em `push` (não em PR): `pnpm install` → `lint` → `typecheck`. **Não roda `pnpm test`, e o backend não tem CI nenhum.** CI verde não significa teste verde — rode `pnpm test` e `composer test` você mesmo.

## Docker

- Dev: `docker compose up --build` na raiz. Única porta web é **:3000** (nginx); backend e frontend não publicam porta. Postgres/Redis/NATS em 5432/6379/4222-8222 só para ferramenta de dev.
- UI e API saem da mesma origem (`:3000` em dev, o domínio em prod), então **não há CORS** — remova `allowed_origins` de `backend/config/cors.php` se o front voltar a falar cross-origin.
- `docker/nginx/{dev,prod}.conf` decidem o roteamento: `/api/*` (exceto `/api/_nuxt_icon/*`), `/sanctum/*` e `/up` vão para o FPM (`backend:9000`), o resto para o Nuxt (`frontend:3000`). Usam `resolver 127.0.0.11` com o nome em variável, então container recriado (IP novo) não exige restart do nginx. No `proxy_pass` a variável **precisa** do esquema (`http://frontend:3000`).
- O nginx de dev **sobrescreve** `X-Forwarded-*` com `$remote_addr` — sem isso o `trustProxies(at: '*')` do Laravel deixaria o cliente forjar `X-Forwarded-For` e burlar o rate limiter de login/register/cnpj-lookup. Em produção quem repassa é o Traefik.
- O `nginx` só espera o `backend` ficar healthy: o SSR do Nuxt chama a API por `http://nginx`, então esperar o `frontend` ali seria deadlock.
- Health: backend = `/ping` do FPM (o entrypoint terminou vendor, `.env`, `APP_KEY` e migrations quando o pool sobe); nginx = GET `/up` de verdade (nginx → FPM → Laravel → banco). O `queue` não tem healthcheck; espera vendor e banco em `backend/docker/queue-entrypoint.sh`, que sonda o PDO direto porque `intl` não está na imagem e `migrate:status` quebraria.
- **Migrations em dev rodam no entrypoint** (`backend/docker/entrypoint.sh:47`, `migrate --force` a cada start) — em produção não (ver abaixo). O `queue` nunca migra.
- O entrypoint de dev realinha o `www-data` ao uid do dono de `./backend` no host, para gravar em `storage/` sem chown no host. Se o host for root (uid 0) ele **recusa** e avisa para chownar no host. Fora do Docker, o Vite do backend escuta `0.0.0.0` mas faz HMR em `localhost` (`backend/vite.config.js`).
- Upload: PHP `upload_max_filesize=4M` / `post_max_size=8M`, nginx `client_max_body_size 6m` (`backend/docker/php/*.ini` + os dois `.conf`) — a escada existe para o `max:2048` do A1 virar **422**, não 413.
- A imagem prod do backend não tem `intl` nem roda `storage:link`. `LOG_CHANNEL=stderr` porque o container do FPM roda como root e o worker como www-data, e um `laravel.log` criado pelo root não pode ser appendido pelo worker.

## Produção (Swarm + Traefik)

- `docker stack deploy -c docker-stack.prod.yml opmoni` — **sem `name:` no arquivo** (o loader do `docker stack deploy` recusa essa chave).
- Só o `nginx` entra na rede externa `traefik-public`; labels: `app.inovaicontabil.com.br`, `web`/`websecure`, `certresolver letsencrypt`, middleware `redirect-https@swarm-traefik` (o `@swarm-<stack>` é obrigatório para usar o middleware definido nas labels do serviço `traefik_traefik`).
- Imagens construídas localmente no nó (o projeto não tem registry — cluster com mais de um nó exige um): `docker build -t opmoni/backend:prod --target prod ./backend` e `docker build -t opmoni/frontend:prod --target prod ./frontend`.
- Segredos nunca no arquivo: `set -a; . /root/opmoni.prod.env; set +a` antes do deploy (`APP_KEY`, `DB_PASSWORD`). `APP_KEY` precisa ser **estável** entre deploys — certificados, senhas e tokens são cifrados com ele.
- Migrations rodam **uma vez, à mão**, fora do entrypoint e do worker: `docker service scale opmoni_migrate=1 && docker service logs -f opmoni_migrate`, depois `docker service scale opmoni_migrate=0`. O serviço faz `migrate --force` com retry (10 tentativas). O `queue` espera a tabela `migrations` existir, não só a conexão.
- O `queue` tem `stop_grace_period: 130s` porque o default de 10s mataria o `queue:work --timeout=120` no meio do job.
- `docker stack deploy` no Swarm ativo é ação manual: só com autorização explícita para alterar produção.
- Dados de produção são exclusivos do stack (volumes `prod_*`, sem porta publicada) e **não têm backup** — configure backup externo antes de guardar dado real. PFX/P12 ficam no disco efêmero do container do Laravel (sem volume) e somem ao recriar o serviço.

## Antes de dizer que terminou

- Backend: `vendor/bin/pint --dirty --format agent` e `php artisan test --compact` (ou `vendor/bin/phpunit <arquivo>`).
- Frontend: `pnpm lint && pnpm typecheck && pnpm test` — nessa ordem, e os testes são o passo que ninguém esquece. Nada disso roda sozinho no CI.
- Comentários, nomes de teste e mensagens são em **português** com o vocabulário de `CONTEXT.md`. Identificadores ficam em inglês e o domínio em pt-BR; siga o arquivo irmão.

## Git

- `.gitignore` da raiz cobre `vendor/`, `node_modules/`, `.nuxt/`, `.output/`, `.env`, `*.env`, `*.pfx`, `*.p12`, `*.pem`, `*.key`, `*.sqlite`, `.ref/` e `.worktrees/`. `.crt`/`.cer` **não** são bloqueados de propósito (o bundle ICP-Brasil é versionado).
- `backend/.env`, `.env.*` e certificados A1 nunca vão para o git.
- Não crie docs (`*.md`) sem pedido explícito. Siga convenções dos arquivos irmãos.
