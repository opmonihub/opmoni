# Repository Guidelines

## Idioma

O usuário fala português do Brasil. Toda comunicação com ele fica em pt-BR: respostas, perguntas, notas de progresso, resumos e descrições de PR. Isso vale mesmo quando a pergunta chega em outro idioma ou com erros de digitação.

## Estrutura do projeto

Monorepo sem scripts na raiz. Cada pacote tem os seus.

- `backend/`: Laravel 13 e PHP `^8.3` (imagem `php:8.4-fpm`), com Sanctum. Código em `app/` (serviços por domínio em `app/Services/`, como `Fiscal/`), testes em `tests/Feature` e `tests/Unit`. As regras do Laravel Boost ficam em `backend/AGENTS.md`. O diretório `.ai/rules` que ele cita não existe.
- `frontend/`: Nuxt 4, Vue 3 e Nuxt UI, com `pnpm@12.5.1`. Código em `app/` (`pages/`, `components/`, `composables/`, `utils/`, `types/`), testes em `tests/`.
- `docker/nginx/{dev,prod}.conf`: roteamento HTTP. `/api`, `/sanctum` e `/up` vão para o FPM, o resto vai para o Nuxt.
- `openspec/`: specs vigentes em `specs/`, changes em andamento em `changes/<id>/`.
- `docs/superpowers/plans/`: planos de implementação.
- `CONTEXT.md` (glossário), `PRODUCT.md` e `DESIGN.md`: leia antes de nomear algo ou mexer na interface.
- `.ref/`: referência somente leitura, fora do git.

## Comandos

```bash
docker compose up --build                  # stack de dev, web em :3000
cd backend && composer test                # suíte PHPUnit (sqlite :memory:)
cd backend && php artisan test --compact --filter=Nome
cd backend && vendor/bin/pint --dirty --format agent
cd frontend && pnpm install && pnpm dev    # use pnpm, nunca npm
cd frontend && pnpm lint && pnpm typecheck && pnpm test
```

Fora do Docker, o backend sobe com `composer setup && php artisan serve`.

## Estilo e nomes

- PHP segue o Pint (preset padrão). Vue e TS seguem o ESLint do Nuxt.
- Identificadores ficam em inglês. Comentários, nomes de teste e textos de interface ficam em português, com o vocabulário do `CONTEXT.md` (Account, Membro, competência, A fazer).
- Gere arquivos com `php artisan make:* --no-interaction` e siga os arquivos irmãos.
- No frontend, reuse os tokens e componentes listados no `DESIGN.md` (`panel.ts`, `pageShell.ts`, `ErrorRetryAlert`, `useRetryableLoad`).

## Testes

- Backend: PHPUnit, com nomes `test_` + snake_case em português. `RefreshDatabase` é opt-in por classe. O grupo `serpro-trial` fica fora por padrão.
- Frontend: `node --test`, sem vitest. Os testes importam o fonte com extensão explícita (`'../app/utils/x.ts'`). Arquivos `.vue` não são importáveis nos testes.
- Um teste não deve fixar contagens que mudam com o próprio repositório.

## Commits e PRs

- Conventional Commits em português, com escopo: `feat(serpro): ...`, `fix(work): ...`, `test(guarda): ...`, `docs(plans): ...`.
- O CI só existe em `frontend/.github/` e não roda testes. Rode as duas suítes localmente antes do PR e descreva no PR o que foi verificado.

## Orquestração de agentes

Os droids do projeto ficam em `.factory/droids/`: `explorer` (leitura), `log-detective` (stack rodando, leitura), `implementer` (escrita) e `griller` (entrevista de design).

- Delegue trabalho independente, principalmente de leitura: exploração, diagnóstico e revisão. Tarefas sequenciais, com julgamento entre os passos, ficam na thread principal.
- Isole cada agente por escopo de escrita. O briefing diz quais caminhos ele pode editar (por exemplo, `backend/app/Services/Fiscal/` e `backend/tests/Feature/Fiscal/`), e ele não toca o resto. Leitura é livre.
- Nunca rode dois agentes escrevendo nos mesmos arquivos ao mesmo tempo.
- Uma feature que cruza backend e frontend não vira dois agentes em paralelo logo de cara. Primeiro se fixa o contrato (rota, payload, status HTTP, tipos em `frontend/app/types/`) na spec ou no design. Só depois cada lado vai para um `implementer` com seu escopo.
- Todo briefing traz: objetivo, contexto já levantado, escopo de escrita, o que não tocar, como validar e o formato do retorno.
- O retorno é um resumo com evidência `arquivo:linha`, separando fato de inferência e listando o que foi verificado e o que ficou de fora. Nunca a transcrição.
- A thread principal decide e fala com o usuário. O relatório do subagente é a fonte, e ninguém refaz a busca que ele já fez.
- Subagentes seguem as mesmas armadilhas abaixo (tenancy, segredos, produção).

## Armadilhas

- Tenancy é a coluna `account_id` com a trait `BelongsToAccount`. O escopo global não filtra nada quando `CurrentTenant` está vazio (console, fila, seed). Em jobs, grave o `account_id` de forma explícita.
- Papéis são strings (`admin`, `operador`, `user`). `super_admin` é `users.is_super_admin`, e o modo suporte escreve na conta alheia com auditoria.
- `config('fiscal.environment')` tem produção como padrão. Em dev, defina `FISCAL_ENVIRONMENT=homologacao`.
- Nunca registre em log a senha do certificado, o XML bruto ou tokens. Arquivos `.env`, `*.pfx`, `*.p12` e `*.pem` não vão para o git.
- O NATS sobe no compose e no stack, mas ainda nenhum código o usa. Ele está reservado para uso futuro.
- Deploy em produção (`docker stack deploy`) e migrations de produção só com autorização explícita.

## Cursor Cloud specific instructions

- Este ambiente não tem Docker. O `start` sobe Postgres 18, Redis 7 e NATS 2.11 com JetStream, mais a API, o Nuxt e o nginx.
- Postgres local: banco `opmoni`, usuário `opmoni`, senha `opmoni`, em `127.0.0.1:5432`. Redis sem senha em `127.0.0.1:6379`. NATS em `127.0.0.1:4222` (monitor `8222`).
- A entrada da aplicação é `http://127.0.0.1:3000` (nginx, mesma origem para UI e API). O Nuxt fica em `127.0.0.1:3001` e o `php artisan serve` em `127.0.0.1:8000`. Sessões tmux: `opmoni-api`, `opmoni-web`, `nats`.
- O `install` gera `backend/.env` a partir do example com `APP_URL=http://localhost:3000`. Não coloque `FISCAL_ENVIRONMENT` nesse arquivo: o PHPUnit assume o padrão `producao`, e a variável no `.env` faz falhar os testes de URL fiscal. O processo `opmoni-api` exporta `FISCAL_ENVIRONMENT=homologacao`.
- A fila não sobe sozinha. Quando precisar, `cd backend && php artisan queue:work`.
- Testes e checagens: `cd backend && composer test`; `cd frontend && pnpm test && pnpm lint && pnpm typecheck`.
- Conta local já criada: `ana.dev@example.com` / `senha-local-1`, Account "Escritorio Demo", cliente "Cliente Demo".
