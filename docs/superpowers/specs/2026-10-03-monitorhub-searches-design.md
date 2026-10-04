# Buscas sob demanda + varredura agendada (modelo MonitorHub) — design

Data: 2026-10-03 · Status: aprovado pelo usuário em diálogo (brainstorming)

## Contexto

O monitoramento SERPRO (Integra Contador) tinha um único mecanismo de consumo: a
execução de sincronização varria a carteira inteira (`SerproSyncRun` → um item por
cliente PJ → todas as obrigações sincronizáveis), com histórico na tela
`/monitoring/execucoes`. O modelo de negócio desejado é o do HubStrom MonitorHub:
busca automática agendada por documento, mais busca manual sob demanda com cota
mensal, sem tela de execuções.

## Regras de negócio

- **Cota manual**: 10 buscas/mês por `conta × cliente × documento`. Mês calendário.
  Cada documento tem cota própria (10 de PGDAS do cliente X não tocam a cota de
  DCTFWeb do cliente X). A cota conta o **pedido**, não o desfecho: busca que falhou
  no provedor consumiu a chamada.
- **Agendada**: por conta, **cada documento tem o seu dia do mês** (1–28); documento
  sem dia não tem busca automática. A agendada não consome a cota manual.

## Backend

1. **`serpro_manual_searches`** — `account_id`, `client_id`, `obligation`, `state`
   (queued/running/completed/failed), `mode` (full/slip_status), `recalculate_date`
   (nullable), `reason`, `requested_by`. É a unidade de cota e de auditoria.
2. **`POST /serpro/monitoring/obligations/{obligation}/clients/search`** — body
   `{client_ids, mode?, recalculate_date?}`; gate do mesmo papel de associar
   (`admin`/`operador`); estouro de cota → `422` com detalhe por cliente; cria um
   registro por cliente e despacha `RunSerproManualSearchJob`.
3. **`RunSerproManualSearchJob`** — reusa a camada de leitura de
   `SyncSerproClientJob`, restrita a UMA obrigação; grava em `serpro_monitorings` +
   `serpro_calls`; não chama o oráculo (elegibilidade lê a outorga observada).
4. **`GET .../search-quota`** — `[{client_id, used, limit}]` do mês; cliente sem
   pedido entra com `used = 0`.
5. **Reader** — busca manual em `queued|running` aparece como "Processando" na
   planilha, mesma regra dos itens de run ativa.
6. **Agenda por documento** — `serpro_obligation_schedules` (unique conta+obrigação);
   `SerproSyncRun.obligations` (JSON, nullable = todas) como escopo da run, aplicado
   no `SyncSerproClientJob`; `serpro:scheduled-run` diário dispara, por conta
   habilitada, uma run com os documentos do dia, `trigger = scheduled`.
7. **`GET/PUT /serpro/obligation-schedules`** — leitura de qualquer membro; escrita
   `admin`/`operador`; wire em **lista** de `{obligation, day}`; `replace` apaga o que
   ficou de fora.
8. A cota por conta antes implementada em `SerproRunStarter` foi revertida; o
   `trigger` na run ficou (distingue a agendada).

### Limitações do provedor (registradas em código)

- `CONSDECLARACAO13` e `CONSULTAROPCAOREGIME103` aceitam apenas `anoCalendario`;
  `MSGCONTRIBUINTE61` só paginação. **Não há recorte por data no provedor**: a busca
  sai completa, e `recalculate_date` fica como metadado de auditoria. O modo
  `slip_status` também não tem filtro próprio no gateway — é metadado do pedido.
- Documentos agendados para o **mesmo dia** saem numa run única com `obligations`
  em lista (uma run por documento encontraria a guarda de uma-execução-por-conta).

## Frontend

- **Removidos** `pages/monitoring/execucoes/index.vue` e `[id].vue`; links, tabs,
  sidebar e a seção "Integração" do Painel ajustados (sobra "Painel" + obrigações).
- **`MonitoringSheet.vue`** — seleção por checkbox, ação em massa **"Buscar
  documentos"** (visível a `canManageClients` em obrigação servida).
- **`ManualSearchModal.vue`** — empresas da obrigação com busca por nome/CNPJ,
  seleção em massa e barra **"X de 10"** por cliente (cor por saldo; em-dash quando a
  cota não chegou); data de recálculo opcional e modo; `422` lista os clientes que
  estouraram; confirmar fecha o modal e atualiza a planilha (linhas em "Processando").
- **Settings → certificado/SERPRO** — card "Agendamentos do monitoramento": uma linha
  por documento servido, input "Dia do mês" (1–28; vazio = sem agendamento), recusa
  nomeada por documento inválido, salvamento por conta.

## Verificação

- Backend: testes de cota (422 no 11º do par, independência por documento, mês vira),
  massa, endpoint de cota, reader "Processando", escopo de run, comando por dia;
  suíte completa verde.
- Frontend: `pnpm test` verde, `pnpm lint` e `pnpm typecheck` limpos.
