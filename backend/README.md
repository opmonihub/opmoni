# opmoni — backend

API Laravel 13 (PHP `^8.3`). Esqueleto inicial: model `User` + migrations padrão.

```bash
composer setup   # install + .env + key + migrate
composer test    # suite (sqlite :memory:, sem docker)
vendor/bin/pint --dirty --format agent  # estilo após editar PHP
```

Fora do Docker, sirva a API com `php artisan serve` (a UI é o Nuxt em `../frontend`).

Regras do agente: ver `AGENTS.md` (raiz) e `backend/AGENTS.md`.
Banco principal: postgres do `docker-compose.yml` (`opmoni/opmoni` em `:5432`); `.env`/`.env.example` usam `pgsql` + `redis`. Testes usam sqlite `:memory:` (só para testar).
