# AGENTS.md — opmoni

Monorepo sem scripts na raiz. Trabalhe dentro do pacote certo:

- `backend/` — Laravel 13, PHP `^8.3` (ver `backend/composer.json`). Regras autoritativas em `backend/AGENTS.md` — leia antes de mexer no backend, não duplique aqui.
- `frontend/` — Nuxt 4 + Vue 3 + Nuxt UI, gerenciador `pnpm@12.5.1` (`packageManager` pinado em `frontend/package.json`).
- Raiz — `docker-compose.yml` (dev) e `docker-stack.prod.yml` (produção no Swarm); o roteamento HTTP mora em `docker/nginx/dev.conf` e `docker/nginx/prod.conf`.
- `openspec/` — specs; skills em `.agents/skills/openspec-*`.
- `.ref/` — referência somente-leitura (template dashboard + chatwoot). Não edite, não importe às cegas.

## Backend (`cd backend`)

- Setup: `composer install` + `cp .env.example .env` se faltar + `php artisan key:generate` + `php artisan migrate --force`. Atalho: `composer setup`.
- Dev: `composer dev` (= `php artisan dev`, multiplex server+queue+vite) só para rodar **fora** do Docker — no Docker o backend é PHP-FPM e a fila é o serviço `queue`. Não use `php artisan serve` direto.
- Teste único: `php artisan test --compact --filter=NomeDoTeste` ou `vendor/bin/phpunit <path>`. Suite cheia: `composer test` (faz `config:clear` antes).
- Estilo PHP: `vendor/bin/pint --dirty --format agent` após editar PHP.
- Banco único: o postgres do `docker-compose.yml` (`opmoni/opmoni` em `:5432`, host `postgres` dentro do compose e `127.0.0.1` fora). `.env`/`.env.example` do backend usam `pgsql` + `redis`; nada de sqlite fora dos testes. Testes usam sqlite `:memory:` via `phpunit.xml` — só para testar, nunca como banco de dev.
- Criar arquivos via `php artisan make:* --no-interaction` (ex.: `php artisan make:test --phpunit Nome`).

## Frontend (`cd frontend`)

- Requer `corepack enable` uma vez; depois `pnpm install` (nunca `npm install` aqui — backend usa `npm`, frontend usa `pnpm`).
- Dev: `pnpm dev` (:3000). Build: `pnpm build`. Preview: `pnpm preview`.
- Lint: `pnpm lint` (`eslint .`). Types: `pnpm typecheck` (`nuxt typecheck`).
- Entradas reais: `app/app.vue`, `app/pages/`, `app/layouts/`, `app/plugins/`, `nuxt.config.ts` (não existe mais `server/api/` — a API é o Laravel). Template original em `.ref/frontend/` — só consulte.

## Docker

- Dev: `docker compose up --build` na raiz. Única porta web é **:3000** (nginx). O nginx manda `/api/*`, `/sanctum/*` e `/up` para o Laravel via FastCGI (`backend:9000`) e o resto para o Nuxt (`frontend:3000`); backend e frontend não publicam porta. Postgres/Redis/NATS continuam em 5432/6379/4222-8222 só para ferramenta de dev.
- UI e API saem da mesma origem (`:3000` em dev, o domínio em prod), então não há CORS: remova `allowed_origins` do `backend/config/cors.php` se algum dia o front voltar a falar cross-origin.
- `docker/nginx/{dev,prod}.conf` decidem o roteamento e usam `resolver 127.0.0.11` com nome em variável: container recriado (IP novo) não exige restart do nginx. No `proxy_pass` a variável **precisa** do esquema (`http://frontend:3000`).
- Health: backend = `/ping` do FPM (ou seja, o entrypoint — vendor, `.env`, `APP_KEY`, migrations — já terminou quando o pool sobe); nginx = GET `/up` de verdade (nginx → FPM → Laravel → banco). O `queue` não tem healthcheck (não expõe endpoint); espera o vendor e o banco no `backend/docker/queue-entrypoint.sh`.
- No Docker o backend roda o pool do FPM com o `www-data` realinhado ao dono de `./backend` no host (entrypoint), para gravar em `storage/` sem chown no host. Fora do Docker, Vite do backend espera `host 0.0.0.0` + `hmr host localhost` (ver `backend/vite.config.js`).
- Upload: PHP `upload_max_filesize=4M` / `post_max_size=8M` e nginx `client_max_body_size 6m` (`backend/docker/php/*.ini` + os dois `.conf`) — a validação de certificado A1 (`max:2048`) continua decidindo o que é erro de validação e o que é 413.

## Produção (Swarm + Traefik)

- `docker-stack.prod.yml` vai para `docker stack deploy -c docker-stack.prod.yml opmoni` (sem `name:` no arquivo — o loader do `docker stack deploy` recusa essa chave). Só o `nginx` entra na rede externa `traefik-public` e traz as labels do Traefik: `app.inovaicontabil.com.br`, `web`/`websecure`, certresolver `letsencrypt` e o middleware `redirect-https@swarm-traefik` (o `@swarm-<stack>` é obrigatório para usar o middleware definido nas labels do serviço `traefik_traefik`).
- Imagens construídas localmente no nó (o projeto não tem registry — cluster com mais de um nó exige um): `docker build -t opmoni/backend:prod --target prod ./backend` e `docker build -t opmoni/frontend:prod --target prod ./frontend`.
- Segredos nunca no arquivo: `set -a; . /root/opmoni.prod.env; set +a` antes do deploy (`APP_KEY`, `DB_PASSWORD`). `APP_KEY` precisa ser **estável** entre deploys (criptografia da aplicação).
- Migrations rodam **uma vez, à mão**, nunca no entrypoint nem no worker: `docker service scale opmoni_migrate=1 && docker service logs -f opmoni_migrate`, depois `docker service scale opmoni_migrate=0`.
- `docker stack deploy` no Swarm ativo é ação manual: só com autorização explícita para alterar produção.
- Dados de produção são exclusivos do stack (volumes `prod_*`, sem porta publicada) e **não têm backup**: configure backup externo antes de guardar dado real. PFX/P12 ficam no disco efêmero do container do Laravel (sem volume) e somem ao recriar o serviço.

## Git

- `.gitignore` da raiz existe e cobre `vendor/`, `node_modules/`, `.nuxt/`, `.output/` e `.env` — `backend/.env`, `.env.*` e certificados A1 nunca vão para o git.
- Não crie docs (`*.md`) sem pedido explícito. Siga convenções dos arquivos irmãos.
