## Why

A auditoria da UI Operate mostrou que o `DESIGN.md` e os utilitários extraídos (`pageShell.ts`, `panel.ts`, `ErrorRetryAlert`, `useRetryableLoad`) já definem o vocabulário correto, mas várias telas ainda duplicam classes inline, omitam `:ui` em tabelas ou tratam falha fatal de carga com toast em vez do contrato único de retry. Isso aumenta drift visual, acessibilidade inconsistente e custo de manutenção sem mudar comportamento de negócio.

Agora é o momento de fechar as fases 1–2 do roadmap da auditoria (tokens mecânicos + contratos de erro/vazio) antes de refatorações maiores (barra de seleção compartilhada, layouts de configuração, KPI grids).

## What Changes

- Importar `pageShell.ts` nas páginas que ainda replicam `pageScrollClass` / `pageTableClass` / `pageRecordScrollClass` inline.
- Aplicar `workTableUi` (ou equivalente documentado) em tabelas Work sem `:ui`, em especial Work › Modelos.
- Alinhar Home / vendas internas ao `panelTableUi` onde a tabela vive em painel de lista.
- Documentar no `DESIGN.md` uma matriz página → token de shell/painel/tabela (referência para novas telas).
- Padronizar falhas **fatais** de carga inicial em Carteira e Admin com `useRetryableLoad` + `ErrorRetryAlert` (sem hand-roll de `UAlert` ou toast como única recuperação).
- Registrar no `design.md` da change decisões sobre estados vazios (quando `#empty` de tabela/painel vs mensagem inline vs empty dedicado).
- **Fora de escopo nesta change (fases 3–5 da auditoria):** extrair `SelectionBar` única, unificar `SettingsSectionLayout`, grid de KPIs, troca sistemática `UCard` ↔ `UPageCard` em fichas — podem virar change separada.

Nenhuma alteração de API backend ou contrato HTTP.

## Capabilities

### New Capabilities

- `design-system-ui`: requisitos transversais de superfície Operate — uso obrigatório de tokens de shell/painel/tabela, contrato de erro fatal com retry, e diretriz de empty state documentada.

### Modified Capabilities

- Nenhuma.

## Impact

### Backend

- Nenhum.

### Frontend

- `frontend/app/utils/pageShell.ts` — consumo ampliado; remoção de cópias inline em páginas Operate (Monitoramento, Work, Clientes, Admin conforme auditoria).
- `frontend/app/utils/workTableUi.ts` (e meta agrupada onde aplicável) — Work › Modelos e demais `UTable` sem `:ui` no escopo.
- `frontend/app/components/data-table/panel.ts` — Home / componentes de vendas que listam em painel.
- `frontend/app/components/ErrorRetryAlert.vue`, `frontend/app/composables/useRetryableLoad.ts` — Carteira e listas Admin com falha fatal de carga.
- `DESIGN.md` — matriz de tokens e reforço dos Don'ts já existentes.
- Testes frontend (`node --test`) onde composables ou utilitários forem tocados; lint/typecheck nas páginas alteradas.

### Provedor

- Nenhum.
