## Why

A obrigação `declaracoes/pgdas` já sincroniza via `PGDASD/CONSDECLARACAO13`, mas a confiança operacional depende de respostas **reais** do Integra Contador em homologação — hoje cobertas sobretudo por fixtures e pelo trial genérico, sem um cliente canário fixo nem um loop que valide declaração transmitida, extrato e DAS já emitido de ponta a ponta. Sem isso, regressões no envelope, no `dados` do pedido ou na projeção (`SerproMonitoringMapper`) passam despercebidas até produção.

## What Changes

- Suíte de **probes de integração** (grupo PHPUnit `serpro-trial`, opt-in) que chama homologação com `FISCAL_ENVIRONMENT=homologacao`, credencial de plataforma e termo/procuração reais, usando **AUTO CENTER** (`30288513000100`) como fixture de integração canário dentro do escritório **G A CONT** (`48123272000105`).
- Cobertura explícita do fluxo PGDAS: consulta de declaração (`CONSDECLARACAO13`), leitura de períodos com transmissão e **DAS emitido**; onde a documentação SERPRO exigir serviços adicionais para **extrato** ou **declaração detalhada**, implementar ou documentar a dependência antes de fechar o critério de aceite.
- **Loop de validação** repetível (comando Artisan ou script documentado) para o operador de manutenção rodar após mudanças em mapper, catálogo ou transporte Serpro — sem depender de UI.
- Gravação opcional de respostas sanitizadas em `backend/tests/Fixtures/serpro/` quando novas formas forem observadas (sem XML bruto, PDF base64 completo ou segredos).
- Matriz de pré-requisitos: credencial de plataforma (`65396736000176`), e-CNPJ do escritório, procuração família `00146` para o cliente canário; falhas de configuração viram skip legível, não falso verde.

## Capabilities

### New Capabilities

- Nenhuma.

### Modified Capabilities

- `serpro-sync`: exigir que a sincronização da obrigação PGDAS permaneça idempotente e registrável quando alimentada por respostas reais de homologação, incluindo critérios de aceite verificáveis por probes.
- `monitoring`: garantir que a projeção PGDAS (períodos, guia emitida/paga, causa `sem_declaracao`) reflita o que homologação devolve para o cliente canário, alinhada ao requirement de status de guia derivado dos dados sincronizados.
- `serpro-connection`: probes reutilizam autenticação, envelope e termo existentes; documentar variáveis de ambiente e gates para não executar probes fora de homologação sem opt-in explícito.
- `client-fiscal-access`: probes assumem família `00146` válida para o canário; cenários de skip quando procuração ou termo faltam.

## Impact

**Backend** (`backend/`):
- Novos testes em `backend/tests/Feature/Serpro/` (grupo `serpro-trial`), possivelmente `SerproPgdasHomologationProbeTest` ou extensão de `SerproTrialContractTest`.
- Comando ou job de diagnóstico em `backend/app/Console/Commands/` (ex.: `serpro:probe-pgdas`) invocando `SerproGateway`, `SerproMonitoringMapper` e catálogo `config/integra-contador.php`.
- Fixtures adicionais em `backend/tests/Fixtures/serpro/` se novos shapes forem capturados.
- Documentação operacional em `backend/` ou `docs/` sobre execução do loop e limites de cota SERPRO.

**Frontend** (`frontend/`):
- Nenhuma mudança de UI prevista; validação é backend/probes.

**Provedor** (Integra Contador / SERPRO):
- Homologação com `PGDASD/CONSDECLARACAO13` e, se confirmado na documentação ou SDK, serviços complementares para extrato e/ou declaração individual.
- Dependência explícita de docs oficiais e, quando disponível, artefatos em `.ref/` ou portal SERPRO — lacunas registradas no `design.md`.
