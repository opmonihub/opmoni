## Context

Ver `proposal.md` (Why). Hoje o PGDAS sincroniza por `PGDASD/CONSDECLARACAO13` (`config/integra-contador.php`, slug `declaracoes/pgdas`), com projeção em `SerproMonitoringMapper::pgdas()` e fixture gravada `pgdasd-consultar-declaracao.json`. Testes contra o provedor real ficam no grupo `serpro-trial` (`phpunit.xml`); o trial público (`SerproTrialContractTest`) cobre só regime e não o cenário do escritório com termo, e-CNPJ e procuração `00146`.

**Fixture de integração acordada (dados do usuário, não commitar segredos):**

| Papel | CNPJ |
| --- | --- |
| Contratante / credencial de plataforma | `65396736000176` |
| Escritório (Account) G A CONT | `48123272000105` |
| Cliente canário AUTO CENTER | `30288513000100` |

Tenancy: probes e jobs devem carregar `account_id` explicitamente quando rodarem fora de request HTTP (`BelongsToAccount`, `CurrentTenant` vazio em console).

## Goals / Non-Goals

**Goals:**

- Probes repetíveis em homologação (`FISCAL_ENVIRONMENT=homologacao`) que validem envelope → parse → projeção PGDAS para o AUTO CENTER.
- Loop operacional documentado (PHPUnit `--group=serpro-trial` e/ou `php artisan serpro:probe-pgdas`) para manutenção contínua.
- Sanitização e gravação opcional de novos shapes em fixtures quando divergirem da documentação.
- Registro claro de dependência de documentação/SDK SERPRO para **extrato** e **declaração detalhada** se não estiverem no payload de `CONSDECLARACAO13`.

**Non-Goals:**

- Mudanças de UI no monitoramento.
- Emissão de DAS, retificação ou qualquer operação de escrita no PGDAS-D.
- Substituir a suíte padrão `composer test` por chamadas reais (probes permanecem opt-in).
- Trial SERPRO genérico (`SERPRO_TRIAL_TOKEN`) como substituto do homologation gateway com certificado real.

## Decisions

1. **Canário fixo por constante de configuração, não por seed aleatório** — `config/integra-contador.php` (ou `config/serpro_probes.php` novo) expõe `homologation_canary_cnpj` default `30288513000100` e `homologation_account_cnpj` opcional para localizar a Account; alternativa descartada: descobrir cliente dinamicamente na carteira (frágil entre ambientes).

2. **Dois modos de execução complementares** — (a) testes Feature no grupo `serpro-trial` que assertam projeção; (b) comando Artisan que imprime relatório legível para operador. Alternativa descartada: só script shell sem passar pelo container Laravel (duplicaria envelope e auth).

3. **Gate duplo de ambiente** — probes checam `config('fiscal.environment') === 'homologacao'` e variável `SERPRO_PROBE_ENABLED=true` (ou flag `--force` explícita no comando). Mitiga acidental execução com default de produção em `config/fiscal.php`. Alternativa descartada: confiar só na URL do gateway.

4. **Escopo em camadas (Apicenter SERPRO, PGDASD)** — baseline já implementado: `Consultar` + `CONSDECLARACAO13` (índice por `anoCalendario` ou `periodoApuracao`; projeção em `SerproMonitoringMapper::pgdas()`). Probes validam isso primeiro. **Declaração/recibo (PDF):** `CONSULTIMADECREC14` (última do PA), `CONSDECREC15` (por `numeroDeclaracao`) — leitura, não persistidos hoje. **Extrato do DAS:** `CONSEXTRATO16` (`numeroDas`). **DAS emitido (PDF):** `Emitir` + `GERARDAS12` (`periodoApuracao`) — fora do escopo de escrita na v1 do change; probe pode só confirmar índice (`numeroDas`, `dasPago`) vindo de `CONSDECLARACAO13`. Procuração eCAC **00146** em todos. Links: [catálogo PGDASD](https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/solucoes/integra-sn/pgdasd/).

5. **Fixtures gravadas seguem `SerproContractFixtureTest`** — respostas novas passam por sanitização (sem base64 volumoso, sem CPF/CNPJ de terceiros além do canário acordado) e marker `_provenance: recorded`. Alternativa descartada: commitar dump integral da resposta.

6. **Skip em vez de fail para pré-requisitos** — credencial ausente, termo inválido, procuração `00146` ausente, 429/900807: `markTestSkipped` ou exit code dedicado no comando, alinhado a `SerproTrialContractTest`.

## Risks / Trade-offs

- **[Cota SERPRO / 429]** → Não rodar probes no CI padrão; documentar intervalo mínimo entre loops; tratar throttle como skip.
- **[403 bilhetado (FAQ Integra Contador)]** → Falhas de procuração/autorização podem gerar cobrança; validar `OBTERPROCURACAO41` antes do loop PGDAS e evitar retry agressivo com autor/contribuinte errados.
- **[Dados do canário mudam na Receita]** → Probe pode falhar por ausência de período no ano-calendário consultado; parametrizar `anoCalendario` e documentar competência esperada no README do probe.
- **[Divergência doc × payload real]** → Já observada em campos de data DAS (`datahoraEmissaoDas` vs `dataHoraEmissaoDas`); mapper tolera ambos; novas divergências viram fixture + ajuste mapper.
- **[Segredos em log]** → Probes usam os mesmos guardrails de `SerproException` e envelope; proibir `-vvv` com dump de `dados` em runbook.
- **[Tenancy]** → Comando deve aceitar `--account=` ou resolver Account pelo CNPJ do escritório; nunca consultar cliente de outra Account.

## Migration Plan

1. Implementar probes e comando atrás de flags; nenhuma migration de banco obrigatória.
2. Documentar no change/tasks como rodar: `FISCAL_ENVIRONMENT=homologacao SERPRO_PROBE_ENABLED=1 cd backend && php artisan serpro:probe-pgdas` e `php artisan test --compact --group=serpro-trial`.
3. Rollback: remover comando e testes; comportamento de sync/monitoramento inalterado se probes forem apenas aditivos.

## Open Questions

- ~~Quais `idServico` retornam extrato e declaração?~~ **Resolvido (Apicenter):** extrato `CONSEXTRATO16`; declaração/recibo `CONSULTIMADECREC14` / `CONSDECREC15`. Falta decidir se v1 do change só valida índice + opcional download sanitizado em probe, ou se entra persistência/API Opmoni (fora do escopo atual de monitoramento).
- Homologação devolve os mesmos períodos/DAS que produção para o AUTO CENTER? Se não, ajustar ano-calendário ou competência fixa no probe.
- Swagger “Referência da API” retornou 500 na pesquisa inicial — confirmar URL estável antes de automatizar contrato OpenAPI.
