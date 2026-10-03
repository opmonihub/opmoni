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

### Probe PGDAS (homologação, opt-in)

Loop de manutenção contra o Integra Contador em homologação — **não** entra no `composer test` padrão. Detalhes e CNPJs canário: [`openspec/changes/add-real-pgdas-das-fetch/design.md`](../openspec/changes/add-real-pgdas-das-fetch/design.md) (Runbook).

Pré-requisitos locais (não commitar): `FISCAL_ENVIRONMENT=homologacao`, credencial de plataforma, e-CNPJ e termo na Account do escritório (`48123272000105`), procuração e-CAC `00146` para o cliente AUTO CENTER (`30288513000100`) — obtenha com sync `OBTERPROCURACAO41` ou seed manual antes do probe passar elegibilidade.

```bash
php artisan config:show fiscal.environment   # deve ser homologacao
FISCAL_ENVIRONMENT=homologacao SERPRO_PROBE_ENABLED=1 php artisan serpro:probe-pgdas
FISCAL_ENVIRONMENT=homologacao SERPRO_PROBE_ENABLED=1 php artisan serpro:probe-pgdas --json
# Simulação (Http::fake) — suíte padrão com `--group=serpro-trial` incluído; sem HTTP real.
php artisan test --compact --group=serpro-trial --filter=Pgdas

# Homologação real no PHPUnit (opt-in; usa banco/.env local, não sqlite :memory: da suíte padrão):
FISCAL_ENVIRONMENT=homologacao SERPRO_PROBE_ENABLED=1 SERPRO_PROBE_REAL=1 php artisan test --compact --group=serpro-trial --filter=Pgdas
```

Sem `SERPRO_PROBE_REAL=1`, o teste trial acima só registra skip — comportamento esperado no CI.

Evite loops apertados (429 / código `900807` → skip). Use `--year=2025` se homologação não devolver períodos no ano corrente. Não use `-vvv` com dump de payload — probes não devem logar token, senha de certificado, consumer secret ou XML bruto.
